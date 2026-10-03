<?php
/**
 * Copyright (c) 2026 DDTCoreX
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace DDTCoreX\DagsterBridge\Api\Data;

/**
 * Answer of the capabilities endpoint.
 *
 * @api
 */
interface CapabilitiesResultInterface
{
    public const VERSION = 'version';
    public const CAPABILITIES = 'capabilities';

    /**
     * Version of this module, the Capabilities::VERSION constant (composer.json carries no version field).
     *
     * @return string
     */
    public function getVersion(): string;

    /**
     * Capability identifiers this release exposes, for example "products.index".
     *
     * @return string[]
     */
    public function getCapabilities(): array;

    /**
     * Set the module version.
     *
     * @param string $version
     * @return CapabilitiesResultInterface
     */
    public function setVersion(string $version): CapabilitiesResultInterface;

    /**
     * Set the capability identifiers.
     *
     * @param string[] $capabilities
     * @return CapabilitiesResultInterface
     */
    public function setCapabilities(array $capabilities): CapabilitiesResultInterface;
}
