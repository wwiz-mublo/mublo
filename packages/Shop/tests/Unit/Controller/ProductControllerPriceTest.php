<?php

namespace Tests\Shop\Unit\Controller;

use Mublo\Core\Context\Context;
use Mublo\Core\Http\Request;
use Mublo\Core\Result\Result;
use Mublo\Packages\Shop\Controller\Admin\ProductController;
use Mublo\Packages\Shop\Service\ProductService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProductControllerPriceTest extends TestCase
{
    #[DataProvider('invalidIds')]
    public function testInvalidProductIdDoesNotReachService(mixed $id): void
    {
        $service = $this->createMock(ProductService::class);
        $service->expects($this->never())->method('changePrice');
        $this->assertFalse($this->controller($service)->listPrice([], $this->context($id, 1000, 0))->isSuccess());
    }

    public static function invalidIds(): array
    {
        return [[null], [0], ['0'], [-1], ['7x'], [[7]], [true]];
    }

    public function testPassesPricesAndCurrentDomainToService(): void
    {
        $service = $this->createMock(ProductService::class);
        $service->expects($this->once())->method('changePrice')->with(7, '9000', '12000', 10)
            ->willReturn(Result::success('가격을 바꿨습니다.', ['goods_id' => 7, 'display_price' => 9000, 'origin_price' => 12000]));
        $response = $this->controller($service)->listPrice([], $this->context('7', '9000', '12000'));
        $this->assertTrue($response->isSuccess());
        $this->assertSame(9000, $response->getData()['data']['display_price']);
    }

    public function testServiceFailureIsReturnedToClient(): void
    {
        $service = $this->createMock(ProductService::class);
        $service->method('changePrice')->willReturn(Result::failure('판매가를 0 이상의 정수로 입력해 주세요.'));
        $response = $this->controller($service)->listPrice([], $this->context(7, '-1', 0));
        $this->assertFalse($response->isSuccess());
        $this->assertSame('판매가를 0 이상의 정수로 입력해 주세요.', $response->getData()['message']);
    }

    private function controller(ProductService $service): ProductController
    {
        $reflection = new \ReflectionClass(ProductController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('productService')->setValue($controller, $service);
        return $controller;
    }

    private function context(mixed $id, mixed $display, mixed $origin): Context
    {
        $request = $this->createMock(Request::class);
        $request->method('json')->willReturnCallback(static fn($key, $default = null) => match ($key) {
            'goods_id' => $id,
            'display_price' => $display,
            'origin_price' => $origin,
            default => $default,
        });
        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getDomainId')->willReturn(10);
        return $context;
    }
}
