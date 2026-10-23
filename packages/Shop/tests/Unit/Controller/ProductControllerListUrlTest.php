<?php

namespace Tests\Shop\Unit\Controller;

use Mublo\Core\Context\Context;
use Mublo\Core\Http\Request;
use Mublo\Packages\Shop\Controller\Admin\ProductController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProductControllerListUrlTest extends TestCase
{
    #[DataProvider('sortModes')]
    public function testReturnUrlPreservesSortAndListFilters(string $sort): void
    {
        $params = [
            'keyword' => '테스트 상품',
            'category_code' => '001',
            'is_active' => '0',
            'sort' => $sort,
            'page' => '2',
        ];

        $this->assertSame(
            '/admin/shop/products?' . http_build_query($params),
            $this->listUrl(http_build_query($params + ['unexpected' => 'discard']))
        );
    }

    public static function sortModes(): array
    {
        return [['newest'], ['price_asc'], ['price_desc'], ['popular']];
    }

    public function testMissingReturnUsesDefaultListUrl(): void
    {
        $this->assertSame('/admin/shop/products', $this->listUrl(''));
    }

    private function listUrl(string $return): string
    {
        $request = $this->createMock(Request::class);
        $request->method('query')->with('return', '')->willReturn($return);
        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($request);

        // 목록 URL 구성에는 서비스 의존성이 없다.
        $controller = (new \ReflectionClass(ProductController::class))->newInstanceWithoutConstructor();
        return (new \ReflectionMethod($controller, 'buildListUrl'))->invoke($controller, $context);
    }
}
