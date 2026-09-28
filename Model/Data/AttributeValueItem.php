<?php
/**
 * Copyright (c) 2026 DDTCoreX
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace DDTCoreX\DagsterBridge\Model\Data;

use DDTCoreX\DagsterBridge\Api\Data\AttributeValueItemInterface;
use Magento\Framework\Api\AbstractSimpleObject;

/**
 * One attribute value of one product.
 */
class AttributeValueItem extends AbstractSimpleObject implements AttributeValueItemInterface
{
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
     * Attribute code.
     *
     * @return string
     */
    public function getAttributeCode(): string
    {
        return (string) $this->_get(self::ATTRIBUTE_CODE);
    }

    /**
     * Value in the requested store.
     *
     * @return string|null
     */
    public function getStoreValue(): ?string
    {
        $value = $this->_get(self::STORE_VALUE);

        return $value === null ? null : (string) $value;
    }

    /**
     * Value in the default store.
     *
     * @return string|null
     */
    public function getDefaultValue(): ?string
    {
        $value = $this->_get(self::DEFAULT_VALUE);

        return $value === null ? null : (string) $value;
    }

    /**
     * Sets the SKU.
     *
     * @param string $sku
     * @return AttributeValueItemInterface
     */
    public function setSku(string $sku): AttributeValueItemInterface
    {
        return $this->setData(self::SKU, $sku);
    }

    /**
     * Sets the attribute code.
     *
     * @param string $attributeCode
     * @return AttributeValueItemInterface
     */
    public function setAttributeCode(string $attributeCode): AttributeValueItemInterface
    {
        return $this->setData(self::ATTRIBUTE_CODE, $attributeCode);
    }

    /**
     * Sets the store value.
     *
     * @param string|null $storeValue
     * @return AttributeValueItemInterface
     */
    public function setStoreValue(?string $storeValue): AttributeValueItemInterface
    {
        return $this->setData(self::STORE_VALUE, $storeValue);
    }

    /**
     * Sets the default value.
     *
     * @param string|null $defaultValue
     * @return AttributeValueItemInterface
     */
    public function setDefaultValue(?string $defaultValue): AttributeValueItemInterface
    {
        return $this->setData(self::DEFAULT_VALUE, $defaultValue);
    }
}
