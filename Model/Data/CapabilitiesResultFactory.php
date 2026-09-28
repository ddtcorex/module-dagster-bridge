<?php
/**
 * Copyright (c) 2026 DDTCoreX
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace DDTCoreX\DagsterBridge\Model\Data;

use Magento\Framework\ObjectManagerInterface;

/**
 * Factory for the capabilities answer.
 *
 * Magento would generate this class into generated/code, but a generated
 * class only exists after the application has produced it once. This module
 * ships the few lines itself so its unit tests, PHPStan run and CI do not
 * depend on Magento's code generator having run first.
 */
class CapabilitiesResultFactory
{
    /**
     * Object manager used to build the answer.
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
        string $instanceName = CapabilitiesResult::class
    ) {
        $this->objectManager = $objectManager;
        $this->instanceName = $instanceName;
    }

    /**
     * Builds one answer object.
     *
     * @param array $data
     * @phpstan-param array<string, mixed> $data
     * @return CapabilitiesResult
     */
    public function create(array $data = []): CapabilitiesResult
    {
        /** @var CapabilitiesResult $result */
        $result = $this->objectManager->create($this->instanceName, $data);

        return $result;
    }
}
