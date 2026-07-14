<?php

namespace Tests\Shop\Unit\Repository;

use Mublo\Infrastructure\Database\Database;
use Mublo\Infrastructure\Database\QueryBuilder;
use Mublo\Packages\Shop\Entity\Product;
use Mublo\Packages\Shop\Repository\ProductRepository;
use PHPUnit\Framework\TestCase;

class ProductRepositoryTest extends TestCase
{
    public function testUnscopedCrudIsRejected(): void
    {
        $repository = new ProductRepository($this->createMock(Database::class));

        foreach (['find', 'update', 'delete', 'findByIds'] as $method) {
            try {
                match ($method) {
                    'find' => $repository->find(7),
                    'update' => $repository->update(7, ['goods_name' => 'Changed']),
                    'delete' => $repository->delete(7),
                    'findByIds' => $repository->findByIds([7]),
                };
                $this->fail("{$method}() should reject unscoped access");
            } catch (\LogicException $e) {
                $this->assertStringContainsString('domainId', $e->getMessage());
            }
        }
    }

    public function testFindInDomainRequiresProductIdAndDomainId(): void
    {
        $whereCalls = [];
        $query = $this->createMock(QueryBuilder::class);
        $query->method('where')->willReturnCallback(
            function (string $column, string $operator, mixed $value) use (&$whereCalls, $query): QueryBuilder {
                $whereCalls[] = [$column, $operator, $value];
                return $query;
            }
        );
        $query->method('first')->willReturn([
            'goods_id' => 7,
            'domain_id' => 10,
            'goods_name' => 'Domain product',
            'is_active' => 1,
        ]);
        $db = $this->createMock(Database::class);
        $db->method('table')->with('shop_products')->willReturn($query);

        $product = (new ProductRepository($db))->findInDomain(10, 7);

        $this->assertContains(['goods_id', '=', 7], $whereCalls);
        $this->assertContains(['domain_id', '=', 10], $whereCalls);
        $this->assertInstanceOf(Product::class, $product);
        $this->assertSame(10, $product->getDomainId());
    }

    public function testUpdateInDomainScopesWriteAndCannotMoveProductBetweenDomains(): void
    {
        $whereCalls = [];
        $updatedData = null;
        $query = $this->createMock(QueryBuilder::class);
        $query->method('where')->willReturnCallback(
            function (string $column, string $operator, mixed $value) use (&$whereCalls, $query): QueryBuilder {
                $whereCalls[] = [$column, $operator, $value];
                return $query;
            }
        );
        $query->method('update')->willReturnCallback(
            function (array $data) use (&$updatedData): int {
                $updatedData = $data;
                return 1;
            }
        );
        $db = $this->createMock(Database::class);
        $db->method('table')->with('shop_products')->willReturn($query);

        $affected = (new ProductRepository($db))->updateInDomain(10, 7, [
            'goods_name' => 'Changed',
            'domain_id' => 99,
        ]);

        $this->assertSame(1, $affected);
        $this->assertContains(['goods_id', '=', 7], $whereCalls);
        $this->assertContains(['domain_id', '=', 10], $whereCalls);
        $this->assertSame('Changed', $updatedData['goods_name']);
        $this->assertArrayNotHasKey('domain_id', $updatedData);
    }

    public function testDeleteInDomainRequiresProductIdAndDomainId(): void
    {
        $whereCalls = [];
        $query = $this->createMock(QueryBuilder::class);
        $query->method('where')->willReturnCallback(
            function (string $column, string $operator, mixed $value) use (&$whereCalls, $query): QueryBuilder {
                $whereCalls[] = [$column, $operator, $value];
                return $query;
            }
        );
        $query->method('delete')->willReturn(1);
        $db = $this->createMock(Database::class);
        $db->method('table')->with('shop_products')->willReturn($query);

        $affected = (new ProductRepository($db))->deleteInDomain(10, 7);

        $this->assertSame(1, $affected);
        $this->assertContains(['goods_id', '=', 7], $whereCalls);
        $this->assertContains(['domain_id', '=', 10], $whereCalls);
    }

    public function testIncrementHitRequiresProductIdAndDomainId(): void
    {
        $db = $this->createMock(Database::class);
        $db->expects($this->once())
            ->method('execute')
            ->with(
                'UPDATE shop_products SET hit = hit + 1 WHERE goods_id = ? AND domain_id = ?',
                [7, 10]
            );

        (new ProductRepository($db))->incrementHit(10, 7);
    }

    public function testFindByIdsForDomainRequiresDomainAndActiveProduct(): void
    {
        $whereCalls = [];
        $query = $this->createMock(QueryBuilder::class);
        $query->method('whereIn')->with('goods_id', [2, 1])->willReturnSelf();
        $query->method('where')->willReturnCallback(
            function (string $column, string $operator, mixed $value) use (&$whereCalls, $query): QueryBuilder {
                $whereCalls[] = [$column, $operator, $value];
                return $query;
            }
        );
        $query->method('get')->willReturn([[
            'goods_id' => 1,
            'domain_id' => 10,
            'goods_name' => 'Product',
            'is_active' => 1,
        ]]);
        $db = $this->createMock(Database::class);
        $db->method('table')->with('shop_products')->willReturn($query);

        $items = (new ProductRepository($db))->findByIdsForDomain(10, [2, 1]);

        $this->assertContains(['domain_id', '=', 10], $whereCalls);
        $this->assertContains(['is_active', '=', 1], $whereCalls);
        $this->assertCount(1, $items);
        $this->assertInstanceOf(Product::class, $items[0]);
    }
}
