<?php

namespace Tests\Shop\Unit\Repository;

use Mublo\Infrastructure\Database\Database;
use Mublo\Infrastructure\Database\QueryBuilder;
use Mublo\Packages\Shop\Entity\CartItem;
use Mublo\Packages\Shop\Repository\CartRepository;
use PHPUnit\Framework\TestCase;

class CartRepositoryDomainTest extends TestCase
{
    public function testFindInDomainRequiresCartItemIdAndDomainId(): void
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
            'cart_item_id' => 7,
            'domain_id' => 10,
            'cart_session_id' => 'session',
            'goods_id' => 3,
        ]);
        $db = $this->createMock(Database::class);
        $db->method('table')->with('shop_carts')->willReturn($query);

        $item = (new CartRepository($db))->findInDomain(10, 7);

        $this->assertContains(['cart_item_id', '=', 7], $whereCalls);
        $this->assertContains(['domain_id', '=', 10], $whereCalls);
        $this->assertInstanceOf(CartItem::class, $item);
    }

    public function testGetItemsAlwaysScopesCurrentDomain(): void
    {
        $whereCalls = [];
        $query = $this->createMock(QueryBuilder::class);
        $query->method('where')->willReturnCallback(
            function (string $column, string $operator, mixed $value) use (&$whereCalls, $query): QueryBuilder {
                $whereCalls[] = [$column, $operator, $value];
                return $query;
            }
        );
        $query->method('orderBy')->willReturnSelf();
        $query->method('get')->willReturn([]);
        $db = $this->createMock(Database::class);
        $db->method('table')->with('shop_carts')->willReturn($query);

        (new CartRepository($db))->getItems('session', 0, 10);

        $this->assertContains(['domain_id', '=', 10], $whereCalls);
        $this->assertContains(['cart_status', '=', 'PENDING'], $whereCalls);
        $this->assertContains(['cart_session_id', '=', 'session'], $whereCalls);
    }
}
