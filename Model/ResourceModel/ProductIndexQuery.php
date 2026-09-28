<?php
/**
 * Copyright (c) 2026 DDTCoreX
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace DDTCoreX\DagsterBridge\Model\ResourceModel;

use Magento\Catalog\Api\Data\ProductAttributeInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Eav\Api\AttributeRepositoryInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;
use Magento\Framework\DB\SelectFactory;
use Magento\Framework\EntityManager\MetadataPool;

/**
 * Builds the keyset paginated product index query.
 *
 * Pure query building: it never runs the statement, so a unit test can pin its
 * shape without a database.
 */
class ProductIndexQuery
{
    private const PRODUCT_TABLE = 'catalog_product_entity';
    private const STATUS_ATTRIBUTE_CODE = 'status';

    /**
     * Connection and table names.
     *
     * @var ResourceConnection
     */
    private $resource;

    /**
     * Factory of the select object.
     *
     * @var SelectFactory
     */
    private $selectFactory;

    /**
     * Source of the product link field.
     *
     * @var MetadataPool
     */
    private $metadataPool;

    /**
     * Source of the status attribute id.
     *
     * @var AttributeRepositoryInterface
     */
    private $attributeRepository;

    /**
     * @param ResourceConnection $resource
     * @param SelectFactory $selectFactory
     * @param MetadataPool $metadataPool
     * @param AttributeRepositoryInterface $attributeRepository
     */
    public function __construct(
        ResourceConnection $resource,
        SelectFactory $selectFactory,
        MetadataPool $metadataPool,
        AttributeRepositoryInterface $attributeRepository
    ) {
        $this->resource = $resource;
        $this->selectFactory = $selectFactory;
        $this->metadataPool = $metadataPool;
        $this->attributeRepository = $attributeRepository;
    }

    /**
     * Builds one page of the index.
     *
     * The status value is joined through the product link field, which is
     * entity_id on Open Source and row_id on Commerce with staging, and only
     * for store 0: the store scoped status is not what the index reports.
     *
     * @param int $after
     * @param int $limit
     * @return Select
     */
    public function build(int $after, int $limit): Select
    {
        $linkField = $this->metadataPool->getMetadata(ProductInterface::class)->getLinkField();
        $connection = $this->resource->getConnection();

        $condition = implode(' AND ', [
            $connection->quoteIdentifier('status_value.' . $linkField)
                . ' = ' . $connection->quoteIdentifier('e.' . $linkField),
            $connection->quoteIdentifier('status_value.attribute_id') . ' = ' . $this->statusAttributeId(),
            $connection->quoteIdentifier('status_value.store_id') . ' = 0',
        ]);

        return $this->selectFactory->create($connection)
            ->from(
                ['e' => $this->resource->getTableName(self::PRODUCT_TABLE)],
                [
                    'entity_id' => 'e.entity_id',
                    'sku' => 'e.sku',
                    'type_id' => 'e.type_id',
                    'attribute_set_id' => 'e.attribute_set_id',
                    'updated_at' => 'e.updated_at',
                ]
            )
            ->joinLeft(
                ['status_value' => $this->resource->getTableName(self::PRODUCT_TABLE . '_int')],
                $condition,
                ['status' => 'status_value.value']
            )
            ->where('e.entity_id > ?', $after)
            ->order('e.entity_id ' . Select::SQL_ASC)
            ->limit($limit);
    }

    /**
     * Resolves the status attribute id from the EAV attribute repository.
     *
     * @return int
     */
    private function statusAttributeId(): int
    {
        return (int) $this->attributeRepository
            ->get(ProductAttributeInterface::ENTITY_TYPE_CODE, self::STATUS_ATTRIBUTE_CODE)
            ->getAttributeId();
    }
}
