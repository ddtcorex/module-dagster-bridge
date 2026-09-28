<?php
/**
 * Copyright (c) 2026 DDTCoreX
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace DDTCoreX\DagsterBridge\Api;

use Magento\Framework\Exception\InputException;

/**
 * Store scoped attribute values for many products in one call.
 *
 * A POST is used only because the request carries two lists; nothing is
 * written.
 *
 * @api
 */
interface AttributeValuesInterface
{
    public const MAX_SKUS = 1000;
    public const MAX_ATTRIBUTE_CODES = 50;

    /**
     * Returns one item per requested product and attribute code.
     *
     * Pairs whose values are both null are returned as well, so a caller can
     * tell "no value" from "not asked", and the store value and the default
     * value come back separately so the caller can apply Magento's own
     * fallback.
     *
     * @param string[] $skus At most MAX_SKUS SKUs.
     * @param string[] $attributeCodes At most MAX_ATTRIBUTE_CODES codes.
     * @param int $storeId Store view whose value is wanted.
     * @return \DDTCoreX\DagsterBridge\Api\Data\AttributeValueItemInterface[]
     * @throws InputException
     */
    public function get(array $skus, array $attributeCodes, int $storeId = 0): array;
}
