<?php
/**
 * Copyright (c) 2026 DDTCoreX
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace DDTCoreX\DagsterBridge\Test\Unit\Model\ResourceModel;

use DDTCoreX\DagsterBridge\Model\ResourceModel\ProductIndexQuery;
use Magento\Catalog\Api\Data\ProductAttributeInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Eav\Api\AttributeRepositoryInterface;
use Magento\Eav\Api\Data\AttributeInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Magento\Framework\DB\Select;
use Magento\Framework\DB\SelectFactory;
use Magento\Framework\DB\Select\SelectRenderer;
use Magento\Framework\EntityManager\EntityMetadataInterface;
use Magento\Framework\EntityManager\MetadataPool;
use PHPUnit\Framework\TestCase;

/**
 * Pins the shape of the index query.
 *
 * The assertions read the parts of the Select instead of its rendered SQL: the
 * where clause keeps the placeholder that carries the caller's cursor, and
 * rendering would need a live adapter only to quote identifiers.
 */
class ProductIndexQueryTest extends TestCase
{
    /**
     * Builds the query builder over mocked metadata and EAV lookups.
     *
     * @param string $linkField
     * @param int $statusAttributeId
     * @return ProductIndexQuery
     */
    private function makeQuery(string $linkField, int $statusAttributeId): ProductIndexQuery
    {
        $adapter = $this->createMock(Mysql::class);
        $adapter->expects($this->atLeastOnce())
            ->method('quoteIdentifier')
            ->willReturnCallback(
                static function ($identifier, $auto = false) {
                    return '`' . $identifier . '`';
                }
            );
        $adapter->expects($this->atLeastOnce())
            ->method('quoteInto')
            ->willReturnCallback(
                static function ($text, $value, $type = null) {
                    return str_replace('?', (string) $value, $text);
                }
            );

        $metadata = $this->createMock(EntityMetadataInterface::class);
        $metadata->expects($this->atLeastOnce())->method('getLinkField')->willReturn($linkField);

        $metadataPool = $this->createMock(MetadataPool::class);
        $metadataPool->expects($this->atLeastOnce())
            ->method('getMetadata')
            ->with(ProductInterface::class)
            ->willReturn($metadata);

        $attribute = $this->createMock(AttributeInterface::class);
        $attribute->expects($this->atLeastOnce())->method('getAttributeId')->willReturn($statusAttributeId);

        $attributeRepository = $this->createMock(AttributeRepositoryInterface::class);
        $attributeRepository->expects($this->atLeastOnce())
            ->method('get')
            ->with(ProductAttributeInterface::ENTITY_TYPE_CODE, 'status')
            ->willReturn($attribute);

        $resource = $this->createMock(ResourceConnection::class);
        $resource->expects($this->atLeastOnce())->method('getConnection')->willReturn($adapter);
        $resource->expects($this->atLeastOnce())
            ->method('getTableName')
            ->willReturnCallback(
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

        return new ProductIndexQuery($resource, $selectFactory, $metadataPool, $attributeRepository);
    }

    public function testIndexPageQueryUsesEntityIdGreaterThanAfterAndOrdersByEntityId(): void
    {
        $select = $this->makeQuery('entity_id', 97)->build(42, 500);

        $where = (string) json_encode($select->getPart(Select::WHERE));
        self::assertStringContainsString('e.entity_id > 42', $where);

        $order = (string) json_encode($select->getPart(Select::ORDER));
        self::assertStringContainsString('"e.entity_id","ASC"', $order);

        self::assertSame(500, (int) $select->getPart(Select::LIMIT_COUNT));
    }

    public function testStatusJoinUsesTheLinkFieldAndStoreZero(): void
    {
        $select = $this->makeQuery('row_id', 97)->build(0, 10);

        $from = $select->getPart(Select::FROM);
        self::assertArrayHasKey('status_value', $from);
        self::assertSame('left join', $from['status_value']['joinType']);

        $condition = str_replace('`', '', (string) $from['status_value']['joinCondition']);
        self::assertStringContainsString('status_value.row_id = e.row_id', $condition);
        self::assertStringContainsString('status_value.attribute_id = 97', $condition);
        self::assertStringContainsString('status_value.store_id = 0', $condition);
    }

    public function testStatusJoinTakesTheLinkFieldFromTheMetadataPool(): void
    {
        // only the source of the join column is pinned here: the module
        // supports Open Source, where the link field is entity_id, and makes
        // no claim about Commerce content staging (row_id versions)
        $select = $this->makeQuery('link_field_from_metadata', 97)->build(0, 10);

        $from = $select->getPart(Select::FROM);
        $condition = str_replace('`', '', (string) $from['status_value']['joinCondition']);
        self::assertStringContainsString(
            'status_value.link_field_from_metadata = e.link_field_from_metadata',
            $condition
        );
    }

    public function testReadsTheProductTableAndItsValueTable(): void
    {
        $select = $this->makeQuery('entity_id', 97)->build(0, 10);

        $from = $select->getPart(Select::FROM);
        self::assertArrayHasKey('e', $from);
        self::assertArrayHasKey('status_value', $from);

        $tables = (string) json_encode($from);
        self::assertStringContainsString('catalog_product_entity_int', $tables);
        self::assertStringContainsString('catalog_product_entity', $tables);

        $columns = $select->getPart(Select::COLUMNS);
        $aliases = array_column($columns, 2);
        self::assertContains('entity_id', $aliases);
        self::assertContains('sku', $aliases);
        self::assertContains('type_id', $aliases);
        self::assertContains('attribute_set_id', $aliases);
        self::assertContains('updated_at', $aliases);
        self::assertContains('status', $aliases);
    }
}
