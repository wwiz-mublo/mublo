<?php

namespace Tests\Shop\Unit\Helper;

use Mublo\Packages\Shop\Helper\ProductSeoPresenter;
use Tests\Shop\TestCase;

/**
 * ProductSeoPresenter — 상품 화면의 검색엔진 메타데이터.
 *
 * 배경: Front 상품 컨트롤러가 코어 프레임(Head.php)이 읽는 SEO 변수를 하나도
 * 넘기지 않아, 모든 상품 상세가 사이트 기본 title/description/og:image 로
 * 폴백됐다. 상품이 몇 개든 검색 결과에서 구분되지 않는 상태였다.
 *
 * 여기서 고정하는 계약:
 *  - 값이 없으면 키를 아예 넣지 않는다 (빈 문자열을 넣으면 코어 폴백이 죽는다)
 *  - 후기가 없으면 aggregateRating 을 넣지 않는다 (구글이 오류로 처리)
 *  - 이미지·링크는 절대 URL 로 나간다
 */
class ProductSeoPresenterTest extends TestCase
{
    private function presenter(): ProductSeoPresenter
    {
        return new ProductSeoPresenter('https://shop.example.com');
    }

    /** @return array<string,mixed> */
    private function product(array $overrides = []): array
    {
        return array_merge([
            'goods_id'           => 12,
            'goods_name'         => '린넨 셔츠',
            'goods_code'         => 'SKU-12',
            'goods_manufacturer' => '무블로',
            'main_image_url'     => '/storage/shop/12.jpg',
            'url'                => '/shop/products/12/linen-shirt',
            'sales_price'        => 39000,
            'is_soldout'         => false,
            'review_count'       => 0,
            'average_rating'     => 0.0,
            'details'            => [],
        ], $overrides);
    }

    /** @return array<int,array{label:string,link:string}> */
    private function categoryPath(): array
    {
        return [
            ['label' => '의류', 'link' => '/shop/category/001'],
            ['label' => '셔츠', 'link' => '/shop/category/001001'],
        ];
    }

    public function testDetailSuppliesTitleAndImageSoProductsAreNotAllIdentical(): void
    {
        $meta = $this->presenter()->detail($this->product());

        $this->assertSame('린넨 셔츠', $meta['seoTitle']);
        $this->assertSame('product', $meta['pageOgType']);
        $this->assertSame('https://shop.example.com/storage/shop/12.jpg', $meta['pageOgImage']);
    }

    public function testDetailReturnsNothingWhenProductHasNoName(): void
    {
        $this->assertSame([], $this->presenter()->detail(['goods_name' => '   ']));
    }

    public function testAbsoluteImageUrlIsLeftAlone(): void
    {
        $meta = $this->presenter()->detail(
            $this->product(['main_image_url' => 'https://cdn.example.net/a.jpg'])
        );

        $this->assertSame('https://cdn.example.net/a.jpg', $meta['pageOgImage']);
    }

    public function testMissingImageOmitsTheKeyRatherThanSendingEmptyString(): void
    {
        $meta = $this->presenter()->detail($this->product(['main_image_url' => '']));

        // 빈 문자열을 넘기면 코어의 사이트 기본 og:image 폴백이 무력화된다
        $this->assertArrayNotHasKey('pageOgImage', $meta);
    }

    public function testDescriptionComesFromDetailHtmlStrippedOfTags(): void
    {
        $meta = $this->presenter()->detail($this->product([
            'details' => [
                ['detail_value' => '<p>부드러운 <strong>린넨</strong> 소재</p><p>여름용</p>'],
            ],
        ]));

        $this->assertSame('부드러운 린넨 소재 여름용', $meta['seoDescription']);
    }

    public function testDescriptionIsTruncatedWithEllipsis(): void
    {
        $long = str_repeat('가', 300);
        $meta = $this->presenter()->detail($this->product([
            'details' => [['detail_value' => $long]],
        ]));

        $this->assertLessThanOrEqual(160, mb_strlen($meta['seoDescription']));
        $this->assertStringEndsWith('…', $meta['seoDescription']);
    }

    public function testDescriptionFallsBackToCategoryAndNameWhenNoDetailHtml(): void
    {
        $meta = $this->presenter()->detail($this->product(), $this->categoryPath());

        $this->assertSame('의류 > 셔츠 | 무블로 | 린넨 셔츠', $meta['seoDescription']);
    }

    public function testProductJsonLdCarriesOfferAndAvailability(): void
    {
        $meta = $this->presenter()->detail($this->product(), $this->categoryPath());
        $product = $this->findNode($meta['pageJsonLd'], 'Product');

        $this->assertSame('린넨 셔츠', $product['name']);
        $this->assertSame('SKU-12', $product['sku']);
        $this->assertSame('무블로', $product['brand']['name']);
        $this->assertSame('의류 > 셔츠', $product['category']);
        $this->assertSame('39000', $product['offers']['price']);
        $this->assertSame('KRW', $product['offers']['priceCurrency']);
        $this->assertSame('https://schema.org/InStock', $product['offers']['availability']);
        $this->assertSame(
            'https://shop.example.com/shop/products/12/linen-shirt',
            $product['offers']['url']
        );
    }

    public function testSoldOutProductReportsOutOfStock(): void
    {
        $meta = $this->presenter()->detail($this->product(['is_soldout' => true]));
        $product = $this->findNode($meta['pageJsonLd'], 'Product');

        $this->assertSame('https://schema.org/OutOfStock', $product['offers']['availability']);
    }

    public function testAggregateRatingIsOmittedWithoutReviews(): void
    {
        $meta = $this->presenter()->detail($this->product());
        $product = $this->findNode($meta['pageJsonLd'], 'Product');

        // 후기 0건에 aggregateRating 을 실으면 구글이 구조화 데이터 오류로 잡는다
        $this->assertArrayNotHasKey('aggregateRating', $product);
    }

    public function testAggregateRatingAppearsWhenReviewsExist(): void
    {
        $meta = $this->presenter()->detail($this->product([
            'review_count'   => 7,
            'average_rating' => 4.28,
        ]));
        $product = $this->findNode($meta['pageJsonLd'], 'Product');

        $this->assertSame(4.3, $product['aggregateRating']['ratingValue']);
        $this->assertSame(7, $product['aggregateRating']['reviewCount']);
    }

    public function testBreadcrumbListEndsWithTheProductItself(): void
    {
        $meta = $this->presenter()->detail($this->product(), $this->categoryPath());
        $crumbs = $this->findNode($meta['pageJsonLd'], 'BreadcrumbList');

        $this->assertCount(3, $crumbs['itemListElement']);
        $this->assertSame('의류', $crumbs['itemListElement'][0]['name']);
        $this->assertSame(
            'https://shop.example.com/shop/category/001',
            $crumbs['itemListElement'][0]['item']
        );
        $this->assertSame(3, $crumbs['itemListElement'][2]['position']);
        $this->assertSame('린넨 셔츠', $crumbs['itemListElement'][2]['name']);
    }

    public function testNoBreadcrumbWithoutCategoryPath(): void
    {
        $meta = $this->presenter()->detail($this->product());

        // 카테고리가 없으면 Product 노드만 남고 @graph 로 감싸지 않는다
        $this->assertSame('Product', $meta['pageJsonLd']['@type']);
    }

    public function testListTitleDistinguishesCategoryKeywordAndAll(): void
    {
        $p = $this->presenter();

        $this->assertSame('전체 상품', $p->list('', '', 42)['seoTitle']);
        $this->assertSame('셔츠', $p->list('셔츠', '', 8)['seoTitle']);
        $this->assertSame("'린넨' 검색 결과", $p->list('셔츠', '린넨', 3)['seoTitle']);
    }

    public function testListDescriptionReportsTheCount(): void
    {
        $meta = $this->presenter()->list('셔츠', '', 1234);

        $this->assertSame('셔츠 카테고리의 상품 1,234개를 모았습니다.', $meta['seoDescription']);
    }

    /**
     * pageJsonLd 는 노드가 하나면 그 자체, 여럿이면 @graph 로 묶인다.
     *
     * @param array<string,mixed> $jsonLd
     * @return array<string,mixed>
     */
    private function findNode(array $jsonLd, string $type): array
    {
        if (($jsonLd['@type'] ?? null) === $type) {
            return $jsonLd;
        }
        foreach ($jsonLd['@graph'] ?? [] as $node) {
            if (($node['@type'] ?? null) === $type) {
                return $node;
            }
        }
        $this->fail(sprintf('JSON-LD 에 %s 노드가 없습니다.', $type));
    }
}
