<?php
/**
 * Copyright (c) 2026 DDTCoreX
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace DDTCoreX\DagsterBridge\Model\Data;

use DDTCoreX\DagsterBridge\Api\Data\CategoryPathIdInterface;
use Magento\Framework\Api\AbstractSimpleObject;

/**
 * One requested category path and the id it resolved to.
 */
class CategoryPathId extends AbstractSimpleObject implements CategoryPathIdInterface
{
    /**
     * The path as it was requested.
     *
     * @return string
     */
    public function getPath(): string
    {
        return (string) $this->_get(self::PATH);
    }

    /**
     * Id of the category the path names.
     *
     * @return int
     */
    public function getId(): int
    {
        return (int) $this->_get(self::ID);
    }

    /**
     * Sets the path.
     *
     * @param string $path
     * @return CategoryPathIdInterface
     */
    public function setPath(string $path): CategoryPathIdInterface
    {
        return $this->setData(self::PATH, $path);
    }

    /**
     * Sets the category id.
     *
     * @param int $id
     * @return CategoryPathIdInterface
     */
    public function setId(int $id): CategoryPathIdInterface
    {
        return $this->setData(self::ID, $id);
    }
}
