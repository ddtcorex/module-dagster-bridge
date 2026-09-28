<?php
/**
 * Copyright (c) 2026 DDTCoreX
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace DDTCoreX\DagsterBridge\Api;

use DDTCoreX\DagsterBridge\Api\Data\CapabilitiesResultInterface;

/**
 * Reports what this module can do, so a client can fall back per capability
 * instead of treating the bridge as all or nothing.
 *
 * @api
 */
interface CapabilitiesInterface
{
    /**
     * Module version and the capabilities this release exposes.
     *
     * @return CapabilitiesResultInterface
     */
    public function get(): CapabilitiesResultInterface;
}
