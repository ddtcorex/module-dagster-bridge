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
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Eav\Model\Entity\Attribute\Backend\BackendInterface;
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
     * @param string[] $productColumns Columns of catalog_product_entity.
     * @return AttributeValuesQuery
     */
    private function makeQuery(
        string $linkField,
        array $productColumns = ['entity_id', 'sku', 'type_id', 'attribute_set_id']
    ): AttributeValuesQuery {
        $adapter = $this->createStub(Mysql::class);
        $adapter->method('describeTable')->willReturnCallback(
            static function ($tableName, $schemaName = null) use ($productColumns) {
                return $tableName === 'catalog_product_entity' ? array_fill_keys($productColumns, []) : [];
            }
        );
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
        $selectFactory = $this->createStub(SelectFactory::class);
        $selectFactory->method('create')
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

    /**
     * Builds a real EAV attribute double, with its backend table and backend.
     *
     * @param string $code
     * @param int $attributeId
     * @param string $backendType
     * @param string $backendTable
     * @param bool $scalar
     * @return AbstractAttribute
     */
    private function eavAttribute(
        string $code,
        int $attributeId,
        string $backendType,
        string $backendTable,
        bool $scalar = true
    ): AbstractAttribute {
        $backend = $this->createStub(BackendInterface::class);
        $backend->method('isScalar')->willReturn($scalar);

        $attribute = $this->createStub(AbstractAttribute::class);
        $attribute->method('getAttributeCode')->willReturn($code);
        $attribute->method('getAttributeId')->willReturn($attributeId);
        $attribute->method('getBackendType')->willReturn($backendType);
        $attribute->method('getBackendTable')->willReturn($backendTable);
        $attribute->method('getBackend')->willReturn($backend);

        return $attribute;
    }

    public function testStaticAttributeThatIsNotAProductColumnIsUnsupported(): void
    {
        self::assertSame(
            ['category_ids'],
            $this->makeQuery('entity_id')->unsupportedCodes([
                'type_id' => $this->attribute('type_id', 99, 'static'),
                'category_ids' => $this->attribute('category_ids', 100, 'static'),
            ])
        );
    }

    public function testAttributeWhoseBackendIsNotScalarIsUnsupported(): void
    {
        // tier_price declares no backend table, so getBackendTable() names the
        // decimal table, but its backend keeps the values in a table of its own
        self::assertSame(
            ['tier_price'],
            $this->makeQuery('entity_id')->unsupportedCodes([
                'price' => $this->eavAttribute('price', 77, 'decimal', 'catalog_product_entity_decimal'),
                'tier_price' => $this->eavAttribute(
                    'tier_price',
                    78,
                    'decimal',
                    'catalog_product_entity_decimal',
                    false
                ),
            ])
        );
    }

    public function testAttributeWithACustomBackendTableIsUnsupported(): void
    {
        self::assertSame(
            ['custom'],
            $this->makeQuery('entity_id')->unsupportedCodes([
                'name' => $this->eavAttribute('name', 71, 'varchar', 'catalog_product_entity_varchar'),
                'custom' => $this->eavAttribute('custom', 80, 'varchar', 'vendor_custom_value'),
            ])
        );
    }

    public function testValueTableIsTheAttributeBackendTable(): void
    {
        $selects = $this->makeQuery('entity_id')->build(
            ['sku-1'],
            ['name' => $this->eavAttribute('name', 71, 'varchar', 'catalog_product_entity_varchar')],
            0
        );

        $from = $selects['varchar']->getPart(Select::FROM);
        self::assertSame('catalog_product_entity_varchar', $from['v']['tableName']);
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

    public function testCallerValuesAreBoundThroughTheAdapterQuoting(): void
    {
        // the other tests use a quote double that pastes values in raw, so
        // they prove the shape of the query only; this one escapes the way
        // MySQL string literals are escaped and checks a hostile SKU stays
        // inside its literal
        $adapter = $this->createStub(Mysql::class);
        $adapter->method('quoteIdentifier')->willReturnCallback(
            static function ($identifier, $auto = false) {
                return '`' . str_replace('`', '``', (string) $identifier) . '`';
            }
        );
        $quote = static function ($value): string {
            return is_int($value) ? (string) $value : "'" . addcslashes((string) $value, "\000\n\r\\'\"\032") . "'";
        };
        $adapter->method('quoteInto')->willReturnCallback(
            static function ($text, $value, $type = null) use ($quote) {
                $quoted = is_array($value) ? implode(', ', array_map($quote, $value)) : $quote($value);

                return str_replace('?', $quoted, $text);
            }
        );

        $metadata = $this->createStub(EntityMetadataInterface::class);
        $metadata->method('getLinkField')->willReturn('entity_id');
        $metadataPool = $this->createStub(MetadataPool::class);
        $metadataPool->method('getMetadata')->willReturn($metadata);

        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($adapter);
        $resource->method('getTableName')->willReturnArgument(0);

        $selectRenderer = $this->createStub(SelectRenderer::class);
        $selectFactory = $this->createStub(SelectFactory::class);
        $selectFactory->method('create')->willReturnCallback(
            static function () use ($adapter, $selectRenderer) {
                return new Select($adapter, $selectRenderer);
            }
        );

        $hostile = "x') OR 1=1 -- ";
        $selects = (new AttributeValuesQuery($resource, $selectFactory, $metadataPool))->build(
            [$hostile, "o'brien"],
            ['name' => $this->attribute('name', 71, 'varchar')],
            5
        );

        $where = implode(' ', $selects['varchar']->getPart(Select::WHERE));
        self::assertStringContainsString("e.sku IN ('x\\') OR 1=1 -- ', 'o\\'brien')", $where);
        self::assertStringNotContainsString("'x') OR", $where);
        self::assertStringContainsString('v.attribute_id IN (71)', $where);
        self::assertStringContainsString('v.store_id IN (0, 5)', $where);
    }
}
