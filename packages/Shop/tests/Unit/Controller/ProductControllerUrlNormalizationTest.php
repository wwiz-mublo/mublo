<?php

namespace Tests\Shop\Unit\Controller;

use Mublo\Core\Context\Context;
use Mublo\Core\Http\Request;
use Mublo\Core\Response\RedirectResponse;
use Mublo\Packages\Shop\Controller\Front\ProductController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * 상품 URL 정규화 — 같은 콘텐츠가 여러 URL 로 200 을 내지 않게 한다.
 *
 * 배경: canonical 은 요청 경로에서 쿼리스트링을 버리고 만들어진다
 * (FrontViewRenderer). 그래서 카테고리를 ?category_code= 로 두면 모든 카테고리
 * 페이지가 /shop/products 하나를 canonical 로 선언해 색인에서 사라진다.
 * 상세도 /products/{id} 와 /products/{id}/{slug} 가 각자 자기를 canonical 로
 * 선언해 중복 콘텐츠가 됐다.
 *
 * 두 경우 모두 정답 URL 하나로 301 로 모은다. 사이트맵(ShopSitemapProvider)이
 * 제출하는 URL 형태와도 이쪽이 일치한다.
 */
class ProductControllerUrlNormalizationTest extends TestCase
{
    /**
     * 리다이렉트는 어떤 서비스보다 먼저 반환되므로 생성자를 돌리지 않고 만든다.
     */
    private function controller(): ProductController
    {
        return (new ReflectionClass(ProductController::class))->newInstanceWithoutConstructor();
    }

    /** @param array<string,mixed> $query */
    private function context(string $path, array $query = []): Context
    {
        $request = $this->createMock(Request::class);
        $request->method('getPath')->willReturn($path);
        $request->method('getQuery')->willReturn($query);
        $request->method('get')->willReturnCallback(
            static fn(string $key, $default = null) => $query[$key] ?? $default
        );

        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getDomainId')->willReturn(1);

        return $context;
    }

    private function assertPermanentRedirect(string $expected, mixed $response): void
    {
        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame(301, $response->getStatusCode());

        $location = (new ReflectionClass($response))->getProperty('location');
        $this->assertSame($expected, $location->getValue($response));
    }

    public function testLegacyCategoryQueryIsMovedToThePathUrl(): void
    {
        $response = $this->controller()->index(
            [],
            $this->context('/shop/products', ['category_code' => '001002'])
        );

        $this->assertPermanentRedirect('/shop/category/001002', $response);
    }

    public function testOtherFiltersSurviveTheCategoryRedirect(): void
    {
        $response = $this->controller()->index(
            [],
            $this->context('/shop/products', [
                'category_code' => '001',
                'sort'          => 'price_asc',
                'page'          => '3',
            ])
        );

        // 카테고리만 경로로 옮기고 나머지 필터는 쿼리에 그대로 남아야 한다
        $this->assertPermanentRedirect('/shop/category/001?sort=price_asc&page=3', $response);
    }

    public function testCategoryCodeIsUrlEncodedInTheRedirect(): void
    {
        $response = $this->controller()->index(
            [],
            $this->context('/shop/products', ['category_code' => 'a b/c'])
        );

        $this->assertPermanentRedirect('/shop/category/a%20b%2Fc', $response);
    }

    public function testPathCategoryRequestIsNotRedirected(): void
    {
        // 이미 정답 URL 이다 — 여기서 리다이렉트하면 무한 루프가 된다.
        // 리다이렉트하지 않으면 컨트롤러는 서비스로 진행하다 죽는다(생성자 미실행).
        $this->expectException(\Error::class);

        $this->controller()->index(
            ['categoryCode' => '001002'],
            $this->context('/shop/category/001002')
        );
    }

    public function testPlainListRequestIsNotRedirected(): void
    {
        $this->expectException(\Error::class);

        $this->controller()->index([], $this->context('/shop/products'));
    }
}
