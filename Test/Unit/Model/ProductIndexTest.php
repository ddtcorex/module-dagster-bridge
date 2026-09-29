<?php
/**
 * Copyright (c) 2026 DDTCoreX
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace DDTCoreX\DagsterBridge\Test\Unit\Model;

use DDTCoreX\DagsterBridge\Api\ProductIndexInterface;
use DDTCoreX\DagsterBridge\Model\Data\ProductIndexItemFactory;
use DDTCoreX\DagsterBridge\Model\Data\ProductIndexPageFactory;
use DDTCoreX\DagsterBridge\Model\ProductIndex;
use DDTCoreX\DagsterBridge\Model\ResourceModel\ProductIndexQuery;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Magento\Framework\DB\Select;
use Magento\Framework\DB\Select\SelectRenderer;
use Magento\Framework\Exception\InputException;
use Magento\Framework\ObjectManagerInterface;
use PHPUnit\Framework\TestCase;

class ProductIndexTest extends TestCase
{
    /**
     * Builds the model over rows a fake connection returns.
     *
     * @param array $rows
     * @return ProductIndex
     * @phpstan-param array<int, array<string, mixed>> $rows
     */
    private function makeModel(array $rows): ProductIndex
    {
        $adapter = $this->createMock(Mysql::class);
        $adapter->expects($this->atLeastOnce())->method('fetchAll')->willReturn($rows);

        $resource = $this->createMock(ResourceConnection::class);
        $resource->expects($this->atLeastOnce())->method('getConnection')->willReturn($adapter);

        $query = $this->createMock(ProductIndexQuery::class);
        $query->expects($this->atLeastOnce())
            ->method('build')
            ->willReturn(new Select($adapter, $this->createStub(SelectRenderer::class)));

        $objectManager = $this->createMock(ObjectManagerInterface::class);
        $objectManager->expects($this->atLeastOnce())
            ->method('create')
            ->willReturnCallback(
                static function ($type, array $arguments = []) {
                    return new $type();
                }
            );

        return new ProductIndex(
            $resource,
            $query,
            new ProductIndexItemFactory($objectManager),
            new ProductIndexPageFactory($objectManager)
        );
    }

    /**
     * Builds the model with collaborators that must never be reached.
     *
     * @return ProductIndex
     */
    private function makeModelWithoutQuery(): ProductIndex
    {
        $resource = $this->createMock(ResourceConnection::class);
        $resource->expects($this->never())->method('getConnection');

        $query = $this->createMock(ProductIndexQuery::class);
        $query->expects($this->never())->method('build');

        $objectManager = $this->createStub(ObjectManagerInterface::class);

        return new ProductIndex(
            $resource,
            $query,
            new ProductIndexItemFactory($objectManager),
            new ProductIndexPageFactory($objectManager)
        );
    }

    /**
     * One index row as the connection would return it.
     *
     * @param int $entityId
     * @param int|null $status
     * @return array
     * @phpstan-return array<string, mixed>
     */
    private function row(int $entityId, ?int $status): array
    {
        return [
            'entity_id' => (string) $entityId,
            'sku' => 'sku-' . $entityId,
            'type_id' => 'simple',
            'attribute_set_id' => '4',
            'updated_at' => '2026-01-02 03:04:05',
            'status' => $status === null ? null : (string) $status,
        ];
    }

    public function testLimitAboveMaxIsRejectedWithInputException(): void
    {
        $this->expectException(InputException::class);
        $this->expectExceptionMessage('20000');

        $this->makeModelWithoutQuery()->getPage(0, ProductIndexInterface::MAX_LIMIT + 1);
    }

    public function testLimitBelowOneIsRejectedWithInputException(): void
    {
        $this->expectException(InputException::class);

        $this->makeModelWithoutQuery()->getPage(0, 0);
    }

    public function testNextAfterIsNullWhenPageIsShort(): void
    {
        $page = $this->makeModel([$this->row(3, 1), $this->row(4, null)])->getPage(2, 5);

        self::assertNull($page->getNextAfter());
        self::assertCount(2, $page->getItems());

        $first = $page->getItems()[0];
        self::assertSame(3, $first->getEntityId());
        self::assertSame('sku-3', $first->getSku());
        self::assertSame('simple', $first->getTypeId());
        self::assertSame(4, $first->getAttributeSetId());
        self::assertSame(1, $first->getStatus());
        self::assertSame('2026-01-02 03:04:05', $first->getUpdatedAt());

        self::assertNull($page->getItems()[1]->getStatus());
    }

    public function testNextAfterIsTheLastEntityIdWhenThePageIsFull(): void
    {
        $rows = [$this->row(3, 1), $this->row(4, 1), $this->row(9, 0)];

        $page = $this->makeModel($rows)->getPage(0, 3);

        self::assertCount(3, $page->getItems());
        self::assertSame(9, $page->getNextAfter());
    }

    public function testEmptyPageHasNoNextAfter(): void
    {
        $page = $this->makeModel([])->getPage(120, 500);

        self::assertSame([], $page->getItems());
        self::assertNull($page->getNextAfter());
    }
}
