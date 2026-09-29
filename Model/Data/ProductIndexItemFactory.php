<?php
/**
 * Copyright (c) 2026 DDTCoreX
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace DDTCoreX\DagsterBridge\Model\Data;

use Magento\Framework\ObjectManagerInterface;

/**
 * Factory for one index item.
 *
 * Magento would generate this class into generated/code, but a generated
 * class only exists after the application has produced it once. This module
 * ships the few lines itself so its unit tests, PHPStan run and CI do not
 * depend on Magento's code generator having run first.
 */
class ProductIndexItemFactory
{
    /**
     * Object manager used to build the object.
     *
     * @var ObjectManagerInterface
     */
    private $objectManager;

    /**
     * Concrete class to build.
     *
     * @var string
     */
    private $instanceName;

    /**
     * @param ObjectManagerInterface $objectManager
     * @param string $instanceName
     */
    public function __construct(
        ObjectManagerInterface $objectManager,
        string $instanceName = ProductIndexItem::class
    ) {
        $this->objectManager = $objectManager;
        $this->instanceName = $instanceName;
    }

    /**
     * Builds one object.
     *
     * @param array $data
     * @phpstan-param array<string, mixed> $data
     * @return ProductIndexItem
     */
    public function create(array $data = []): ProductIndexItem
    {
        /** @var ProductIndexItem $result */
        $result = $this->objectManager->create($this->instanceName, $data);

        return $result;
    }
}
