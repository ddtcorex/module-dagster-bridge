<?php
/**
 * Copyright (c) 2026 DDTCoreX
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace DDTCoreX\DagsterBridge\Test\Unit\Model\ResourceModel;

use DDTCoreX\DagsterBridge\Model\ResourceModel\AttributeValuesQuery;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Eav\Api\Data\AttributeInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Magento\Framework\DB\Select;
use Magento\Framework\DB\Select\SelectRenderer;
use Magento\Framework\DB\SelectFactory;
use Magento\Framework\EntityManager\EntityMetadataInterface;
use Magento\Framework\EntityManager\MetadataPool;
use PHPUnit\Framework\TestCase;

/**
 * Pins the shape of the attribute value queries.
 *
 * As in the index query, the assertions read the parts of the Select instead of
 * its rendered SQL: rendering needs a live adapter only to quote identifiers.
 */
class AttributeValuesQueryTest extends TestCase
{
    /**
     * Builds the query builder over mocked metadata and a fake connection.
     *
     * @param string $linkField
     * @return AttributeValuesQuery
     */
    private function makeQuery(string $linkField): AttributeValuesQuery
    {
        $adapter = $this->createStub(Mysql::class);
        $adapter->method('quoteIdentifier')->willReturnCallback(
            static function ($identifier, $auto = false) {
                return '`' . $identifier . '`';
            }
        );
        $adapter->method('quoteInto')->willReturnCallback(
            static function ($text, $value, $type = null) {
                if (is_array($value)) {
                    $value = implode(',', array_map('strval', $value));
                }

                return str_replace('?', (string) $value, $text);
            }
        );

        $metadata = $this->createStub(EntityMetadataInterface::class);
        $metadata->method('getLinkField')->willReturn($linkField);

        $metadataPool = $this->createStub(MetadataPool::class);
        $metadataPool->method('getMetadata')->willReturn($metadata);

        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($adapter);
        $resource->method('getTableName')->willReturnCallback(
            static function ($tableName, $connectionName = null) {
                return $tableName;
            }
        );

        $selectRenderer = $this->createStub(SelectRenderer::class);
        $selectFactory = $this->createMock(SelectFactory::class);
        $selectFactory->expects($this->atLeastOnce())
            ->method('create')
            ->willReturnCallback(
                static function () use ($adapter, $selectRenderer) {
                    return new Select($adapter, $selectRenderer);
                }
            );

        return new AttributeValuesQuery($resource, $selectFactory, $metadataPool);
    }

    /**
     * Builds an attribute double.
     *
     * @param string $code
     * @param int $attributeId
     * @param string $backendType
     * @return AttributeInterface
     */
    private function attribute(string $code, int $attributeId, string $backendType): AttributeInterface
    {
        $attribute = $this->createStub(AttributeInterface::class);
        $attribute->method('getAttributeCode')->willReturn($code);
        $attribute->method('getAttributeId')->willReturn($attributeId);
        $attribute->method('getBackendType')->willReturn($backendType);

        return $attribute;
    }

    public function testTablesAreDerivedFromBackendType(): void
    {
        $selects = $this->makeQuery('entity_id')->build(
            ['sku-1'],
            [
                'weight' => $this->attribute('weight', 77, 'decimal'),
                'qty' => $this->attribute('qty', 78, 'int'),
                'name' => $this->attribute('name', 71, 'varchar'),
            ],
            0
        );

        self::assertSame(['decimal', 'int', 'varchar'], array_keys($selects));
        self::assertStringContainsString(
            'catalog_product_entity_decimal',
            (string) json_encode($selects['decimal']->getPart(Select::FROM))
        );
        self::assertStringContainsString(
            'catalog_product_entity_int',
            (string) json_encode($selects['int']->getPart(Select::FROM))
        );
        self::assertStringContainsString(
            'catalog_product_entity_varchar',
            (string) json_encode($selects['varchar']->getPart(Select::FROM))
        );
    }

    public function testValueSelectJoinsStoreAndDefaultRowsOnTheLinkField(): void
    {
        $selects = $this->makeQuery('row_id')->build(
            ['sku-1', 'sku-2'],
            ['name' => $this->attribute('name', 71, 'varchar')],
            5
        );

        $from = $selects['varchar']->getPart(Select::FROM);
        self::assertArrayHasKey('v', $from);
        self::assertArrayHasKey('e', $from);

        // the FROM entry is the value table, the join condition sits on the
        // product table it is joined with
        $condition = str_replace('`', '', (string) $from['e']['joinCondition']);
        self::assertStringContainsString('e.row_id = v.row_id', $condition);

        $where = str_replace('`', '', (string) json_encode($selects['varchar']->getPart(Select::WHERE)));
        self::assertStringContainsString('v.store_id IN (0,5)', $where);
        self::assertStringContainsString('v.attribute_id IN (71)', $where);
        self::assertStringContainsString('e.sku IN (sku-1,sku-2)', $where);
    }

    public function testDefaultStoreOnlyAsksForStoreZero(): void
    {
        $selects = $this->makeQuery('entity_id')->build(
            ['sku-1'],
            ['name' => $this->attribute('name', 71, 'varchar')],
            0
        );

        $where = str_replace('`', '', (string) json_encode($selects['varchar']->getPart(Select::WHERE)));
        self::assertStringContainsString('v.store_id IN (0)', $where);
    }

    public function testStaticAttributeReadsTheEntityTable(): void
    {
        $selects = $this->makeQuery('entity_id')->build(
            ['sku-1'],
            ['type_id' => $this->attribute('type_id', 99, 'static')],
            5
        );

        self::assertSame(['static'], array_keys($selects));

        $from = (string) json_encode($selects['static']->getPart(Select::FROM));
        self::assertStringContainsString('catalog_product_entity', $from);
        self::assertStringNotContainsString('_varchar', $from);

        $columns = (string) json_encode($selects['static']->getPart(Select::COLUMNS));
        self::assertStringContainsString('"sku"', $columns);
        self::assertStringContainsString('"type_id"', $columns);
    }

    public function testAttributesWithTheSameBackendTypeShareOneQuery(): void
    {
        $selects = $this->makeQuery('entity_id')->build(
            ['sku-1'],
            [
                'name' => $this->attribute('name', 71, 'varchar'),
                'meta_title' => $this->attribute('meta_title', 72, 'varchar'),
            ],
            0
        );

        self::assertCount(1, $selects);
        $where = str_replace('`', '', (string) json_encode($selects['varchar']->getPart(Select::WHERE)));
        self::assertStringContainsString('v.attribute_id IN (71,72)', $where);
    }
}
