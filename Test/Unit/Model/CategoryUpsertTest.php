<?php
/**
 * Copyright (c) 2026 DDTCoreX
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace DDTCoreX\DagsterBridge\Test\Unit\Model;

use DDTCoreX\DagsterBridge\Model\CategoryFactory;
use DDTCoreX\DagsterBridge\Model\CategoryPathParser;
use DDTCoreX\DagsterBridge\Model\CategoryUpsert;
use DDTCoreX\DagsterBridge\Model\Data\CategoryPathIdFactory;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\CatalogImportExport\Model\Import\Product\CategoryProcessor;
use Magento\Catalog\Model\ResourceModel\Category as CategoryResource;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\InputException;
use Magento\Framework\ObjectManagerInterface;
use PHPUnit\Framework\TestCase;

class CategoryUpsertTest extends TestCase
{
    /**
     * Names the repository was asked to save, in order.
     *
     * @var string[]
     */
    private $savedNames = [];

    /**
     * Name of the last category the factory built.
     *
     * @var string
     */
    private $createdName = '';

    /**
     * Id the next save hands back.
     *
     * @var int
     */
    private $nextId = 100;

    /**
     * Builds the service over stubs that keep the database out of the test.
     *
     * @param CategoryProcessor $processor
     * @param array $names Category id => name, for repository reads.
     * @param array $children Category id => comma separated child ids.
     * @param string|null $failOnName Name whose save throws.
     * @param CategoryResource|null $categoryResource
     * @return CategoryUpsert
     * @phpstan-param array<int, string> $names
     * @phpstan-param array<int, string> $children
     */
    private function makeModel(
        CategoryProcessor $processor,
        array $names = [],
        array $children = [],
        ?string $failOnName = null,
        ?CategoryResource $categoryResource = null
    ): CategoryUpsert {
        $this->savedNames = [];
        $this->createdName = '';
        $this->nextId = 100;

        $factory = $this->createStub(CategoryFactory::class);
        $factory->method('create')->willReturnCallback(function () {
            $category = $this->createStub(CategoryInterface::class);
            $category->method('setName')->willReturnCallback(function ($name) {
                $this->createdName = (string) $name;
            });

            return $category;
        });

        $repository = $this->createStub(CategoryRepositoryInterface::class);
        $repository->method('get')->willReturnCallback(function ($categoryId) use ($names, $children) {
            $category = $this->createStub(CategoryInterface::class);
            $category->method('getId')->willReturn((int) $categoryId);
            $category->method('getName')->willReturn($names[(int) $categoryId] ?? '');
            $category->method('getChildren')->willReturn($children[(int) $categoryId] ?? '');

            return $category;
        });
        $repository->method('save')->willReturnCallback(function ($category) use ($failOnName) {
            $name = $this->createdName;
            if ($failOnName !== null && $name === $failOnName) {
                throw new CouldNotSaveException(__('The category "%1" could not be saved.', $name));
            }
            $this->savedNames[] = $name;

            $saved = $this->createStub(CategoryInterface::class);
            $saved->method('getId')->willReturn($this->nextId++);

            return $saved;
        });

        $objectManager = $this->createStub(ObjectManagerInterface::class);
        $objectManager->method('create')->willReturnCallback(
            static function ($type, array $arguments = []) {
                return new $type();
            }
        );

        return new CategoryUpsert(
            new CategoryPathParser(),
            $processor,
            $repository,
            $factory,
            new CategoryPathIdFactory($objectManager),
            $categoryResource ?? $this->createStub(CategoryResource::class)
        );
    }

    public function testPathsWithoutSlashGoThroughTheNativeProcessor(): void
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

    public function testNamesWithSlashUseTheRepositoryAndNeverTheProcessor(): void
    {
        $processor = $this->createMock(CategoryProcessor::class);
        $processor->expects($this->never())->method('upsertCategories');

        $items = $this->makeModel(
            $processor,
            [1 => '', 2 => 'Default Category'],
            [1 => '2', 2 => '']
        )->upsert(['Default Category|Tops/Tees'], 'Default Category', '|');

        self::assertCount(1, $items);
        self::assertSame('Default Category|Tops/Tees', $items[0]->getPath());
        self::assertSame(100, $items[0]->getId());
        self::assertSame(['Tops/Tees'], $this->savedNames);
    }

    public function testExistingCategoriesAreReusedInsteadOfCreated(): void
    {
        $processor = $this->createMock(CategoryProcessor::class);
        $processor->expects($this->never())->method('upsertCategories');

        $items = $this->makeModel(
            $processor,
            [1 => '', 2 => 'Default Category', 20 => 'Tops/Tees'],
            [1 => '2', 2 => '20', 20 => '']
        )->upsert(['Default Category|Tops/Tees'], 'Default Category', '|');

        self::assertSame(20, $items[0]->getId());
        self::assertSame([], $this->savedNames);
    }

    public function testFailureRollsBackAndNamesThePath(): void
    {
        $processor = $this->createMock(CategoryProcessor::class);
        $processor->expects($this->never())->method('upsertCategories');

        $categoryResource = $this->createMock(CategoryResource::class);
        $categoryResource->expects($this->once())->method('beginTransaction');
        $categoryResource->expects($this->once())->method('rollBack');
        $categoryResource->expects($this->never())->method('commit');

        try {
            $this->makeModel(
                $processor,
                [1 => '', 2 => 'Default Category'],
                [1 => '2', 2 => ''],
                'Tops/Tees',
                $categoryResource
            )->upsert(['Default Category|Tops/Tees'], 'Default Category', '|');
            self::fail('an InputException was expected');
        } catch (InputException $exception) {
            self::assertStringContainsString('Default Category|Tops/Tees', $exception->getMessage());
        }
    }

    public function testMissingRootIsRejected(): void
    {
        $processor = $this->createMock(CategoryProcessor::class);
        $processor->expects($this->never())->method('upsertCategories');

        $this->expectException(InputException::class);
        $this->expectExceptionMessage('Default Category');

        $this->makeModel($processor, [1 => ''], [1 => ''])->upsert(
            ['Default Category|Tops/Tees'],
            'Default Category',
            '|'
        );
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

        $items = $this->makeModel($processor, [], [], null, $categoryResource)->upsert(['Men']);

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

        $this->makeModel($processor, [], [], null, $categoryResource)->upsert(['Men']);
    }
}
