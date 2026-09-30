<?php
/**
 * Copyright (c) 2026 DDTCoreX
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace DDTCoreX\DagsterBridge\Model;

use DDTCoreX\DagsterBridge\Api\CapabilitiesInterface;
use DDTCoreX\DagsterBridge\Api\Data\CapabilitiesResultInterface;
use DDTCoreX\DagsterBridge\Model\Data\CapabilitiesResultFactory;

/**
 * Answers what this module can do.
 *
 * A client probes this once per run and falls back to plain REST for every
 * capability the list does not carry, so a bridge older than the client
 * degrades instead of failing.
 */
class Capabilities implements CapabilitiesInterface
{
    /**
     * Module name, as registered in registration.php.
     */
    public const MODULE_NAME = 'DDTCoreX_DagsterBridge';

    /**
     * Version of this release.
     *
     * It is the top release heading of CHANGELOG.md, which a unit test
     * enforces, and the tag the release is cut from. composer.json carries no
     * version field, because Composer ignores a VCS tag that disagrees with it.
     */
    public const VERSION = '1.0.0';

    /**
     * Capabilities this release exposes, one entry per finished endpoint.
     */
    private const CAPABILITIES = ['products.index', 'products.attribute_values', 'categories.upsert'];

    /**
     * Factory of the answer object.
     *
     * @var CapabilitiesResultFactory
     */
    private $resultFactory;

    /**
     * @param CapabilitiesResultFactory $resultFactory
     */
    public function __construct(CapabilitiesResultFactory $resultFactory)
    {
        $this->resultFactory = $resultFactory;
    }

    /**
     * Module version and the capabilities this release exposes.
     *
     * @return CapabilitiesResultInterface
     */
    public function get(): CapabilitiesResultInterface
    {
        $result = $this->resultFactory->create();
        $result->setVersion(self::VERSION);
        $result->setCapabilities(self::CAPABILITIES);

        return $result;
    }
}
