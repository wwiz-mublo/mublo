<?php

namespace Tests\Shop\Unit\Controller;

use Mublo\Core\Context\Context;
use Mublo\Core\Http\Request;
use Mublo\Core\Result\Result;
use Mublo\Packages\Shop\Controller\Admin\ProductController;
use Mublo\Packages\Shop\Service\ProductService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProductControllerActiveTest extends TestCase
{
    #[DataProvider('invalidPayloads')]
    public function testInvalidPayloadDoesNotChangeProducts(mixed $ids, mixed $active): void
    {
        $service = $this->createMock(ProductService::class);
        $service->expects($this->never())->method('changeActiveMultiple');
        $this->assertFalse($this->controller($service)->listActive([], $this->context($ids, $active))->isSuccess());
    }

    public static function invalidPayloads(): array
    {
        return [[[], true], [7, true], [null, true], [[7], null], [[7], 'false'], [[7], 2], [[7], []]];
    }

    #[DataProvider('validStates')]
    public function testPassesExplicitStateAndCurrentDomainToService(mixed $input, bool $expected): void
    {
        $service = $this->createMock(ProductService::class);
        $service->expects($this->once())->method('changeActiveMultiple')->with([7], $expected, 10)
            ->willReturn(Result::success('저장됨', ['changed' => 1, 'is_active' => $expected]));
        $response = $this->controller($service)->listActive([], $this->context([7], $input));
        $this->assertTrue($response->isSuccess());
        $this->assertSame($expected, $response->getData()['data']['is_active']);
    }

    public static function validStates(): array
    {
        return [[true, true], [false, false], [1, true], [0, false], ['1', true], ['0', false]];
    }

    public function testServiceFailureIsReturnedToClient(): void
    {
        $service = $this->createMock(ProductService::class);
        $service->method('changeActiveMultiple')->willReturn(Result::failure('권한이 없는 상품이 포함되어 있습니다.'));
        $response = $this->controller($service)->listActive([], $this->context([7], false));
        $this->assertFalse($response->isSuccess());
        $this->assertSame('권한이 없는 상품이 포함되어 있습니다.', $response->getData()['message']);
    }

    private function controller(ProductService $service): ProductController
    {
        $reflection = new \ReflectionClass(ProductController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('productService')->setValue($controller, $service);
        return $controller;
    }

    private function context(mixed $ids, mixed $active): Context
    {
        $request = $this->createMock(Request::class);
        $request->method('json')->willReturnCallback(static fn($key, $default = null) => match ($key) {
            'goods_ids' => $ids,
            'is_active' => $active,
            default => $default,
        });
        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getDomainId')->willReturn(10);
        return $context;
    }
}
