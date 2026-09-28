<?php
/**
 * Copyright (c) 2026 DDTCoreX
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace DDTCoreX\DagsterBridge\Model\Data;

use DDTCoreX\DagsterBridge\Api\Data\CapabilitiesResultInterface;
use Magento\Framework\Api\AbstractSimpleObject;

/**
 * Carries the answer of the capabilities endpoint.
 */
class CapabilitiesResult extends AbstractSimpleObject implements CapabilitiesResultInterface
{
    /**
     * Module version.
     *
     * @return string
     */
    public function getVersion(): string
    {
        return (string) $this->_get(self::VERSION);
    }

    /**
     * Capability identifiers.
     *
     * @return string[]
     */
    public function getCapabilities(): array
    {
        return (array) $this->_get(self::CAPABILITIES);
    }

    /**
     * Sets the module version.
     *
     * @param string $version
     * @return CapabilitiesResultInterface
     */
    public function setVersion(string $version): CapabilitiesResultInterface
    {
        return $this->setData(self::VERSION, $version);
    }

    /**
     * Sets the capability identifiers.
     *
     * @param string[] $capabilities
     * @return CapabilitiesResultInterface
     */
    public function setCapabilities(array $capabilities): CapabilitiesResultInterface
    {
        return $this->setData(self::CAPABILITIES, array_values($capabilities));
    }
}
