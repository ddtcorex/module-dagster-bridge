<?php
/**
 * Copyright (c) 2026 DDTCoreX
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace DDTCoreX\DagsterBridge\Api\Data;

/**
 * One attribute value of one product.
 *
 * @api
 */
interface AttributeValueItemInterface
{
    public const SKU = 'sku';
    public const ATTRIBUTE_CODE = 'attribute_code';
    public const STORE_VALUE = 'store_value';
    public const DEFAULT_VALUE = 'default_value';

    /**
     * SKU of the product.
     *
     * @return string
     */
    public function getSku(): string;

    /**
     * Attribute code.
     *
     * @return string
     */
    public function getAttributeCode(): string;

    /**
     * Value in the requested store.
     *
     * Null when that store has no value of its own. Always null for a static
     * attribute, whose value exists once for the whole catalog.
     *
     * @return string|null
     */
    public function getStoreValue(): ?string;

    /**
     * Value in the default store.
     *
     * Null when there is none.
     *
     * @return string|null
     */
    public function getDefaultValue(): ?string;

    /**
     * Sets the SKU.
     *
     * @param string $sku
     * @return AttributeValueItemInterface
     */
    public function setSku(string $sku): AttributeValueItemInterface;

    /**
     * Sets the attribute code.
     *
     * @param string $attributeCode
     * @return AttributeValueItemInterface
     */
    public function setAttributeCode(string $attributeCode): AttributeValueItemInterface;

    /**
     * Sets the store value.
     *
     * @param string|null $storeValue
     * @return AttributeValueItemInterface
     */
    public function setStoreValue(?string $storeValue): AttributeValueItemInterface;

    /**
     * Sets the default value.
     *
     * @param string|null $defaultValue
     * @return AttributeValueItemInterface
     */
    public function setDefaultValue(?string $defaultValue): AttributeValueItemInterface;
}
