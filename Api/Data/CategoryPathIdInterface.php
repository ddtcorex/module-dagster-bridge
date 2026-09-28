<?php
/**
 * Copyright (c) 2026 DDTCoreX
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace DDTCoreX\DagsterBridge\Api\Data;

/**
 * One requested category path and the id it resolved to.
 *
 * @api
 */
interface CategoryPathIdInterface
{
    public const PATH = 'path';
    public const ID = 'id';

    /**
     * The path exactly as it was requested.
     *
     * @return string
     */
    public function getPath(): string;

    /**
     * Id of the category the path names.
     *
     * @return int
     */
    public function getId(): int;

    /**
     * Sets the path.
     *
     * @param string $path
     * @return CategoryPathIdInterface
     */
    public function setPath(string $path): CategoryPathIdInterface;

    /**
     * Sets the category id.
     *
     * @param int $id
     * @return CategoryPathIdInterface
     */
    public function setId(int $id): CategoryPathIdInterface;
}
