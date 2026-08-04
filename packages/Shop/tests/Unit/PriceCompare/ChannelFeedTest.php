<?php
/**
 * 가격비교 채널 — 형식별 출력 테스트
 *
 * 채널이 늘어도 깨지는 지점은 같다. 컬럼 수와 값 수가 어긋나는 것, 값에 섞인
 * 문자가 형식을 깨뜨리는 것. 그래서 등록된 채널 전체를 한 번에 훑고, 형식별
 * 라이터는 깨뜨릴 값을 넣어 따로 본다.
 */

namespace Tests\Shop\Unit\PriceCompare;

use Tests\Shop\TestCase;
use Mublo\Packages\Shop\Contract\PriceCompare\PriceCompareChannelInterface;
use Mublo\Packages\Shop\PriceCompare\Channel\GoogleShopping;
use Mublo\Packages\Shop\PriceCompare\Channel\KakaoShoppingHow;
use Mublo\Packages\Shop\PriceCompare\Channel\NaverShopping;
use Mublo\Packages\Shop\PriceCompare\FeedItem;
use Mublo\Packages\Shop\PriceCompare\Writer\RssWriter;

class ChannelFeedTest extends TestCase
{
    /** @return list<PriceCompareChannelInterface> */
    private function channels(): array
    {
        return [new NaverShopping(), new KakaoShoppingHow(), new GoogleShopping()];
    }

    private function makeItem(array $overrides = []): FeedItem
    {
        return new FeedItem(...array_merge([
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
        ], $overrides));
    }

    /** @return array<string, string> */
    private function mapped(PriceCompareChannelInterface $channel, FeedItem $item): array
    {
        return array_combine($channel->columns(), $channel->row($item));
    }

    // ─────────────────────────────────────────
    // 모든 채널 공통
    // ─────────────────────────────────────────

    public function testEveryChannelRowMatchesItsColumnCount(): void
    {
        foreach ($this->channels() as $channel) {
            $this->assertCount(
                count($channel->columns()),
                $channel->row($this->makeItem()),
                $channel->code() . ' 채널의 컬럼 수와 값 수가 다릅니다'
            );
        }
    }

    /** 키·라벨·형식·안내문이 비면 라우트와 관리자 화면이 채널을 표현할 수 없다 */
    public function testEveryChannelDeclaresIdentityAndGuide(): void
    {
        $codes = [];

        foreach ($this->channels() as $channel) {
            $code = $channel->code();

            $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', $code);
            $this->assertNotSame('', $channel->label());
            $this->assertContains($channel->format(), ['tsv', 'rss']);
            $this->assertNotEmpty($channel->guide(), $code . ' 채널의 안내문이 비었습니다');

            $codes[] = $code;
        }

        $this->assertSame($codes, array_unique($codes), '채널 키가 겹칩니다');
    }

    /** 유입 파라미터가 없으면 어느 비교사에서 들어온 방문인지 갈리지 않는다 */
    public function testEveryChannelTagsItsLinkWithACampaignKey(): void
    {
        foreach ($this->channels() as $channel) {
            $row = $this->mapped($channel, $this->makeItem());
            $link = $row['link'] ?? '';

            $this->assertStringContainsString('?k=', $link, $channel->code() . ' 채널 링크에 유입 키가 없습니다');
        }
    }

    // ─────────────────────────────────────────
    // 구글 (RSS)
    // ─────────────────────────────────────────

    public function testGoogleMapsPriceAvailabilityAndProductType(): void
    {
        $row = $this->mapped(new GoogleShopping(), $this->makeItem());

        $this->assertSame('19900 KRW', $row['price']);
        $this->assertSame('in stock', $row['availability']);
        $this->assertSame('new', $row['condition']);
        $this->assertSame('가전 > 주방가전', $row['product_type']);
        // 구글 분류는 의도적으로 비운다(대응표를 만들지 않는다)
        $this->assertArrayNotHasKey('google_product_category', $row);
    }

    /** 브랜드가 없으면 identifier_exists=no 를 붙여야 항목이 거부되지 않는다 */
    public function testGoogleDeclaresMissingIdentifierWhenBrandIsEmpty(): void
    {
        $channel = new GoogleShopping();

        $withBrand = $this->mapped($channel, $this->makeItem());
        $this->assertSame('테스트제조', $withBrand['brand']);
        $this->assertSame('', $withBrand['identifier_exists']);

        $withoutBrand = $this->mapped($channel, $this->makeItem(['manufacturer' => '']));
        $this->assertSame('', $withoutBrand['brand']);
        $this->assertSame('no', $withoutBrand['identifier_exists']);
    }

    public function testRssWriterEscapesValuesAndSkipsEmptyElements(): void
    {
        $channel = new GoogleShopping();
        $writer = new RssWriter();

        $xml = $writer->render(
            $channel->columns(),
            [$channel->row($this->makeItem(['name' => '앰퍼샌드 & <태그> 상품']))],
            'example.com',
            'https://example.com'
        );

        // 파서가 통째로 버리지 않는지 — 실제로 파싱해서 확인한다
        $parsed = simplexml_load_string($xml);
        $this->assertNotFalse($parsed, 'RSS 피드가 XML 로 파싱되지 않습니다');

        $this->assertStringContainsString('앰퍼샌드 &amp; &lt;태그&gt; 상품', $xml);
        // 값이 빈 컬럼(identifier_exists)은 요소를 만들지 않는다
        $this->assertStringNotContainsString('<g:identifier_exists>', $xml);
    }

    /** 제어문자가 섞이면 XML 전체가 버려진다 — 파싱되는지로 검증한다 */
    public function testRssWriterSurvivesControlCharacters(): void
    {
        $channel = new GoogleShopping();
        $writer = new RssWriter();

        $xml = $writer->render(
            $channel->columns(),
            [$channel->row($this->makeItem(['name' => "제어문자\x08섞인\x0C상품"]))],
            'example.com',
            'https://example.com'
        );

        $this->assertNotFalse(simplexml_load_string($xml));
    }

    // ─────────────────────────────────────────
    // 카카오 (TSV)
    // ─────────────────────────────────────────

    public function testKakaoCarriesMakerOriginAndShipping(): void
    {
        $row = $this->mapped(new KakaoShoppingHow(), $this->makeItem());

        $this->assertSame('테스트제조', $row['maker']);
        $this->assertSame('국내', $row['origin']);
        $this->assertSame('3000', $row['shipping']);
        $this->assertSame('20260730', $row['update_time']);
    }
}
