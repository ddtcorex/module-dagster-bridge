<?php
/**
 * Copyright (c) 2026 DDTCoreX
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace DDTCoreX\DagsterBridge\Api\Data;

/**
 * One page of the product index.
 *
 * @api
 */
interface ProductIndexPageInterface
{
    public const ITEMS = 'items';
    public const NEXT_AFTER = 'next_after';

    /**
     * Products of this page, ordered by entity id.
     *
     * @return \DDTCoreX\DagsterBridge\Api\Data\ProductIndexItemInterface[]
     */
    public function getItems(): array;

    /**
     * Entity id to pass as after for the next page.
     *
     * Null when this page is the last one.
     *
     * @return int|null
     */
    public function getNextAfter(): ?int;

    /**
     * Sets the products of this page.
     *
     * @param \DDTCoreX\DagsterBridge\Api\Data\ProductIndexItemInterface[] $items
     * @return ProductIndexPageInterface
     */
    public function setItems(array $items): ProductIndexPageInterface;

    /**
     * Sets the cursor of the next page.
     *
     * @param int|null $nextAfter
     * @return ProductIndexPageInterface
     */
    public function setNextAfter(?int $nextAfter): ProductIndexPageInterface;
}
