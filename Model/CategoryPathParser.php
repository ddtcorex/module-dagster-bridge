<?php
/**
 * Copyright (c) 2026 DDTCoreX
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace DDTCoreX\DagsterBridge\Model;

/**
 * Splits a category path into the names from the root down.
 *
 * Pure string handling, so a unit test can pin it without a database.
 */
class CategoryPathParser
{
    /**
     * Returns the level names of one path, root first.
     *
     * A path that does not start with the root gets the root prepended, and
     * empty levels are dropped, so both "Men/Tops" and "Default Category/Men"
     * describe the same branch. The root is recognised case-insensitively, as
     * the native category processor compares every name.
     *
     * @param string $path
     * @param string $root
     * @param string $separator
     * @return string[]
     */
    public function parse(string $path, string $root, string $separator): array
    {
        $levels = [];
        foreach (explode($separator, $path) as $level) {
            $level = trim($level);
            if ($level !== '') {
                $levels[] = $level;
            }
        }

        if ($levels === [] || mb_strtolower($levels[0]) !== mb_strtolower(trim($root))) {
            array_unshift($levels, trim($root));
        }

        return $levels;
    }
}
