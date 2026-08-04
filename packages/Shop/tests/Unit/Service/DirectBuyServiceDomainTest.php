<?php

namespace Tests\Shop\Unit\Service;

use Mublo\Infrastructure\Session\SessionManager;
use Mublo\Packages\Shop\Repository\ProductRepository;
use Mublo\Packages\Shop\Service\DirectBuyService;
use Mublo\Packages\Shop\Service\PriceCalculator;
use Mublo\Packages\Shop\Service\ShippingFeeCalculator;
use PHPUnit\Framework\TestCase;

class DirectBuyServiceDomainTest extends TestCase
{
    public function testDirectBuySessionFromAnotherDomainIsRejectedBeforeProductLookup(): void
    {
        $products = $this->createMock(ProductRepository::class);
        $products->expects($this->never())->method('findInDomain');

        $session = $this->createMock(SessionManager::class);
        $session->method('get')->with('shop_direct_buy')->willReturn([
            'domain_id' => 2,
            'created_at' => time(),
            'items' => [['goods_id' => 7]],
        ]);

        $service = new DirectBuyService(
            $products,
            new PriceCalculator(),
            $this->createMock(ShippingFeeCalculator::class),
            $session
        );

        $this->assertNull($service->getDirectBuyData(1));
    }
}
