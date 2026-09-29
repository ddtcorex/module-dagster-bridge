<?php
/**
 * Copyright (c) 2026 DDTCoreX
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace DDTCoreX\DagsterBridge\Model;

use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Framework\ObjectManagerInterface;

/**
 * Builds the category object the repository saves.
 *
 * Magento's own CategoryFactory is a generated class, so it exists only after
 * the application has produced it once. This module ships its own so a
 * standalone install, its unit tests and static analysis can see it; the object
 * manager resolves the interface through the application's own preference.
 */
class CategoryFactory
{
    /**
     * Object manager used to build the category.
     *
     * @var ObjectManagerInterface
     */
    private $objectManager;

    /**
     * @param ObjectManagerInterface $objectManager
     */
    public function __construct(ObjectManagerInterface $objectManager)
    {
        $this->objectManager = $objectManager;
    }

    /**
     * Builds an empty category the repository can save.
     *
     * @return CategoryInterface
     */
    public function create(): CategoryInterface
    {
        /** @var CategoryInterface $category */
        $category = $this->objectManager->create(CategoryInterface::class);

        return $category;
    }
}
