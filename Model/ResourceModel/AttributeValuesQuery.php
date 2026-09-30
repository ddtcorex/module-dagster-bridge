<?php
/**
 * Copyright (c) 2026 DDTCoreX
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace DDTCoreX\DagsterBridge\Model\ResourceModel;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Eav\Api\Data\AttributeInterface;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\DB\SelectFactory;
use Magento\Framework\EntityManager\MetadataPool;

/**
 * Builds one value query per table the requested attributes live in.
 *
 * Pure query building: it never runs a statement, so a unit test can pin its
 * shape without a database.
 */
class AttributeValuesQuery
{
    /**
     * Backend type of an attribute whose value is a column of the product row.
     */
    public const STATIC_BACKEND_TYPE = 'static';

    private const PRODUCT_TABLE = 'catalog_product_entity';
    private const VALUE_TABLE_PREFIX = 'catalog_product_entity_';
    private const COLUMN_SKU = 'sku';

    /**
     * Backend types whose values live in a standard EAV value table.
     */
    private const VALUE_BACKEND_TYPES = ['datetime', 'decimal', 'int', 'text', 'varchar'];

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
     * @param ResourceConnection $resource
     * @param SelectFactory $selectFactory
     * @param MetadataPool $metadataPool
     */
    public function __construct(
        ResourceConnection $resource,
        SelectFactory $selectFactory,
        MetadataPool $metadataPool
    ) {
        $this->resource = $resource;
        $this->selectFactory = $selectFactory;
        $this->metadataPool = $metadataPool;
    }

    /**
     * Returns the codes this query cannot read, in request order.
     *
     * A static attribute is readable only when it is a column of the product
     * table: category_ids, for one, is static but lives elsewhere. Any other
     * attribute is readable only when its values are scalar rows of one of the
     * standard value tables: an attribute whose backend table is a table of its
     * own, or whose backend is not scalar (tier_price keeps its rows in a price
     * table while its declared table is the decimal one), is refused instead of
     * answering null for every product.
     *
     * @param AttributeInterface[] $attributes Keyed by attribute code.
     * @return string[]
     */
    public function unsupportedCodes(array $attributes): array
    {
        $productColumns = null;
        $standardTables = [];
        foreach (self::VALUE_BACKEND_TYPES as $backendType) {
            $standardTables[] = $this->resource->getTableName(self::VALUE_TABLE_PREFIX . $backendType);
        }

        $unsupported = [];
        foreach ($attributes as $code => $attribute) {
            $code = (string) $code;
            $backendType = (string) $attribute->getBackendType();

            if ($backendType === self::STATIC_BACKEND_TYPE) {
                if ($productColumns === null) {
                    $productColumns = array_keys(
                        $this->resource->getConnection()->describeTable(
                            $this->resource->getTableName(self::PRODUCT_TABLE)
                        )
                    );
                }
                if (!in_array($code, $productColumns, true)) {
                    $unsupported[] = $code;
                }
                continue;
            }

            if (!in_array($backendType, self::VALUE_BACKEND_TYPES, true)
                || !in_array($this->valueTable($attribute), $standardTables, true)
                || !$this->hasScalarBackend($attribute)
            ) {
                $unsupported[] = $code;
            }
        }

        return $unsupported;
    }

    /**
     * Builds the queries that answer one request.
     *
     * Attributes sharing a backend type share a query, because their values
     * live in the same table. Static attributes come from the product row
     * itself and are read in a single query over that table. The caller
     * rejects the codes unsupportedCodes() names before it builds anything.
     *
     * @param string[] $skus
     * @param AttributeInterface[] $attributes Keyed by attribute code.
     * @param int $storeId
     * @return Select[] Keyed by backend type.
     */
    public function build(array $skus, array $attributes, int $storeId): array
    {
        $byBackendType = [];
        foreach ($attributes as $code => $attribute) {
            $byBackendType[(string) $attribute->getBackendType()][(string) $code] = $attribute;
        }

        $linkField = $this->metadataPool->getMetadata(ProductInterface::class)->getLinkField();
        $connection = $this->resource->getConnection();
        $storeIds = $storeId === 0 ? [0] : [0, $storeId];

        $selects = [];
        foreach ($byBackendType as $backendType => $group) {
            $selects[$backendType] = $backendType === self::STATIC_BACKEND_TYPE
                ? $this->buildStaticSelect($skus, $group, $connection)
                : $this->buildValueSelect($skus, $group, $storeIds, $linkField, $connection);
        }

        return $selects;
    }

    /**
     * Resolves the table an attribute's values live in.
     *
     * An EAV attribute knows its own table, which honours a declared backend
     * table; anything else falls back to the prefix and backend type.
     *
     * @param AttributeInterface $attribute
     * @return string
     */
    private function valueTable(AttributeInterface $attribute): string
    {
        if ($attribute instanceof AbstractAttribute) {
            return (string) $attribute->getBackendTable();
        }

        return $this->resource->getTableName(self::VALUE_TABLE_PREFIX . $attribute->getBackendType());
    }

    /**
     * Tells whether the attribute's backend stores plain scalar value rows.
     *
     * @param AttributeInterface $attribute
     * @return bool
     */
    private function hasScalarBackend(AttributeInterface $attribute): bool
    {
        return !$attribute instanceof AbstractAttribute || $attribute->getBackend()->isScalar();
    }

    /**
     * Builds the query for one EAV value table.
     *
     * The join goes through the link field, never through a hard coded
     * entity_id, and each row carries its own store so the caller can tell the
     * store value from the default one.
     *
     * @param string[] $skus
     * @param AttributeInterface[] $group
     * @param int[] $storeIds
     * @param string $linkField
     * @param AdapterInterface $connection
     * @return Select
     */
    private function buildValueSelect(
        array $skus,
        array $group,
        array $storeIds,
        string $linkField,
        AdapterInterface $connection
    ): Select {
        $attributeIds = [];
        foreach ($group as $attribute) {
            $attributeIds[] = (int) $attribute->getAttributeId();
        }
        // every attribute of the group shares its backend type, and
        // unsupportedCodes() refused any whose table is not the standard one
        $valueTable = $this->valueTable(reset($group));

        $condition = $connection->quoteIdentifier('e.' . $linkField)
            . ' = ' . $connection->quoteIdentifier('v.' . $linkField);

        return $this->selectFactory->create($connection)
            ->from(
                ['v' => $valueTable],
                [
                    'attribute_id' => 'v.attribute_id',
                    'store_id' => 'v.store_id',
                    'value' => 'v.value',
                ]
            )
            ->join(['e' => $this->resource->getTableName(self::PRODUCT_TABLE)], $condition, [
                self::COLUMN_SKU => 'e.' . self::COLUMN_SKU,
            ])
            ->where('v.attribute_id IN (?)', $attributeIds)
            ->where('v.store_id IN (?)', $storeIds)
            ->where('e.' . self::COLUMN_SKU . ' IN (?)', array_values($skus));
    }

    /**
     * Builds the query for the static attributes.
     *
     * Their values are columns of the product row itself, so the query selects
     * one column per attribute, aliased with the attribute code.
     *
     * @param string[] $skus
     * @param AttributeInterface[] $group
     * @param AdapterInterface $connection
     * @return Select
     */
    private function buildStaticSelect(array $skus, array $group, AdapterInterface $connection): Select
    {
        $columns = [self::COLUMN_SKU => 'e.' . self::COLUMN_SKU];
        foreach ($group as $code => $attribute) {
            $columns[$code] = 'e.' . $code;
        }

        return $this->selectFactory->create($connection)
            ->from(['e' => $this->resource->getTableName(self::PRODUCT_TABLE)], $columns)
            ->where('e.' . self::COLUMN_SKU . ' IN (?)', array_values($skus));
    }
}
