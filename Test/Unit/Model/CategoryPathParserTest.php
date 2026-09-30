<?php
/**
 * Copyright (c) 2026 DDTCoreX
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace DDTCoreX\DagsterBridge\Test\Unit\Model;

use DDTCoreX\DagsterBridge\Model\CategoryPathParser;
use PHPUnit\Framework\TestCase;

class CategoryPathParserTest extends TestCase
{
    /**
     * @var CategoryPathParser
     */
    private $parser;

    protected function setUp(): void
    {
        $this->parser = new CategoryPathParser();
    }

    public function testParsePrependsTheRootAndSplitsOnTheSeparator(): void
    {
        self::assertSame(
            ['Default Category', 'Men', 'Tops', 'Hoodies'],
            $this->parser->parse('Men/Tops/Hoodies', 'Default Category', '/')
        );
    }

    public function testParseKeepsAPathThatAlreadyStartsWithTheRoot(): void
    {
        self::assertSame(
            ['Default Category', 'Men'],
            $this->parser->parse('Default Category/Men', 'Default Category', '/')
        );
    }

    public function testParseUsesTheCallerSeparatorAndKeepsSlashesInNames(): void
    {
        self::assertSame(
            ['Default Category', 'Tops/Tees'],
            $this->parser->parse('Default Category|Tops/Tees', 'Default Category', '|')
        );
    }

    public function testParseIgnoresEmptyLevelsAndSurroundingSpace(): void
    {
        self::assertSame(
            ['Default Category', 'Men', 'Tops'],
            $this->parser->parse(' Default Category // Men / Tops ', 'Default Category', '/')
        );
    }

    public function testParseRecognisesTheRootCaseInsensitivelyAsTheNativeProcessorDoes(): void
    {
        // the native processor matches names case-insensitively, so a path
        // that starts with the root in another case already starts with it
        self::assertSame(
            ['default category', 'Men'],
            $this->parser->parse('default category/Men', 'Default Category', '/')
        );
    }
}
