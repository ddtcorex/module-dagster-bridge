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
use PHPUnit\Framework\TestCase;

class CapabilitiesTest extends TestCase
{
    /**
     * Builds the model over a factory that hands back a real result.
     *
     * @return Capabilities
     */
    private function makeModel(): Capabilities
    {
        $factory = $this->createMock(CapabilitiesResultFactory::class);
        $factory->expects($this->once())
            ->method('create')
            ->willReturn(new CapabilitiesResult());

        return new Capabilities($factory);
    }

    public function testReturnsTheModuleVersionAndTheReleasedCapabilities(): void
    {
        $result = $this->makeModel()->get();

        self::assertSame(Capabilities::VERSION, $result->getVersion());
        self::assertSame(
            ['products.index', 'products.attribute_values', 'categories.upsert'],
            $result->getCapabilities()
        );
    }

    public function testVersionIsTheTopReleaseHeadingOfTheChangelog(): void
    {
        // the release checklist bumps both together; this test fails the
        // build when one of them is forgotten
        $changelog = (string) file_get_contents(__DIR__ . '/../../../CHANGELOG.md');
        self::assertSame(1, preg_match('/^## \[(\d+\.\d+\.\d+)\]/m', $changelog, $match));

        self::assertSame($match[1], Capabilities::VERSION);
    }

    public function testComposerJsonCarriesNoVersionField(): void
    {
        // Composer skips a VCS tag whose version disagrees with this field,
        // so the version lives in the tag and in Capabilities::VERSION only
        $composer = json_decode((string) file_get_contents(__DIR__ . '/../../../composer.json'), true);

        self::assertIsArray($composer);
        self::assertArrayNotHasKey('version', $composer);
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
