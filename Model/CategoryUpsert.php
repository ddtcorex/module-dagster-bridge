<?php
/**
 * Copyright (c) 2026 DDTCoreX
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace DDTCoreX\DagsterBridge\Model;

use DDTCoreX\DagsterBridge\Api\CategoryUpsertInterface;
use DDTCoreX\DagsterBridge\Model\Data\CategoryPathIdFactory;
use Exception;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Model\ResourceModel\Category as CategoryResource;
use Magento\CatalogImportExport\Model\Import\Product\CategoryProcessor;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\Webapi\Exception as WebapiException;
use Magento\Store\Model\Store;
use Throwable;

/**
 * Creates the categories a path names.
 *
 * Every path goes through the native CatalogImportExport category processor,
 * so this endpoint builds the tree exactly as a native product import would:
 * names match case-insensitively, an existing category is reused whether or not
 * it is active, and new categories are written at the admin store (store 0). A
 * slash inside a name is escaped as "\/", the processor's own quoting.
 *
 * The whole call runs in one transaction, owned by the category resource model
 * so that the after commit callbacks each saved category registers run on
 * commit and are dropped on rollback. Calls are serialised by a named lock, so
 * two concurrent calls that create the same new path never both create it: the
 * second one waits, then finds what the first one committed.
 */
class CategoryUpsert implements CategoryUpsertInterface
{
    /**
     * Name of the lock every upsert holds while it reads and writes the tree.
     */
    public const LOCK_NAME = 'dagster_bridge_category_upsert';

    /**
     * Seconds a call waits for the lock before it answers 503.
     */
    public const LOCK_TIMEOUT = 15;

    /**
     * HTTP status of a call that could not get the lock in time.
     */
    private const HTTP_SERVICE_UNAVAILABLE = 503;

    /**
     * The root above every store root, whose children are the tree roots.
     */
    private const INVISIBLE_ROOT_ID = 1;

    /**
     * Level separator of the native processor.
     */
    private const PROCESSOR_SEPARATOR = CategoryProcessor::DELIMITER_CATEGORY;

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
     * Builds the native category processor once the lock is held.
     *
     * @var CategoryProcessorFactory
     */
    private $processorFactory;

    /**
     * Repository the root is looked up in.
     *
     * @var CategoryRepositoryInterface
     */
    private $categoryRepository;

    /**
     * Factory of one path answer.
     *
     * @var CategoryPathIdFactory
     */
    private $itemFactory;

    /**
     * Category resource model whose transaction the whole call runs in.
     *
     * @var CategoryResource
     */
    private $categoryResource;

    /**
     * Serialises concurrent upserts.
     *
     * @var LockManagerInterface
     */
    private $lockManager;

    /**
     * @param CategoryPathParser $parser
     * @param CategoryProcessorFactory $processorFactory
     * @param CategoryRepositoryInterface $categoryRepository
     * @param CategoryPathIdFactory $itemFactory
     * @param CategoryResource $categoryResource
     * @param LockManagerInterface $lockManager
     */
    public function __construct(
        CategoryPathParser $parser,
        CategoryProcessorFactory $processorFactory,
        CategoryRepositoryInterface $categoryRepository,
        CategoryPathIdFactory $itemFactory,
        CategoryResource $categoryResource,
        LockManagerInterface $lockManager
    ) {
        $this->parser = $parser;
        $this->processorFactory = $processorFactory;
        $this->categoryRepository = $categoryRepository;
        $this->itemFactory = $itemFactory;
        $this->categoryResource = $categoryResource;
        $this->lockManager = $lockManager;
    }

    /**
     * Creates the missing categories and answers every requested path.
     *
     * @param string[] $paths
     * @param string $root
     * @param string $separator
     * @return \DDTCoreX\DagsterBridge\Api\Data\CategoryPathIdInterface[]
     * @throws InputException
     * @throws WebapiException When another upsert holds the lock for too long.
     */
    public function upsert(
        array $paths,
        string $root = self::DEFAULT_ROOT,
        string $separator = '/'
    ): array {
        if ($separator === '') {
            throw new InputException(__('The separator must not be empty.'));
        }
        if ($paths === []) {
            return [];
        }

        $this->assertRootExists($root);
        $joinedPaths = $this->joinForProcessor($paths, $root, $separator);

        if (!$this->lockManager->lock(self::LOCK_NAME, self::LOCK_TIMEOUT)) {
            throw new WebapiException(
                __(
                    'Another category upsert is still running after %1 seconds; retry the call later.',
                    self::LOCK_TIMEOUT
                ),
                0,
                self::HTTP_SERVICE_UNAVAILABLE
            );
        }

        try {
            return $this->upsertUnderLock($paths, $joinedPaths);
        } finally {
            $this->lockManager->unlock(self::LOCK_NAME);
        }
    }

    /**
     * Runs the upsert in one transaction, once the lock is held.
     *
     * @param string[] $paths
     * @param string[] $joinedPaths
     * @return \DDTCoreX\DagsterBridge\Api\Data\CategoryPathIdInterface[]
     * @throws InputException
     */
    private function upsertUnderLock(array $paths, array $joinedPaths): array
    {
        // built here, not injected, so it reads the tree as the previous
        // holder of the lock committed it
        $processor = $this->processorFactory->create();

        $this->categoryResource->beginTransaction();

        try {
            $ids = $this->upsertThroughProcessor($processor, $joinedPaths);

            $items = [];
            foreach (array_values($paths) as $index => $path) {
                $item = $this->itemFactory->create();
                $item->setPath($path);
                $item->setId($ids[$index]);
                $items[] = $item;
            }

            $this->categoryResource->commit();

            return $items;
        } catch (Throwable $throwable) {
            // every failure rolls back, an Error as much as an Exception, so
            // no transaction is left open and no commit callback survives
            $this->categoryResource->rollBack();

            if ($throwable instanceof InputException || !$throwable instanceof Exception) {
                throw $throwable;
            }

            throw new InputException(
                __(
                    'Category path "%1" could not be created: %2',
                    implode('", "', $paths),
                    $throwable->getMessage()
                ),
                $throwable
            );
        }
    }

    /**
     * Rejects a root that is not a tree root already.
     *
     * The native processor would silently create an unknown root as a new
     * tree, so the root is checked first, case-insensitively as the processor
     * compares names, and at the admin store.
     *
     * @param string $root
     * @return void
     * @throws InputException
     */
    private function assertRootExists(string $root): void
    {
        $wanted = mb_strtolower(trim($root));
        $children = (string) $this->categoryRepository
            ->get(self::INVISIBLE_ROOT_ID, Store::DEFAULT_STORE_ID)
            ->getChildren();

        foreach (explode(',', $children) as $childId) {
            $childId = (int) trim($childId);
            if ($childId === 0) {
                continue;
            }
            $name = (string) $this->categoryRepository->get($childId, Store::DEFAULT_STORE_ID)->getName();
            if (mb_strtolower($name) === $wanted) {
                return;
            }
        }

        throw new InputException(__('The root category "%1" does not exist.', $root));
    }

    /**
     * Turns every path into the processor's own form, names escaped.
     *
     * @param string[] $paths
     * @param string $root
     * @param string $separator
     * @return string[] One processor path per requested path, same order.
     * @throws InputException
     */
    private function joinForProcessor(array $paths, string $root, string $separator): array
    {
        $joined = [];
        foreach ($paths as $path) {
            $names = [];
            foreach ($this->parser->parse($path, $root, $separator) as $name) {
                if (substr($name, -1) === '\\') {
                    throw new InputException(
                        __('Category name "%1" in path "%2" ends with a backslash, which the native '
                            . 'processor cannot store.', $name, $path)
                    );
                }
                $names[] = str_replace(self::PROCESSOR_SEPARATOR, '\\' . self::PROCESSOR_SEPARATOR, $name);
            }
            $joined[] = implode(self::PROCESSOR_SEPARATOR, $names);
        }

        return $joined;
    }

    /**
     * Creates the paths with the native processor.
     *
     * @param CategoryProcessor $processor
     * @param string[] $joinedPaths
     * @return int[] One category id per path, same order.
     * @throws InputException
     */
    private function upsertThroughProcessor(CategoryProcessor $processor, array $joinedPaths): array
    {
        $delimiter = $this->pickPathDelimiter($joinedPaths);
        $ids = $processor->upsertCategories(implode($delimiter, $joinedPaths), $delimiter);

        $failures = $processor->getFailedCategories();
        if ($failures !== []) {
            $processor->clearFailedCategories();
            $failed = (string) ($failures[0]['category'] ?? '');
            throw new InputException(
                __('Category path "%1" could not be created by the native processor.', $failed)
            );
        }

        if (count($ids) !== count($joinedPaths)) {
            throw new InputException(
                __('The native processor answered %1 ids for %2 paths.', count($ids), count($joinedPaths))
            );
        }

        return array_map('intval', array_values($ids));
    }

    /**
     * Picks a path delimiter that appears in none of the paths.
     *
     * @param string[] $joinedPaths
     * @return string
     * @throws InputException
     */
    private function pickPathDelimiter(array $joinedPaths): string
    {
        foreach (self::PATH_DELIMITERS as $delimiter) {
            foreach ($joinedPaths as $path) {
                if (strpos($path, $delimiter) !== false) {
                    continue 2;
                }
            }

            return $delimiter;
        }

        throw new InputException(__('None of the supported path delimiters is free of the requested names.'));
    }
}
