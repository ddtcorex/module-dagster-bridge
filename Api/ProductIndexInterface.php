<?php
/**
 * Copyright (c) 2026 DDTCoreX
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace DDTCoreX\DagsterBridge\Api;

use DDTCoreX\DagsterBridge\Api\Data\ProductIndexPageInterface;

/**
 * The identity of every product, without the cost of the REST collection.
 *
 * @api
 */
interface ProductIndexInterface
{
    /**
     * Total number of products the index can return in one page.
     */
    public const MAX_LIMIT = 20000;

    /**
     * Default page size.
     */
    public const DEFAULT_LIMIT = 5000;

    /**
     * Returns one page of the product index.
     *
     * Keyset pagination on entity id: pass the next_after of the previous page
     * as after. A product deleted between two pages cannot make a caller skip
     * another one, which an offset based page would.
     *
     * @param int $after Return products whose entity id is greater than this value.
     * @param int $limit Page size, between 1 and MAX_LIMIT.
     * @return ProductIndexPageInterface
     */
    public function getPage(int $after = 0, int $limit = self::DEFAULT_LIMIT): ProductIndexPageInterface;
}
