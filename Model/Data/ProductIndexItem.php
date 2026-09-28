<?php
/**
 * Copyright (c) 2026 DDTCoreX
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace DDTCoreX\DagsterBridge\Model\Data;

use DDTCoreX\DagsterBridge\Api\Data\ProductIndexItemInterface;
use Magento\Framework\Api\AbstractSimpleObject;

/**
 * One product of the index.
 */
class ProductIndexItem extends AbstractSimpleObject implements ProductIndexItemInterface
{
    /**
     * Entity id of the product.
     *
     * @return int
     */
    public function getEntityId(): int
    {
        return (int) $this->_get(self::ENTITY_ID);
    }

    /**
     * SKU of the product.
     *
     * @return string
     */
    public function getSku(): string
    {
        return (string) $this->_get(self::SKU);
    }

    /**
     * Product type.
     *
     * @return string
     */
    public function getTypeId(): string
    {
        return (string) $this->_get(self::TYPE_ID);
    }

    /**
     * Attribute set id.
     *
     * @return int
     */
    public function getAttributeSetId(): int
    {
        return (int) $this->_get(self::ATTRIBUTE_SET_ID);
    }

    /**
     * Status in the default store.
     *
     * @return int|null
     */
    public function getStatus(): ?int
    {
        $status = $this->_get(self::STATUS);

        return $status === null ? null : (int) $status;
    }

    /**
     * Last modification time.
     *
     * @return string
     */
    public function getUpdatedAt(): string
    {
        return (string) $this->_get(self::UPDATED_AT);
    }

    /**
     * Sets the entity id.
     *
     * @param int $entityId
     * @return ProductIndexItemInterface
     */
    public function setEntityId(int $entityId): ProductIndexItemInterface
    {
        return $this->setData(self::ENTITY_ID, $entityId);
    }

    /**
     * Sets the SKU.
     *
     * @param string $sku
     * @return ProductIndexItemInterface
     */
    public function setSku(string $sku): ProductIndexItemInterface
    {
        return $this->setData(self::SKU, $sku);
    }

    /**
     * Sets the product type.
     *
     * @param string $typeId
     * @return ProductIndexItemInterface
     */
    public function setTypeId(string $typeId): ProductIndexItemInterface
    {
        return $this->setData(self::TYPE_ID, $typeId);
    }

    /**
     * Sets the attribute set id.
     *
     * @param int $attributeSetId
     * @return ProductIndexItemInterface
     */
    public function setAttributeSetId(int $attributeSetId): ProductIndexItemInterface
    {
        return $this->setData(self::ATTRIBUTE_SET_ID, $attributeSetId);
    }

    /**
     * Sets the status.
     *
     * @param int|null $status
     * @return ProductIndexItemInterface
     */
    public function setStatus(?int $status): ProductIndexItemInterface
    {
        return $this->setData(self::STATUS, $status);
    }

    /**
     * Sets the modification time.
     *
     * @param string $updatedAt
     * @return ProductIndexItemInterface
     */
    public function setUpdatedAt(string $updatedAt): ProductIndexItemInterface
    {
        return $this->setData(self::UPDATED_AT, $updatedAt);
    }
}
