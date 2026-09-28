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
use InvalidArgumentException;
use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Component\ComponentRegistrarInterface;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Serialize\Serializer\Json;

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
     * Capabilities this release exposes, one entry per finished endpoint.
     */
    private const CAPABILITIES = ['products.index'];

    /**
     * Factory of the answer object.
     *
     * @var CapabilitiesResultFactory
     */
    private $resultFactory;

    /**
     * Resolves the module directory, wherever the module is installed.
     *
     * @var ComponentRegistrarInterface
     */
    private $componentRegistrar;

    /**
     * JSON serializer.
     *
     * @var Json
     */
    private $json;

    /**
     * Filesystem driver used to read the module's own composer.json.
     *
     * @var File
     */
    private $fileDriver;

    /**
     * @param CapabilitiesResultFactory $resultFactory
     * @param ComponentRegistrarInterface $componentRegistrar
     * @param Json $json
     * @param File $fileDriver
     */
    public function __construct(
        CapabilitiesResultFactory $resultFactory,
        ComponentRegistrarInterface $componentRegistrar,
        Json $json,
        File $fileDriver
    ) {
        $this->resultFactory = $resultFactory;
        $this->componentRegistrar = $componentRegistrar;
        $this->json = $json;
        $this->fileDriver = $fileDriver;
    }

    /**
     * Module version and the capabilities this release exposes.
     *
     * @return CapabilitiesResultInterface
     */
    public function get(): CapabilitiesResultInterface
    {
        $result = $this->resultFactory->create();
        $result->setVersion($this->readVersion());
        $result->setCapabilities(self::CAPABILITIES);

        return $result;
    }

    /**
     * Reads the version from this module's own composer.json.
     *
     * Works whether the module sits in app/code or was installed as a
     * composer package, because the registrar knows both. An unreadable or
     * malformed file answers an empty version instead of failing the call.
     *
     * @return string
     */
    private function readVersion(): string
    {
        $moduleDir = $this->componentRegistrar->getPath(ComponentRegistrar::MODULE, self::MODULE_NAME);
        if ($moduleDir === null) {
            return '';
        }

        $composerJson = $moduleDir . '/composer.json';
        if (!$this->fileDriver->isExists($composerJson)) {
            return '';
        }

        try {
            $contents = $this->fileDriver->fileGetContents($composerJson);
        } catch (FileSystemException $exception) {
            return '';
        }

        try {
            $data = $this->json->unserialize($contents);
        } catch (InvalidArgumentException $exception) {
            return '';
        }

        return is_array($data) && isset($data['version']) ? (string) $data['version'] : '';
    }
}
