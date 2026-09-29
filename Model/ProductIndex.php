<?php
/**
 * Copyright (c) 2026 DDTCoreX
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace DDTCoreX\DagsterBridge\Model;

use DDTCoreX\DagsterBridge\Api\Data\ProductIndexItemInterface;
use DDTCoreX\DagsterBridge\Api\Data\ProductIndexPageInterface;
use DDTCoreX\DagsterBridge\Api\ProductIndexInterface;
use DDTCoreX\DagsterBridge\Model\Data\ProductIndexItemFactory;
use DDTCoreX\DagsterBridge\Model\Data\ProductIndexPageFactory;
use DDTCoreX\DagsterBridge\Model\ResourceModel\ProductIndexQuery;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\InputException;

/**
 * Reads the product index one keyset page at a time.
 */
class ProductIndex implements ProductIndexInterface
{
    /**
     * Connection the query runs on.
     *
     * @var ResourceConnection
     */
    private $resource;

    /**
     * Query builder.
     *
     * @var ProductIndexQuery
     */
    private $query;

    /**
     * Factory of one index item.
     *
     * @var ProductIndexItemFactory
     */
    private $itemFactory;

    /**
     * Factory of the page object.
     *
     * @var ProductIndexPageFactory
     */
    private $pageFactory;

    /**
     * @param ResourceConnection $resource
     * @param ProductIndexQuery $query
     * @param ProductIndexItemFactory $itemFactory
     * @param ProductIndexPageFactory $pageFactory
     */
    public function __construct(
        ResourceConnection $resource,
        ProductIndexQuery $query,
        ProductIndexItemFactory $itemFactory,
        ProductIndexPageFactory $pageFactory
    ) {
        $this->resource = $resource;
        $this->query = $query;
        $this->itemFactory = $itemFactory;
        $this->pageFactory = $pageFactory;
    }

    /**
     * Returns one page of the product index.
     *
     * @param int $after
     * @param int $limit
     * @return ProductIndexPageInterface
     * @throws InputException
     */
    public function getPage(int $after = 0, int $limit = self::DEFAULT_LIMIT): ProductIndexPageInterface
    {
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new InputException(
                __('The limit must be between 1 and %1.', self::MAX_LIMIT)
            );
        }

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $this->resource->getConnection()->fetchAll($this->query->build($after, $limit));

        $items = [];
        foreach ($rows as $row) {
            $item = $this->itemFactory->create();
            $item->setEntityId((int) $row[ProductIndexItemInterface::ENTITY_ID]);
            $item->setSku((string) $row[ProductIndexItemInterface::SKU]);
            $item->setTypeId((string) $row[ProductIndexItemInterface::TYPE_ID]);
            $item->setAttributeSetId((int) $row[ProductIndexItemInterface::ATTRIBUTE_SET_ID]);
            $item->setUpdatedAt((string) $row[ProductIndexItemInterface::UPDATED_AT]);
            $item->setStatus(
                $row[ProductIndexItemInterface::STATUS] === null
                    ? null
                    : (int) $row[ProductIndexItemInterface::STATUS]
            );
            $items[] = $item;
        }

        $page = $this->pageFactory->create();
        $page->setItems($items);
        $page->setNextAfter(
            count($items) === $limit ? $items[count($items) - 1]->getEntityId() : null
        );

        return $page;
    }
}
