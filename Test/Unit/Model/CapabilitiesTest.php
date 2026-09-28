<?php
/**
 * Copyright (c) 2026 DDTCoreX
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace DDTCoreX\DagsterBridge\Test\Unit\Model;

use DDTCoreX\DagsterBridge\Model\Capabilities;
use DDTCoreX\DagsterBridge\Model\Data\CapabilitiesResult;
use DDTCoreX\DagsterBridge\Model\Data\CapabilitiesResultFactory;
use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Component\ComponentRegistrarInterface;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;

class CapabilitiesTest extends TestCase
{
    /**
     * Temporary module directory the tests write a composer.json into.
     *
     * @var string
     */
    private $moduleDir;

    protected function setUp(): void
    {
        $this->moduleDir = sys_get_temp_dir() . '/dagster-bridge-' . uniqid('', true);
        mkdir($this->moduleDir);
    }

    protected function tearDown(): void
    {
        $composerJson = $this->moduleDir . '/composer.json';
        if (is_file($composerJson)) {
            unlink($composerJson);
        }
        if (is_dir($this->moduleDir)) {
            rmdir($this->moduleDir);
        }
    }

    /**
     * Writes the module's own composer.json.
     *
     * @param string $composerJson
     * @return void
     */
    private function writeComposerJson(string $composerJson): void
    {
        file_put_contents($this->moduleDir . '/composer.json', $composerJson);
    }

    /**
     * Builds the model with a mocked registrar and a real filesystem driver.
     *
     * @param string|null $moduleDir
     * @return Capabilities
     */
    private function makeModel(?string $moduleDir): Capabilities
    {
        $registrar = $this->createMock(ComponentRegistrarInterface::class);
        $registrar->expects($this->once())
            ->method('getPath')
            ->with(ComponentRegistrar::MODULE, Capabilities::MODULE_NAME)
            ->willReturn($moduleDir);

        $factory = $this->createMock(CapabilitiesResultFactory::class);
        $factory->expects($this->once())
            ->method('create')
            ->willReturn(new CapabilitiesResult());

        return new Capabilities($factory, $registrar, new Json(), new File());
    }

    public function testReturnsVersionFromComposerJsonAndEmptyCapabilities(): void
    {
        $this->writeComposerJson((string) json_encode([
            'name' => 'ddtcorex/module-dagster-bridge',
            'version' => '1.2.3',
        ]));

        $result = $this->makeModel($this->moduleDir)->get();

        self::assertSame('1.2.3', $result->getVersion());
        self::assertSame([], $result->getCapabilities());
    }

    public function testVersionIsEmptyWhenTheModuleIsNotRegistered(): void
    {
        $this->writeComposerJson((string) json_encode(['version' => '1.2.3']));

        self::assertSame('', $this->makeModel(null)->get()->getVersion());
    }

    public function testVersionIsEmptyWhenComposerJsonHasNoVersion(): void
    {
        $this->writeComposerJson((string) json_encode(['name' => 'ddtcorex/module-dagster-bridge']));

        self::assertSame('', $this->makeModel($this->moduleDir)->get()->getVersion());
    }

    public function testVersionIsEmptyWhenComposerJsonIsBroken(): void
    {
        file_put_contents($this->moduleDir . '/composer.json', '{"version": ');

        self::assertSame('', $this->makeModel($this->moduleDir)->get()->getVersion());
    }

    public function testResultCarriesTheKeysTheEndpointPublishes(): void
    {
        $result = new CapabilitiesResult();
        $result->setVersion('1.0.0');
        $result->setCapabilities(['products.index']);

        self::assertSame(
            ['version' => '1.0.0', 'capabilities' => ['products.index']],
            $result->__toArray()
        );
    }
}
