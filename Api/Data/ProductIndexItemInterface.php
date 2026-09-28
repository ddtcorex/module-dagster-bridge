<?php
/**
 * Copyright (c) 2026 DDTCoreX
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace DDTCoreX\DagsterBridge\Api\Data;

/**
 * One product in the index.
 *
 * @api
 */
interface ProductIndexItemInterface
{
    public const ENTITY_ID = 'entity_id';
    public const SKU = 'sku';
    public const TYPE_ID = 'type_id';
    public const ATTRIBUTE_SET_ID = 'attribute_set_id';
    public const STATUS = 'status';
    public const UPDATED_AT = 'updated_at';

    /**
     * Entity id of the product.
     *
     * @return int
     */
    public function getEntityId(): int;

    /**
     * SKU of the product.
     *
     * @return string
     */
    public function getSku(): string;

    /**
     * Product type, for example "simple" or "configurable".
     *
     * @return string
     */
    public function getTypeId(): string;

    /**
     * Attribute set the product belongs to.
     *
     * @return int
     */
    public function getAttributeSetId(): int;

    /**
     * Status as the default store sees it.
     *
     * Null when the product has no status value at all.
     *
     * @return int|null
     */
    public function getStatus(): ?int;

    /**
     * Last modification time of the product row.
     *
     * @return string
     */
    public function getUpdatedAt(): string;

    /**
     * Sets the entity id.
     *
     * @param int $entityId
     * @return ProductIndexItemInterface
     */
    public function setEntityId(int $entityId): ProductIndexItemInterface;

    /**
     * Sets the SKU.
     *
     * @param string $sku
     * @return ProductIndexItemInterface
     */
    public function setSku(string $sku): ProductIndexItemInterface;

    /**
     * Sets the product type.
     *
     * @param string $typeId
     * @return ProductIndexItemInterface
     */
    public function setTypeId(string $typeId): ProductIndexItemInterface;

    /**
     * Sets the attribute set id.
     *
     * @param int $attributeSetId
     * @return ProductIndexItemInterface
     */
    public function setAttributeSetId(int $attributeSetId): ProductIndexItemInterface;

    /**
     * Sets the status.
     *
     * @param int|null $status
     * @return ProductIndexItemInterface
     */
    public function setStatus(?int $status): ProductIndexItemInterface;

    /**
     * Sets the modification time.
     *
     * @param string $updatedAt
     * @return ProductIndexItemInterface
     */
    public function setUpdatedAt(string $updatedAt): ProductIndexItemInterface;
}
