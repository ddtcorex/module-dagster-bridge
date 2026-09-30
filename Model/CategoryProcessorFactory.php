<?php
/**
 * Copyright (c) 2026 DDTCoreX
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace DDTCoreX\DagsterBridge\Model;

use Magento\CatalogImportExport\Model\Import\Product\CategoryProcessor;
use Magento\Framework\ObjectManagerInterface;

/**
 * Builds a fresh native category processor.
 *
 * The processor loads the whole category tree in its constructor and keeps
 * it, so a shared instance would answer from the tree as it was when it was
 * built. The upsert builds one per call, after it holds its lock. Magento's own
 * generated factory would do the same, but it exists only once the application
 * has generated it, so this module ships its own for standalone installs, its
 * unit tests and static analysis.
 */
class CategoryProcessorFactory
{
    /**
     * Object manager used to build the processor.
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
     * Builds a processor that reads the tree as it is now.
     *
     * @return CategoryProcessor
     */
    public function create(): CategoryProcessor
    {
        /** @var CategoryProcessor $processor */
        $processor = $this->objectManager->create(CategoryProcessor::class);

        return $processor;
    }
}
