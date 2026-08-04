<?php
/**
 * 네이버 쇼핑 채널 — 피드 행 변환 테스트
 *
 * 피드가 깨지는 방식은 대부분 둘이다. 컬럼 수가 어긋나거나, 값에 탭·개행이 섞여
 * 줄이 밀리는 것. 둘 다 상품 하나 때문에 파일 전체가 거부될 수 있어서 값 변환과
 * 라이터를 함께 검증한다.
 */

namespace Tests\Shop\Unit\PriceCompare;

use Tests\Shop\TestCase;
use Mublo\Packages\Shop\PriceCompare\Channel\NaverShopping;
use Mublo\Packages\Shop\PriceCompare\FeedItem;
use Mublo\Packages\Shop\PriceCompare\Writer\TsvWriter;

class NaverShoppingTest extends TestCase
{
    private function makeItem(array $overrides = []): FeedItem
    {
        $data = array_merge([
            'goodsId'        => 42,
            'name'           => '테스트 상품',
            'url'            => 'https://example.com/shop/products/42/test-product',
            'trackedUrl'     => 'https://example.com/shop/products/42/test-product?k=naver-shopping',
            'mainImageUrl'   => 'https://example.com/storage/main.jpg',
            'extraImageUrls' => ['https://example.com/storage/sub1.jpg'],
            'categoryNames'  => ['가전', '주방가전'],
            'manufacturer'   => '테스트제조',
            'origin'         => '국내',
            'tags'           => ['태그1', '태그2'],
            'price'          => 19900,
            'normalPrice'    => 25000,
            'shippingFee'    => 3000,
            'updatedAt'      => '2026-07-30 11:22:33',
        ], $overrides);

        return new FeedItem(...$data);
    }

    /** 컬럼 수와 행의 값 수가 어긋나면 그 줄부터 파일이 밀린다 */
    public function testRowCountMatchesColumnCount(): void
    {
        $channel = new NaverShopping();

        $this->assertCount(
            count($channel->columns()),
            $channel->row($this->makeItem())
        );
    }

    /** 링크는 조회층이 만든 추적 URL 을 그대로 싣는다(키 결정은 채널 밖) */
    public function testLinkCarriesTrackedUrl(): void
    {
        $channel = new NaverShopping();
        $row = array_combine($channel->columns(), $channel->row($this->makeItem()));

        $this->assertSame(
            'https://example.com/shop/products/42/test-product?k=naver-shopping',
            $row['link']
        );
        // 반응형이라 모바일도 같은 URL을 쓴다
        $this->assertSame($row['link'], $row['mobile_link']);
    }

    /** 상품에 없는 값(브랜드)은 비운다 — 제조사로 대체하면 오등록이 된다 */
    public function testBrandStaysEmptyWhileMakerIsFilled(): void
    {
        $channel = new NaverShopping();
        $row = array_combine($channel->columns(), $channel->row($this->makeItem()));

        $this->assertSame('', $row['brand']);
        $this->assertSame('테스트제조', $row['maker']);
    }

    /** 카테고리는 있는 깊이까지만 채우고 나머지는 빈 칸으로 남는다 */
    public function testCategoryColumnsFillOnlyAvailableDepth(): void
    {
        $channel = new NaverShopping();
        $row = array_combine($channel->columns(), $channel->row($this->makeItem()));

        $this->assertSame('가전', $row['category_name1']);
        $this->assertSame('주방가전', $row['category_name2']);
        $this->assertSame('', $row['category_name3']);
        $this->assertSame('', $row['category_name4']);
    }

    public function testUpdateTimeIsFormattedAsDate(): void
    {
        $channel = new NaverShopping();
        $row = array_combine($channel->columns(), $channel->row($this->makeItem()));

        $this->assertSame('20260730', $row['update_time']);
    }

    /** 파싱할 수 없는 날짜는 빈 값으로 두고 행 전체를 버리지 않는다 */
    public function testUnparsableUpdateTimeBecomesEmpty(): void
    {
        $channel = new NaverShopping();
        $row = array_combine(
            $channel->columns(),
            $channel->row($this->makeItem(['updatedAt' => '0000-00-00 00:00:00']))
        );

        $this->assertSame('', $row['update_time']);
    }

    /** 상품명에 섞인 탭·개행은 줄을 밀지 않고 공백으로 접힌다 */
    public function testWriterFoldsTabsAndNewlinesInValues(): void
    {
        $channel = new NaverShopping();
        $writer = new TsvWriter();

        $tsv = $writer->render(
            $channel->columns(),
            [$channel->row($this->makeItem(['name' => "줄바꿈\n섞인\t상품명"]))]
        );

        $lines = explode("\n", trim($tsv));

        $this->assertCount(2, $lines, '헤더 1줄 + 상품 1줄이어야 한다');
        $this->assertCount(
            count($channel->columns()),
            explode("\t", $lines[1])
        );
        $this->assertStringContainsString('줄바꿈 섞인 상품명', $lines[1]);
    }

    /** 상품이 0건이어도 컬럼명 줄은 남아야 한다 */
    public function testHeaderSurvivesEmptyFeed(): void
    {
        $channel = new NaverShopping();
        $writer = new TsvWriter();

        $tsv = $writer->render($channel->columns(), []);

        $this->assertSame($channel->columns(), explode("\t", trim($tsv)));
    }
}
