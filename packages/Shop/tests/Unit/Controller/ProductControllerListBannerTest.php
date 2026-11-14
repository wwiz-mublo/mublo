<?php

namespace Tests\Shop\Unit\Controller;

use Mublo\Packages\Shop\Controller\Front\ProductController;
use Mublo\Packages\Shop\Service\CategoryService;
use PHPUnit\Framework\TestCase;

/**
 * 목록 안내 이미지의 분류 경로 해석 (Front ProductController)
 *
 * 분류 트리에서 현재 분류까지의 조상 경로를 만들어 CategoryService 에 넘긴다.
 * 분류 코드 모양(20 → 2010 → 201010)이 아니라 트리 구조로 조상을 찾는다.
 */
class ProductControllerListBannerTest extends TestCase
{
    private function tree(): array
    {
        return [
            ['code' => '20', 'label' => '삼성전자', 'children' => [
                ['code' => '2010', 'label' => 'TV', 'children' => [
                    ['code' => '201010', 'label' => 'QLED', 'children' => []],
                ]],
            ]],
            ['code' => '30', 'label' => 'SHOP', 'children' => []],
        ];
    }

    private function resolve(?CategoryService $service, string $code): ?array
    {
        $reflection = new \ReflectionClass(ProductController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('categoryService')->setValue($controller, $service);
        $method = $reflection->getMethod('resolveListBanner');
        return $method->invoke($controller, 1, $this->tree(), $code);
    }

    public function testPassesAncestorPathToService(): void
    {
        $banner = ['category_code' => '20', 'pc' => '/a.png', 'mobile' => '/a.png', 'link' => ''];
        $service = $this->createMock(CategoryService::class);
        $service->expects($this->once())->method('resolveListBanner')
            ->with(1, ['20', '2010', '201010'])
            ->willReturn($banner);

        $this->assertSame($banner, $this->resolve($service, '201010'));
    }

    public function testNoCategoryOrUnknownCategoryHasNoBanner(): void
    {
        $service = $this->createMock(CategoryService::class);
        $service->expects($this->never())->method('resolveListBanner');

        $this->assertNull($this->resolve($service, ''));
        $this->assertNull($this->resolve($service, 'zz'));
    }

    public function testWithoutCategoryServiceKeepsOldBehaviour(): void
    {
        $this->assertNull($this->resolve(null, '20'));
    }

    public function testServiceFailureDoesNotBreakList(): void
    {
        $service = $this->createMock(CategoryService::class);
        $service->method('resolveListBanner')->willThrowException(new \RuntimeException('Unknown column'));

        $this->assertNull(@$this->resolve($service, '30'));
    }
}
