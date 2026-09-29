<?php
/**
 * Copyright (c) 2026 DDTCoreX
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace DDTCoreX\DagsterBridge\Api;

use Magento\Framework\Exception\InputException;

/**
 * Creates the categories a path names, and answers with the id of each one.
 *
 * The only endpoint of this module that writes. It is a POST because it
 * carries a list, and it runs in one transaction: a failure leaves the
 * category tree exactly as it was.
 *
 * @api
 */
interface CategoryUpsertInterface
{
    /**
     * Name of the root category the paths hang from by default.
     */
    public const DEFAULT_ROOT = 'Default Category';

    /**
     * Every requested path comes back with its category id.
     *
     * A path that already exists is not created again, and a path created by
     * an earlier path of the same call is reused.
     *
     * @param string[] $paths Category paths, each one starting at the root.
     * @param string $root Name of the root category the paths hang from.
     * @param string $separator Level separator inside the paths, which must not appear in any name.
     * @return \DDTCoreX\DagsterBridge\Api\Data\CategoryPathIdInterface[]
     * @throws InputException
     */
    public function upsert(
        array $paths,
        string $root = self::DEFAULT_ROOT,
        string $separator = '/'
    ): array;
}
