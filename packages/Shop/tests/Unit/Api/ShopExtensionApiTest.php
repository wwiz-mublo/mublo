<?php

namespace Tests\Shop\Unit\Api;

use Mublo\Core\Container\DependencyContainer;
use Mublo\Core\Extension\PluginHostInterface;
use Mublo\Core\Extension\PluginHostTrait;
use Mublo\Core\Result\Result;
use Mublo\Packages\Shop\Api\ShopCommand;
use Mublo\Packages\Shop\Api\ShopExtensionApi;
use Mublo\Packages\Shop\Api\ShopOrderReader;
use Mublo\Packages\Shop\Api\ShopProductReader;
use Mublo\Packages\Shop\Contract\Extension\ShopCommandInterface;
use Mublo\Packages\Shop\Contract\Extension\ShopExtensionApiInterface;
use Mublo\Packages\Shop\Contract\Extension\ShopOrderReaderInterface;
use Mublo\Packages\Shop\Contract\Extension\ShopProductReaderInterface;
use Mublo\Packages\Shop\Entity\Order;
use Mublo\Packages\Shop\Entity\Product;
use Mublo\Packages\Shop\Repository\OrderRepository;
use Mublo\Packages\Shop\Repository\ProductRepository;
use Mublo\Packages\Shop\Service\OrderService;
use Mublo\Packages\Shop\Service\ProductService;
use Mublo\Packages\Shop\ShopProvider;
use PHPUnit\Framework\TestCase;

class ShopExtensionApiTest extends TestCase
{
    protected function tearDown(): void
    {
        DependencyContainer::resetInstance();
        parent::tearDown();
    }

    public function testShopProviderDeclaresNestedPluginHost(): void
    {
        $provider = new ShopProvider();

        $this->assertInstanceOf(PluginHostInterface::class, $provider);
        $this->assertContains(PluginHostTrait::class, class_uses($provider));
        $this->assertIsArray($provider->discoverPlugins());
    }

    public function testProductReaderReturnsReadonlySnapshotForCurrentDomain(): void
    {
        $repository = $this->createMock(ProductRepository::class);
        $repository->expects($this->once())
            ->method('findInDomain')
            ->with(3, 17)
            ->willReturn($this->product(17, 3));

        $snapshot = (new ShopProductReader($repository))->findAccessibleById(17, 3);

        $this->assertNotNull($snapshot);
        $this->assertSame(17, $snapshot->getGoodsId());
        $this->assertSame(3, $snapshot->getDomainId());
        $this->assertSame('SKU-17', $snapshot->getItemCode());
        $this->assertSame('공개 API 상품', $snapshot->getName());
        $this->assertSame(12000, $snapshot->getDisplayPrice());
        $this->assertTrue($snapshot->isActive());
        $this->assertTrue((new \ReflectionClass($snapshot))->isReadOnly());
    }

    public function testProductReaderRejectsForeignDomain(): void
    {
        $repository = $this->createMock(ProductRepository::class);
        $repository->method('findInDomain')->willReturn(null);

        $this->assertNull(
            (new ShopProductReader($repository))->findAccessibleById(17, 1)
        );
    }

    public function testOrderReaderReturnsPiiFreeReadonlySnapshotForCurrentDomain(): void
    {
        $repository = $this->createMock(OrderRepository::class);
        $repository->expects($this->once())
            ->method('find')
            ->with('ORD-17')
            ->willReturn($this->order('ORD-17', 3));

        $snapshot = (new ShopOrderReader($repository))->findAccessibleByOrderNo('ORD-17', 3);

        $this->assertNotNull($snapshot);
        $this->assertSame('ORD-17', $snapshot->getOrderNo());
        $this->assertSame(3, $snapshot->getDomainId());
        $this->assertSame('paid', $snapshot->getStatus());
        $this->assertSame(11500, $snapshot->getFinalAmount());
        $this->assertSame('BANK', $snapshot->getPaymentMethod());
        $this->assertTrue((new \ReflectionClass($snapshot))->isReadOnly());

        $publicMethods = array_map(
            static fn(\ReflectionMethod $method): string => $method->getName(),
            (new \ReflectionClass($snapshot))->getMethods(\ReflectionMethod::IS_PUBLIC)
        );
        $this->assertNotContains('getOrdererName', $publicMethods);
        $this->assertNotContains('getRecipientPhone', $publicMethods);
        $this->assertNotContains('getShippingAddress1', $publicMethods);
    }

    public function testOrderReaderRejectsForeignDomain(): void
    {
        $repository = $this->createMock(OrderRepository::class);
        $repository->method('find')->willReturn($this->order('ORD-17', 2));

        $this->assertNull(
            (new ShopOrderReader($repository))->findAccessibleByOrderNo('ORD-17', 1)
        );
    }

    public function testCommandDelegatesProductDeleteWithDomainBoundary(): void
    {
        $expected = Result::success('deleted');
        $products = $this->createMock(ProductService::class);
        $products->expects($this->once())
            ->method('delete')
            ->with(9, 3)
            ->willReturn($expected);

        $command = new ShopCommand(
            $products,
            $this->createMock(OrderService::class),
            $this->createMock(OrderRepository::class)
        );

        $this->assertSame($expected, $command->deleteProduct(9, 3));
    }

    public function testCommandChangesStatusOnlyForCurrentDomain(): void
    {
        $repository = $this->createMock(OrderRepository::class);
        $repository->method('find')->with('ORD-17')->willReturn($this->order('ORD-17', 3));
        $orders = $this->createMock(OrderService::class);
        $expected = Result::success('changed');
        $orders->expects($this->once())
            ->method('updateStatus')
            ->with('ORD-17', 'shipping', 3, '택배사 연동', 'SYSTEM')
            ->willReturn($expected);

        $command = new ShopCommand($this->createMock(ProductService::class), $orders, $repository);

        $this->assertSame(
            $expected,
            $command->changeOrderStatus('ORD-17', 'shipping', 3, '택배사 연동')
        );
    }

    public function testCommandRejectsForeignOrderBeforeServiceCall(): void
    {
        $repository = $this->createMock(OrderRepository::class);
        $repository->method('find')->willReturn($this->order('ORD-17', 2));
        $orders = $this->createMock(OrderService::class);
        $orders->expects($this->never())->method('updateStatus');

        $result = (new ShopCommand(
            $this->createMock(ProductService::class),
            $orders,
            $repository
        ))->changeOrderStatus('ORD-17', 'shipping', 1);

        $this->assertTrue($result->isFailure());
        $this->assertSame('주문을 찾을 수 없습니다.', $result->getMessage());
    }

    public function testFacadeExposesOnlyPublicContracts(): void
    {
        $products = $this->createMock(ShopProductReaderInterface::class);
        $orders = $this->createMock(ShopOrderReaderInterface::class);
        $commands = $this->createMock(ShopCommandInterface::class);
        $api = new ShopExtensionApi($products, $orders, $commands);

        $this->assertSame($products, $api->products());
        $this->assertSame($orders, $api->orders());
        $this->assertSame($commands, $api->commands());
    }

    public function testShopProviderBindsPublicApiFacade(): void
    {
        $container = DependencyContainer::getInstance();
        (new ShopProvider())->register($container);
        $container->set(ProductRepository::class, $this->createMock(ProductRepository::class));
        $container->set(OrderRepository::class, $this->createMock(OrderRepository::class));
        $container->set(ProductService::class, $this->createMock(ProductService::class));
        $container->set(OrderService::class, $this->createMock(OrderService::class));

        $api = $container->get(ShopExtensionApiInterface::class);

        $this->assertInstanceOf(ShopExtensionApiInterface::class, $api);
        $this->assertInstanceOf(ShopProductReaderInterface::class, $api->products());
        $this->assertInstanceOf(ShopOrderReaderInterface::class, $api->orders());
        $this->assertInstanceOf(ShopCommandInterface::class, $api->commands());
    }

    private function product(int $goodsId, int $domainId): Product
    {
        return Product::fromArray([
            'goods_id' => $goodsId,
            'domain_id' => $domainId,
            'item_code' => 'SKU-17',
            'goods_name' => '공개 API 상품',
            'goods_slug' => 'public-product',
            'category_code' => 'goods',
            'display_price' => 12000,
            'stock_quantity' => 8,
            'is_active' => 1,
            'created_at' => '2026-07-22 10:00:00',
        ]);
    }

    private function order(string $orderNo, int $domainId): Order
    {
        return Order::fromArray([
            'order_no' => $orderNo,
            'domain_id' => $domainId,
            'member_id' => 7,
            'total_price' => 12000,
            'extra_price' => 0,
            'shipping_fee' => 500,
            'tax_amount' => 0,
            'point_used' => 500,
            'coupon_discount' => 500,
            'payment_method' => 'BANK',
            'order_status' => 'paid',
            'is_complete' => 0,
            'created_at' => '2026-07-22 10:00:00',
        ]);
    }
}
