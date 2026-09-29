<?php
/**
 * Copyright (c) 2026 DDTCoreX
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace DDTCoreX\DagsterBridge\Model\Data;

use DDTCoreX\DagsterBridge\Api\Data\ProductIndexItemInterface;
use DDTCoreX\DagsterBridge\Api\Data\ProductIndexPageInterface;
use Magento\Framework\Api\AbstractSimpleObject;

/**
 * One page of the product index.
 */
class ProductIndexPage extends AbstractSimpleObject implements ProductIndexPageInterface
{
    /**
     * Products of this page.
     *
     * @return \DDTCoreX\DagsterBridge\Api\Data\ProductIndexItemInterface[]
     */
    public function getItems(): array
    {
        return (array) $this->_get(self::ITEMS);
    }

    /**
     * Cursor of the next page.
     *
     * @return int|null
     */
    public function getNextAfter(): ?int
    {
        $nextAfter = $this->_get(self::NEXT_AFTER);

        return $nextAfter === null ? null : (int) $nextAfter;
    }

    /**
     * Sets the products of this page.
     *
     * @param \DDTCoreX\DagsterBridge\Api\Data\ProductIndexItemInterface[] $items
     * @return ProductIndexPageInterface
     */
    public function setItems(array $items): ProductIndexPageInterface
    {
        return $this->setData(self::ITEMS, array_values($items));
    }

    /**
     * Sets the cursor of the next page.
     *
     * @param int|null $nextAfter
     * @return ProductIndexPageInterface
     */
    public function setNextAfter(?int $nextAfter): ProductIndexPageInterface
    {
        return $this->setData(self::NEXT_AFTER, $nextAfter);
    }
}
