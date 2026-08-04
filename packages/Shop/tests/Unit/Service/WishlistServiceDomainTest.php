<?php

namespace Tests\Shop\Unit\Service;

use Mublo\Packages\Shop\Repository\ProductRepository;
use Mublo\Packages\Shop\Repository\WishlistRepository;
use Mublo\Packages\Shop\Service\WishlistService;
use PHPUnit\Framework\TestCase;

class WishlistServiceDomainTest extends TestCase
{
    public function testToggleRejectsProductOutsideCurrentDomain(): void
    {
        $wishlists = $this->createMock(WishlistRepository::class);
        $products = $this->createMock(ProductRepository::class);
        $products->expects($this->once())->method('findInDomain')->with(7, 99)->willReturn(null);
        $wishlists->expects($this->never())->method('create');

        $result = (new WishlistService($wishlists, $products))->toggle(7, 3, 99);

        $this->assertTrue($result->isFailure());
    }
}
