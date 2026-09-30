<?php
/**
 * Copyright (c) 2026 DDTCoreX
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace DDTCoreX\DagsterBridge\Model;

use DDTCoreX\DagsterBridge\Api\AttributeValuesInterface;
use DDTCoreX\DagsterBridge\Api\Data\AttributeValueItemInterface;
use DDTCoreX\DagsterBridge\Model\Data\AttributeValueItemFactory;
use DDTCoreX\DagsterBridge\Model\ResourceModel\AttributeValuesQuery;
use Magento\Catalog\Api\Data\ProductAttributeInterface;
use Magento\Eav\Api\AttributeRepositoryInterface;
use Magento\Eav\Api\Data\AttributeInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\StoreRepositoryInterface;

/**
 * Reads store scoped attribute values for many products at once.
 */
class AttributeValues implements AttributeValuesInterface
{
    /**
     * Connection the queries run on.
     *
     * @var ResourceConnection
     */
    private $resource;

    /**
     * Query builder.
     *
     * @var AttributeValuesQuery
     */
    private $query;

    /**
     * Source of attribute ids and backend types.
     *
     * @var AttributeRepositoryInterface
     */
    private $attributeRepository;

    /**
     * Factory of one value item.
     *
     * @var AttributeValueItemFactory
     */
    private $itemFactory;

    /**
     * Source of the stores a caller may ask for.
     *
     * @var StoreRepositoryInterface
     */
    private $storeRepository;

    /**
     * @param ResourceConnection $resource
     * @param AttributeValuesQuery $query
     * @param AttributeRepositoryInterface $attributeRepository
     * @param AttributeValueItemFactory $itemFactory
     * @param StoreRepositoryInterface $storeRepository
     */
    public function __construct(
        ResourceConnection $resource,
        AttributeValuesQuery $query,
        AttributeRepositoryInterface $attributeRepository,
        AttributeValueItemFactory $itemFactory,
        StoreRepositoryInterface $storeRepository
    ) {
        $this->resource = $resource;
        $this->query = $query;
        $this->attributeRepository = $attributeRepository;
        $this->itemFactory = $itemFactory;
        $this->storeRepository = $storeRepository;
    }

    /**
     * Returns one item per requested product and attribute code.
     *
     * For a store of its own, an item carries the store value when that store
     * has one and the default value always, so the caller can apply Magento's
     * fallback itself instead of guessing. Static attributes only ever carry
     * the default value.
     *
     * @param string[] $skus
     * @param string[] $attributeCodes
     * @param int $storeId
     * @return \DDTCoreX\DagsterBridge\Api\Data\AttributeValueItemInterface[]
     * @throws InputException
     */
    public function get(array $skus, array $attributeCodes, int $storeId = 0): array
    {
        $skus = array_values(array_unique($skus));
        $attributeCodes = array_values(array_unique($attributeCodes));

        if ($skus === []) {
            throw new InputException(__('At least one SKU is required.'));
        }
        if ($attributeCodes === []) {
            throw new InputException(__('At least one attribute code is required.'));
        }
        if (count($skus) > self::MAX_SKUS) {
            throw new InputException(
                __('At most %1 SKUs can be requested in one call, %2 given.', self::MAX_SKUS, count($skus))
            );
        }
        if (count($attributeCodes) > self::MAX_ATTRIBUTE_CODES) {
            throw new InputException(
                __(
                    'At most %1 attribute codes can be requested in one call, %2 given.',
                    self::MAX_ATTRIBUTE_CODES,
                    count($attributeCodes)
                )
            );
        }

        $this->assertStoreExists($storeId);
        $attributes = $this->resolveAttributes($attributeCodes);

        $codeById = [];
        $staticCodes = [];
        foreach ($attributes as $code => $attribute) {
            $codeById[(int) $attribute->getAttributeId()] = (string) $code;
            if ($attribute->getBackendType() === AttributeValuesQuery::STATIC_BACKEND_TYPE) {
                $staticCodes[(string) $code] = true;
            }
        }

        // the database matches SKUs case-insensitively and answers in its own
        // spelling, so values are keyed by the lowercased SKU and every item
        // is answered under the spelling the caller asked for
        $values = [];
        foreach ($skus as $sku) {
            foreach ($attributeCodes as $code) {
                $values[$this->skuKey($sku)][$code] = [null, null];
            }
        }

        $connection = $this->resource->getConnection();
        foreach ($this->query->build($skus, $attributes, $storeId) as $backendType => $select) {
            /** @var array<int, array<string, mixed>> $rows */
            $rows = $connection->fetchAll($select);

            if ($backendType === AttributeValuesQuery::STATIC_BACKEND_TYPE) {
                foreach ($rows as $row) {
                    $sku = $this->skuKey((string) $row[AttributeValueItemInterface::SKU]);
                    foreach (array_keys($staticCodes) as $code) {
                        $values[$sku][$code][1] = $this->stringOrNull($row[$code] ?? null);
                    }
                }
                continue;
            }

            foreach ($rows as $row) {
                $sku = $this->skuKey((string) $row[AttributeValueItemInterface::SKU]);
                $code = $codeById[(int) $row['attribute_id']] ?? null;
                if ($code === null) {
                    continue;
                }

                $value = $this->stringOrNull($row['value']);
                $rowStoreId = (int) $row['store_id'];
                if ($rowStoreId === 0) {
                    $values[$sku][$code][1] = $value;
                }
                if ($rowStoreId === $storeId) {
                    $values[$sku][$code][0] = $value;
                }
            }
        }

        $items = [];
        foreach ($skus as $sku) {
            foreach ($attributeCodes as $code) {
                $item = $this->itemFactory->create();
                $item->setSku($sku);
                $item->setAttributeCode($code);
                $item->setStoreValue($values[$this->skuKey($sku)][$code][0]);
                $item->setDefaultValue($values[$this->skuKey($sku)][$code][1]);
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * Rejects a store id that names no store, instead of answering defaults.
     *
     * @param int $storeId
     * @return void
     * @throws InputException
     */
    private function assertStoreExists(int $storeId): void
    {
        try {
            $this->storeRepository->getById($storeId);
        } catch (NoSuchEntityException $exception) {
            throw new InputException(__('Store %1 does not exist.', $storeId), $exception);
        }
    }

    /**
     * Resolves every requested code, naming all unknown or unreadable ones at once.
     *
     * @param string[] $attributeCodes
     * @return AttributeInterface[] Keyed by attribute code.
     * @throws InputException
     */
    private function resolveAttributes(array $attributeCodes): array
    {
        $attributes = [];
        $unknown = [];
        foreach ($attributeCodes as $code) {
            try {
                $attributes[$code] = $this->attributeRepository->get(
                    ProductAttributeInterface::ENTITY_TYPE_CODE,
                    $code
                );
            } catch (NoSuchEntityException $exception) {
                $unknown[] = $code;
            }
        }

        if ($unknown !== []) {
            throw new InputException(__('Unknown attribute codes: %1.', implode(', ', $unknown)));
        }

        $unsupported = $this->query->unsupportedCodes($attributes);
        if ($unsupported !== []) {
            throw new InputException(
                __(
                    'Attribute codes this endpoint cannot read: %1. A static attribute must be a column of the '
                    . 'product table, any other one must keep scalar values in a standard EAV value table.',
                    implode(', ', $unsupported)
                )
            );
        }

        return $attributes;
    }

    /**
     * Key a SKU is looked up under, whatever its case.
     *
     * @param string $sku
     * @return string
     */
    private function skuKey(string $sku): string
    {
        return mb_strtolower($sku);
    }

    /**
     * Casts a database value, keeping null as null.
     *
     * @param mixed $value
     * @return string|null
     */
    private function stringOrNull($value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
