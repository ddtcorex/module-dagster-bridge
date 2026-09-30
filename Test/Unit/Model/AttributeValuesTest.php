<?php
/**
 * Copyright (c) 2026 DDTCoreX
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace DDTCoreX\DagsterBridge\Test\Unit\Model;

use DDTCoreX\DagsterBridge\Api\AttributeValuesInterface;
use DDTCoreX\DagsterBridge\Model\AttributeValues;
use DDTCoreX\DagsterBridge\Model\Data\AttributeValueItemFactory;
use DDTCoreX\DagsterBridge\Model\ResourceModel\AttributeValuesQuery;
use Magento\Eav\Api\AttributeRepositoryInterface;
use Magento\Eav\Api\Data\AttributeInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Magento\Framework\DB\Select;
use Magento\Framework\DB\Select\SelectRenderer;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\ObjectManagerInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\StoreRepositoryInterface;
use PHPUnit\Framework\TestCase;

class AttributeValuesTest extends TestCase
{
    /**
     * Builds an attribute double with the three getters the service asks for.
     *
     * @param string $code
     * @param int $attributeId
     * @param string $backendType
     * @return AttributeInterface
     */
    private function attribute(string $code, int $attributeId, string $backendType): AttributeInterface
    {
        $attribute = $this->createStub(AttributeInterface::class);
        $attribute->method('getAttributeCode')->willReturn($code);
        $attribute->method('getAttributeId')->willReturn($attributeId);
        $attribute->method('getBackendType')->willReturn($backendType);

        return $attribute;
    }

    /**
     * Builds the service over rows a fake connection returns.
     *
     * @param array $rows
     * @param array $attributes
     * @param string[] $unsupported Codes the query builder cannot read.
     * @return AttributeValues
     * @phpstan-param array<int, array<string, mixed>> $rows
     * @phpstan-param array<string, AttributeInterface> $attributes
     */
    private function makeModel(array $rows, array $attributes, array $unsupported = []): AttributeValues
    {
        $adapter = $this->createStub(Mysql::class);
        $adapter->method('fetchAll')->willReturn($rows);

        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($adapter);

        $repository = $this->createStub(AttributeRepositoryInterface::class);
        $repository->method('get')->willReturnCallback(
            static function ($entityTypeCode, $attributeCode, $storeId = null) use ($attributes) {
                if (!isset($attributes[$attributeCode])) {
                    throw new NoSuchEntityException();
                }

                return $attributes[$attributeCode];
            }
        );

        // keyed by backend type, because that is how the service reads it
        $first = reset($attributes);
        $backendType = $first === false ? 'varchar' : (string) $first->getBackendType();
        $selects = [$backendType => new Select($adapter, $this->createStub(SelectRenderer::class))];

        $query = $this->createStub(AttributeValuesQuery::class);
        $query->method('build')->willReturn($selects);
        $query->method('unsupportedCodes')->willReturn($unsupported);

        $objectManager = $this->createStub(ObjectManagerInterface::class);
        $objectManager->method('create')->willReturnCallback(
            static function ($type, array $arguments = []) {
                return new $type();
            }
        );

        $storeRepository = $this->createStub(StoreRepositoryInterface::class);
        $storeRepository->method('getById')->willReturnCallback(function ($storeId) {
            if (!in_array((int) $storeId, [0, 1, 5], true)) {
                throw new NoSuchEntityException(__('The store that was requested wasn\'t found.'));
            }

            return $this->createStub(StoreInterface::class);
        });

        return new AttributeValues(
            $resource,
            $query,
            $repository,
            new AttributeValueItemFactory($objectManager),
            $storeRepository
        );
    }

    /**
     * One value row as the connection would return it.
     *
     * @param int $attributeId
     * @param int $storeId
     * @param string|null $value
     * @param string $sku
     * @return array
     * @phpstan-return array<string, mixed>
     */
    private function row(int $attributeId, int $storeId, ?string $value, string $sku): array
    {
        return [
            'attribute_id' => (string) $attributeId,
            'store_id' => (string) $storeId,
            'value' => $value,
            'sku' => $sku,
        ];
    }

    public function testRejectsMoreSkusThanTheLimit(): void
    {
        $skus = [];
        for ($index = 0; $index <= AttributeValuesInterface::MAX_SKUS; $index++) {
            $skus[] = 'sku-' . $index;
        }

        $this->expectException(InputException::class);
        $this->expectExceptionMessage('1000');

        $this->makeModel([], [])->get($skus, ['name']);
    }

    public function testRejectsMoreAttributeCodesThanTheLimit(): void
    {
        $codes = [];
        for ($index = 0; $index <= AttributeValuesInterface::MAX_ATTRIBUTE_CODES; $index++) {
            $codes[] = 'code_' . $index;
        }

        $this->expectException(InputException::class);
        $this->expectExceptionMessage('50');

        $this->makeModel([], [])->get(['sku-1'], $codes);
    }

    public function testUnknownAttributeCodeIsRejectedAndListed(): void
    {
        $this->expectException(InputException::class);
        $this->expectExceptionMessage('does_not_exist');

        $this->makeModel([], ['name' => $this->attribute('name', 71, 'varchar')])
            ->get(['sku-1'], ['name', 'does_not_exist']);
    }

    public function testReturnsOneItemPerRequestedPair(): void
    {
        $items = $this->makeModel(
            [$this->row(77, 0, '1.5000', 'sku-1')],
            ['weight' => $this->attribute('weight', 77, 'decimal'), 'name' => $this->attribute('name', 71, 'varchar')]
        )->get(['sku-1', 'sku-2'], ['weight', 'name'], 5);

        self::assertCount(4, $items);
        self::assertSame('sku-1', $items[0]->getSku());
        self::assertSame('weight', $items[0]->getAttributeCode());
        self::assertSame('1.5000', $items[0]->getDefaultValue());
        self::assertNull($items[0]->getStoreValue());

        self::assertSame('name', $items[1]->getAttributeCode());
        self::assertNull($items[1]->getDefaultValue());
        self::assertNull($items[1]->getStoreValue());

        self::assertSame('sku-2', $items[2]->getSku());
        self::assertNull($items[3]->getDefaultValue());
    }

    public function testStoreValueAndDefaultValueComeBackSeparately(): void
    {
        $items = $this->makeModel(
            [
                $this->row(71, 0, 'Default name', 'sku-1'),
                $this->row(71, 5, 'Nom du magasin', 'sku-1'),
            ],
            ['name' => $this->attribute('name', 71, 'varchar')]
        )->get(['sku-1'], ['name'], 5);

        self::assertCount(1, $items);
        self::assertSame('Nom du magasin', $items[0]->getStoreValue());
        self::assertSame('Default name', $items[0]->getDefaultValue());
    }

    public function testStoreWithoutItsOwnValueKeepsTheDefaultOne(): void
    {
        $items = $this->makeModel(
            [$this->row(71, 0, 'Default name', 'sku-1')],
            ['name' => $this->attribute('name', 71, 'varchar')]
        )->get(['sku-1'], ['name'], 5);

        self::assertNull($items[0]->getStoreValue());
        self::assertSame('Default name', $items[0]->getDefaultValue());
    }

    public function testStaticAttributeHasNoStoreValue(): void
    {
        $items = $this->makeModel(
            [['sku' => 'sku-1', 'type_id' => 'simple']],
            ['type_id' => $this->attribute('type_id', 99, 'static')]
        )->get(['sku-1'], ['type_id'], 5);

        self::assertCount(1, $items);
        self::assertNull($items[0]->getStoreValue());
        self::assertSame('simple', $items[0]->getDefaultValue());
    }

    public function testUnsupportedAttributeCodesAreRejectedAndListed(): void
    {
        $this->expectException(InputException::class);
        $this->expectExceptionMessage('category_ids, tier_price');

        $this->makeModel(
            [],
            [
                'category_ids' => $this->attribute('category_ids', 100, 'static'),
                'tier_price' => $this->attribute('tier_price', 78, 'decimal'),
            ],
            ['category_ids', 'tier_price']
        )->get(['sku-1'], ['category_ids', 'tier_price']);
    }

    public function testASkuSpelledInAnotherCaseAnswersUnderTheRequestedSpelling(): void
    {
        // MySQL matches sku IN ('abc-1') against ABC-1, the row comes back in
        // the database spelling and must still land on the requested item
        $items = $this->makeModel(
            [$this->row(71, 0, 'Default name', 'ABC-1')],
            ['name' => $this->attribute('name', 71, 'varchar')]
        )->get(['abc-1'], ['name'], 0);

        self::assertCount(1, $items);
        self::assertSame('abc-1', $items[0]->getSku());
        self::assertSame('Default name', $items[0]->getDefaultValue());
        self::assertSame('Default name', $items[0]->getStoreValue());
    }

    public function testAStaticValueIsFoundForASkuSpelledInAnotherCase(): void
    {
        $items = $this->makeModel(
            [['sku' => 'ABC-1', 'created_at' => '2026-01-01 00:00:00']],
            ['created_at' => $this->attribute('created_at', 99, 'static')]
        )->get(['Abc-1'], ['created_at'], 0);

        self::assertSame('Abc-1', $items[0]->getSku());
        self::assertSame('2026-01-01 00:00:00', $items[0]->getDefaultValue());
    }

    public function testEmptySkusAreRejected(): void
    {
        $this->expectException(InputException::class);
        $this->expectExceptionMessage('At least one SKU');

        $this->makeModel([], ['name' => $this->attribute('name', 71, 'varchar')])->get([], ['name']);
    }

    public function testEmptyAttributeCodesAreRejected(): void
    {
        $this->expectException(InputException::class);
        $this->expectExceptionMessage('At least one attribute code');

        $this->makeModel([], [])->get(['sku-1'], []);
    }

    public function testAStoreThatDoesNotExistIsRejected(): void
    {
        $this->expectException(InputException::class);
        $this->expectExceptionMessage('Store 999 does not exist.');

        $this->makeModel([], ['name' => $this->attribute('name', 71, 'varchar')])->get(['sku-1'], ['name'], 999);
    }
}
