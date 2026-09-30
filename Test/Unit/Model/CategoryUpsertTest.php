<?php
/**
 * Copyright (c) 2026 DDTCoreX
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace DDTCoreX\DagsterBridge\Test\Unit\Model;

use DDTCoreX\DagsterBridge\Model\CategoryPathParser;
use DDTCoreX\DagsterBridge\Model\CategoryProcessorFactory;
use DDTCoreX\DagsterBridge\Model\CategoryUpsert;
use DDTCoreX\DagsterBridge\Model\Data\CategoryPathIdFactory;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Model\ResourceModel\Category as CategoryResource;
use Magento\CatalogImportExport\Model\Import\Product\CategoryProcessor;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Webapi\Exception as WebapiException;
use PHPUnit\Framework\TestCase;

class CategoryUpsertTest extends TestCase
{
    /**
     * Store ids the repository was read with.
     *
     * @var int[]
     */
    private $readStoreIds = [];

    /**
     * Calls to the lock and to the processor factory, in order.
     *
     * @var string[]
     */
    private $calls = [];

    /**
     * Builds the service over stubs that keep the database out of the test.
     *
     * The repository only answers the tree roots: the children of the
     * invisible root and their names.
     *
     * @param CategoryProcessor $processor
     * @param array $roots Root category id => name.
     * @param CategoryResource|null $categoryResource
     * @param LockManagerInterface|null $lockManager
     * @return CategoryUpsert
     * @phpstan-param array<int, string> $roots
     */
    private function makeModel(
        CategoryProcessor $processor,
        array $roots = [2 => 'Default Category'],
        ?CategoryResource $categoryResource = null,
        ?LockManagerInterface $lockManager = null
    ): CategoryUpsert {
        $this->readStoreIds = [];
        $this->calls = [];

        if ($lockManager === null) {
            $lockManager = $this->createStub(LockManagerInterface::class);
            $lockManager->method('lock')->willReturnCallback(function () {
                $this->calls[] = 'lock';

                return true;
            });
            $lockManager->method('unlock')->willReturnCallback(function () {
                $this->calls[] = 'unlock';

                return true;
            });
        }

        $processorFactory = $this->createStub(CategoryProcessorFactory::class);
        $processorFactory->method('create')->willReturnCallback(function () use ($processor) {
            $this->calls[] = 'processor';

            return $processor;
        });

        $repository = $this->createStub(CategoryRepositoryInterface::class);
        $repository->method('get')->willReturnCallback(function ($categoryId, $storeId = null) use ($roots) {
            $this->readStoreIds[] = $storeId;

            $category = $this->createStub(CategoryInterface::class);
            $category->method('getId')->willReturn((int) $categoryId);
            $category->method('getName')->willReturn($roots[(int) $categoryId] ?? 'Root Catalog');
            $category->method('getChildren')->willReturn(
                (int) $categoryId === 1 ? implode(',', array_keys($roots)) : ''
            );

            return $category;
        });

        $objectManager = $this->createStub(ObjectManagerInterface::class);
        $objectManager->method('create')->willReturnCallback(
            static function ($type, array $arguments = []) {
                return new $type();
            }
        );

        return new CategoryUpsert(
            new CategoryPathParser(),
            $processorFactory,
            $repository,
            new CategoryPathIdFactory($objectManager),
            $categoryResource ?? $this->createStub(CategoryResource::class),
            $lockManager
        );
    }

    public function testPathsGoThroughTheNativeProcessor(): void
    {
        $processor = $this->createMock(CategoryProcessor::class);
        $processor->expects($this->once())
            ->method('upsertCategories')
            ->with('Default Category/Men,Default Category/Women', ',')
            ->willReturn([7, 8]);
        $processor->expects($this->atLeastOnce())
            ->method('getFailedCategories')
            ->willReturn([]);

        $items = $this->makeModel($processor)->upsert(
            ['Men', 'Women'],
            'Default Category',
            '/'
        );

        self::assertCount(2, $items);
        self::assertSame('Men', $items[0]->getPath());
        self::assertSame(7, $items[0]->getId());
        self::assertSame('Women', $items[1]->getPath());
        self::assertSame(8, $items[1]->getId());
    }

    public function testThePathDelimiterNeverAppearsInAName(): void
    {
        $processor = $this->createMock(CategoryProcessor::class);
        $processor->expects($this->once())
            ->method('upsertCategories')
            ->with('Default Category/Men,Women;Default Category/Kids', ';')
            ->willReturn([7, 8]);
        $processor->expects($this->atLeastOnce())
            ->method('getFailedCategories')
            ->willReturn([]);

        $items = $this->makeModel($processor)->upsert(['Men,Women', 'Kids'], 'Default Category');

        self::assertCount(2, $items);
        self::assertSame(7, $items[0]->getId());
    }

    public function testASlashInsideANameIsEscapedForTheNativeProcessor(): void
    {
        $processor = $this->createMock(CategoryProcessor::class);
        $processor->expects($this->once())
            ->method('upsertCategories')
            ->with('Default Category/Tops\/Tees', ',')
            ->willReturn([20]);
        $processor->method('getFailedCategories')->willReturn([]);

        $items = $this->makeModel($processor)->upsert(['Default Category|Tops/Tees'], 'Default Category', '|');

        self::assertSame('Default Category|Tops/Tees', $items[0]->getPath());
        self::assertSame(20, $items[0]->getId());
    }

    public function testANameEndingWithABackslashIsRejected(): void
    {
        $processor = $this->createMock(CategoryProcessor::class);
        $processor->expects($this->never())->method('upsertCategories');

        $this->expectException(InputException::class);
        $this->expectExceptionMessage('backslash');

        $this->makeModel($processor)->upsert(['Tops\\|Tees'], 'Default Category', '|');
    }

    public function testMissingRootIsRejectedBeforeAnythingIsCreated(): void
    {
        $processor = $this->createMock(CategoryProcessor::class);
        $processor->expects($this->never())->method('upsertCategories');

        $categoryResource = $this->createMock(CategoryResource::class);
        $categoryResource->expects($this->never())->method('commit');

        $this->expectException(InputException::class);
        $this->expectExceptionMessage('The root category "No Such Root" does not exist.');

        $this->makeModel($processor, [2 => 'Default Category'], $categoryResource)
            ->upsert(['Men'], 'No Such Root');
    }

    public function testRootMatchesCaseInsensitivelyAsTheNativeProcessorDoes(): void
    {
        $processor = $this->createMock(CategoryProcessor::class);
        $processor->expects($this->once())
            ->method('upsertCategories')
            ->with('default category/Men', ',')
            ->willReturn([7]);
        $processor->method('getFailedCategories')->willReturn([]);

        $items = $this->makeModel($processor)->upsert(['Men'], 'default category');

        self::assertSame(7, $items[0]->getId());
    }

    public function testTheRootIsReadAtTheAdminStore(): void
    {
        $processor = $this->createStub(CategoryProcessor::class);
        $processor->method('upsertCategories')->willReturn([7]);
        $processor->method('getFailedCategories')->willReturn([]);

        $this->makeModel($processor)->upsert(['Men']);

        self::assertNotEmpty($this->readStoreIds);
        self::assertSame([0], array_values(array_unique($this->readStoreIds)));
    }

    public function testFailureRollsBackAndNamesThePath(): void
    {
        $processor = $this->createStub(CategoryProcessor::class);
        $processor->method('upsertCategories')
            ->willThrowException(new CouldNotSaveException(__('URL key for specified store already exists.')));

        $categoryResource = $this->createMock(CategoryResource::class);
        $categoryResource->expects($this->once())->method('beginTransaction');
        $categoryResource->expects($this->once())->method('rollBack');
        $categoryResource->expects($this->never())->method('commit');

        try {
            $this->makeModel($processor, [2 => 'Default Category'], $categoryResource)
                ->upsert(['Men/Shirts'], 'Default Category');
            self::fail('an InputException was expected');
        } catch (InputException $exception) {
            self::assertStringContainsString('Men/Shirts', $exception->getMessage());
            self::assertStringContainsString('URL key', $exception->getMessage());
        }
    }

    public function testProcessorFailuresAreReportedWithTheirPath(): void
    {
        $processor = $this->createMock(CategoryProcessor::class);
        $processor->expects($this->atLeastOnce())
            ->method('upsertCategories')
            ->willReturn([7]);
        $processor->expects($this->atLeastOnce())
            ->method('getFailedCategories')
            ->willReturn([
                [
                    'category' => 'Default Category/Men',
                    'exception' => new CouldNotSaveException(__('boom')),
                ],
            ]);
        $processor->expects($this->atLeastOnce())->method('clearFailedCategories');

        $this->expectException(InputException::class);
        $this->expectExceptionMessage('Default Category/Men');

        $this->makeModel($processor)->upsert(['Men'], 'Default Category');
    }

    public function testSuccessCommitsThroughTheCategoryResourceModel(): void
    {
        $processor = $this->createStub(CategoryProcessor::class);
        $processor->method('upsertCategories')->willReturn([7]);
        $processor->method('getFailedCategories')->willReturn([]);

        // the resource model, not the raw adapter, owns the transaction so
        // the category after commit callbacks run and a rollback clears them
        $categoryResource = $this->createMock(CategoryResource::class);
        $categoryResource->expects($this->once())->method('beginTransaction');
        $categoryResource->expects($this->once())->method('commit');
        $categoryResource->expects($this->never())->method('rollBack');

        $items = $this->makeModel($processor, [2 => 'Default Category'], $categoryResource)->upsert(['Men']);

        self::assertSame(7, $items[0]->getId());
    }

    public function testAnErrorThatIsNotAnExceptionStillRollsBack(): void
    {
        $processor = $this->createStub(CategoryProcessor::class);
        $processor->method('upsertCategories')->willThrowException(new \TypeError('boom'));

        $categoryResource = $this->createMock(CategoryResource::class);
        $categoryResource->expects($this->once())->method('beginTransaction');
        $categoryResource->expects($this->once())->method('rollBack');
        $categoryResource->expects($this->never())->method('commit');

        $this->expectException(\TypeError::class);

        $this->makeModel($processor, [2 => 'Default Category'], $categoryResource)->upsert(['Men']);
    }

    public function testTheProcessorIsBuiltUnderTheLockAndTheLockIsReleased(): void
    {
        $processor = $this->createStub(CategoryProcessor::class);
        $processor->method('upsertCategories')->willReturn([7]);
        $processor->method('getFailedCategories')->willReturn([]);

        $this->makeModel($processor)->upsert(['Men']);

        // the processor loads the whole tree when it is built, so it must be
        // built after the lock is held or it misses a concurrent call's rows
        self::assertSame(['lock', 'processor', 'unlock'], $this->calls);
    }

    public function testTheLockIsReleasedWhenTheUpsertFails(): void
    {
        $processor = $this->createStub(CategoryProcessor::class);
        $processor->method('upsertCategories')->willThrowException(new \TypeError('boom'));

        try {
            $this->makeModel($processor)->upsert(['Men']);
            self::fail('a TypeError was expected');
        } catch (\TypeError $error) {
            self::assertSame(['lock', 'processor', 'unlock'], $this->calls);
        }
    }

    public function testTheLockIsNamedAndBounded(): void
    {
        $processor = $this->createStub(CategoryProcessor::class);
        $processor->method('upsertCategories')->willReturn([7]);
        $processor->method('getFailedCategories')->willReturn([]);

        $lockManager = $this->createMock(LockManagerInterface::class);
        $lockManager->expects($this->once())
            ->method('lock')
            ->with(CategoryUpsert::LOCK_NAME, CategoryUpsert::LOCK_TIMEOUT)
            ->willReturn(true);
        $lockManager->expects($this->once())->method('unlock')->with(CategoryUpsert::LOCK_NAME)->willReturn(true);

        $this->makeModel($processor, [2 => 'Default Category'], null, $lockManager)->upsert(['Men']);

        self::assertSame('dagster_bridge_category_upsert', CategoryUpsert::LOCK_NAME);
        self::assertGreaterThan(0, CategoryUpsert::LOCK_TIMEOUT);
        self::assertLessThanOrEqual(30, CategoryUpsert::LOCK_TIMEOUT);
    }

    public function testALockTimeoutAnswersServiceUnavailable(): void
    {
        $processor = $this->createMock(CategoryProcessor::class);
        $processor->expects($this->never())->method('upsertCategories');

        $lockManager = $this->createMock(LockManagerInterface::class);
        $lockManager->expects($this->once())->method('lock')->willReturn(false);
        $lockManager->expects($this->never())->method('unlock');

        try {
            $this->makeModel($processor, [2 => 'Default Category'], null, $lockManager)->upsert(['Men']);
            self::fail('a web API exception was expected');
        } catch (WebapiException $exception) {
            self::assertSame(503, $exception->getHttpCode());
            self::assertStringContainsString('retry', $exception->getMessage());
        }
    }

    public function testAnEmptySeparatorIsRejected(): void
    {
        $processor = $this->createMock(CategoryProcessor::class);
        $processor->expects($this->never())->method('upsertCategories');

        $this->expectException(InputException::class);
        $this->expectExceptionMessage('separator');

        $this->makeModel($processor)->upsert(['Men'], 'Default Category', '');
    }
}
