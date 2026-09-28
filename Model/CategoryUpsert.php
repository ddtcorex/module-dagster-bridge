<?php
/**
 * Copyright (c) 2026 DDTCoreX
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace DDTCoreX\DagsterBridge\Model;

use DDTCoreX\DagsterBridge\Api\CategoryUpsertInterface;
use DDTCoreX\DagsterBridge\Api\Data\CategoryPathIdInterface;
use DDTCoreX\DagsterBridge\Model\Data\CategoryPathIdFactory;
use Exception;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\CatalogImportExport\Model\Import\Product\CategoryProcessor;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\InputException;

/**
 * Creates the categories a path names.
 *
 * The native CatalogImportExport processor does the work when it can, because
 * it already knows how Magento wants a category tree built. It cannot express a
 * name that contains its own level separator, so such a call walks the tree
 * through the category repository instead, parent first. Either way the whole
 * call runs in one transaction.
 */
class CategoryUpsert implements CategoryUpsertInterface
{
    /**
     * The root above every store root, whose children are the tree roots.
     */
    private const INVISIBLE_ROOT_ID = 1;

    /**
     * Delimiters that can separate paths in one processor call.
     */
    private const PATH_DELIMITERS = [',', ';', '|', '~', '^'];

    /**
     * Turns a path string into level names.
     *
     * @var CategoryPathParser
     */
    private $parser;

    /**
     * Native category processor.
     *
     * @var CategoryProcessor
     */
    private $processor;

    /**
     * Repository used when the processor cannot express a name.
     *
     * @var CategoryRepositoryInterface
     */
    private $categoryRepository;

    /**
     * Builder of the categories created through the repository.
     *
     * @var CategoryFactory
     */
    private $categoryFactory;

    /**
     * Factory of one path answer.
     *
     * @var CategoryPathIdFactory
     */
    private $itemFactory;

    /**
     * Connection the transaction runs on.
     *
     * @var ResourceConnection
     */
    private $resource;

    /**
     * Path the current work belongs to, for error messages.
     *
     * @var string
     */
    private $currentPath = '';

    /**
     * @param CategoryPathParser $parser
     * @param CategoryProcessor $processor
     * @param CategoryRepositoryInterface $categoryRepository
     * @param CategoryFactory $categoryFactory
     * @param CategoryPathIdFactory $itemFactory
     * @param ResourceConnection $resource
     */
    public function __construct(
        CategoryPathParser $parser,
        CategoryProcessor $processor,
        CategoryRepositoryInterface $categoryRepository,
        CategoryFactory $categoryFactory,
        CategoryPathIdFactory $itemFactory,
        ResourceConnection $resource
    ) {
        $this->parser = $parser;
        $this->processor = $processor;
        $this->categoryRepository = $categoryRepository;
        $this->categoryFactory = $categoryFactory;
        $this->itemFactory = $itemFactory;
        $this->resource = $resource;
    }

    /**
     * Creates the missing categories and answers every requested path.
     *
     * @param string[] $paths
     * @param string $root
     * @param string $separator
     * @return \DDTCoreX\DagsterBridge\Api\Data\CategoryPathIdInterface[]
     * @throws InputException
     */
    public function upsert(
        array $paths,
        string $root = self::DEFAULT_ROOT,
        string $separator = '/'
    ): array {
        if ($paths === []) {
            return [];
        }

        $connection = $this->resource->getConnection();
        $connection->beginTransaction();

        try {
            $ids = $this->needsRepository($paths, $root, $separator)
                ? $this->upsertThroughRepository($paths, $root, $separator)
                : $this->upsertThroughProcessor($paths, $root, $separator);

            $items = [];
            foreach ($paths as $path) {
                $this->currentPath = $path;
                if (!isset($ids[$path])) {
                    throw new InputException(__('Category path "%1" was not resolved.', $path));
                }

                $item = $this->itemFactory->create();
                $item->setPath($path);
                $item->setId((int) $ids[$path]);
                $items[] = $item;
            }

            $connection->commit();

            return $items;
        } catch (InputException $exception) {
            $connection->rollBack();

            throw $exception;
        } catch (Exception $exception) {
            $connection->rollBack();

            throw new InputException(
                __('Category path "%1" could not be created: %2', $this->currentPath, $exception->getMessage()),
                $exception
            );
        }
    }

    /**
     * Tells whether a name contains the processor's own level separator.
     *
     * @param string[] $paths
     * @param string $root
     * @param string $separator
     * @return bool
     */
    private function needsRepository(array $paths, string $root, string $separator): bool
    {
        foreach ($paths as $path) {
            foreach ($this->parser->parse($path, $root, $separator) as $name) {
                if (strpos($name, '/') !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Creates the paths with the native processor.
     *
     * @param string[] $paths
     * @param string $root
     * @param string $separator
     * @return array Path => category id.
     * @phpstan-return array<string, int>
     * @throws InputException
     */
    private function upsertThroughProcessor(array $paths, string $root, string $separator): array
    {
        $levelSets = [];
        $joinedPaths = [];
        foreach ($paths as $path) {
            $levels = $this->parser->parse($path, $root, $separator);
            $levelSets[] = $levels;
            $joinedPaths[] = implode('/', $levels);
        }

        $delimiter = $this->pickPathDelimiter($levelSets);
        $ids = $this->processor->upsertCategories(implode($delimiter, $joinedPaths), $delimiter);

        $failures = $this->processor->getFailedCategories();
        if ($failures !== []) {
            $this->processor->clearFailedCategories();
            $failed = (string) ($failures[0]['category'] ?? '');
            throw new InputException(
                __('Category path "%1" could not be created by the native processor.', $failed)
            );
        }

        if (count($ids) !== count($paths)) {
            throw new InputException(
                __('The native processor answered %1 ids for %2 paths.', count($ids), count($paths))
            );
        }

        $resolved = [];
        foreach (array_values($paths) as $index => $path) {
            $resolved[$path] = (int) $ids[$index];
        }

        return $resolved;
    }

    /**
     * Creates the paths through the category repository, parent first.
     *
     * @param string[] $paths
     * @param string $root
     * @param string $separator
     * @return array Path => category id.
     * @phpstan-return array<string, int>
     * @throws InputException
     */
    private function upsertThroughRepository(array $paths, string $root, string $separator): array
    {
        $rootId = $this->findChildId(self::INVISIBLE_ROOT_ID, $root);
        if ($rootId === null) {
            throw new InputException(__('The root category "%1" does not exist.', $root));
        }

        $resolved = [];
        foreach ($paths as $path) {
            $this->currentPath = $path;
            $parentId = $rootId;

            foreach (array_slice($this->parser->parse($path, $root, $separator), 1) as $name) {
                $childId = $this->findChildId($parentId, $name);
                if ($childId === null) {
                    $category = $this->categoryFactory->create();
                    $category->setName($name);
                    $category->setParentId($parentId);
                    $category->setIsActive(true);
                    $childId = (int) $this->categoryRepository->save($category)->getId();
                }
                $parentId = $childId;
            }

            $resolved[$path] = $parentId;
        }

        return $resolved;
    }

    /**
     * Finds a child of the given parent by name.
     *
     * @param int $parentId
     * @param string $name
     * @return int|null
     */
    private function findChildId(int $parentId, string $name): ?int
    {
        $children = (string) $this->categoryRepository->get($parentId)->getChildren();
        foreach (explode(',', $children) as $childId) {
            $childId = (int) trim($childId);
            if ($childId === 0) {
                continue;
            }
            if ((string) $this->categoryRepository->get($childId)->getName() === $name) {
                return $childId;
            }
        }

        return null;
    }

    /**
     * Picks a path delimiter that appears in none of the level names.
     *
     * @param array $levelSets
     * @return string
     * @phpstan-param array<int, array<int, string>> $levelSets
     * @throws InputException
     */
    private function pickPathDelimiter(array $levelSets): string
    {
        foreach (self::PATH_DELIMITERS as $delimiter) {
            $used = false;
            foreach ($levelSets as $levels) {
                foreach ($levels as $name) {
                    if (strpos($name, $delimiter) !== false) {
                        $used = true;
                        break 2;
                    }
                }
            }
            if (!$used) {
                return $delimiter;
            }
        }

        throw new InputException(__('None of the supported path delimiters is free of the requested names.'));
    }
}
