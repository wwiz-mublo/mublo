<?php
/**
 * 가격비교 서비스 — 채널 사용 여부 게이트
 *
 * 피드는 전 상품을 한 번에 내주는 공개 주소다. 그래서 "켜지 않으면 내용을 내주지
 * 않는다"는 것이 이 기능의 안전 장치이고, 설정 저장이 실패하거나 기본값이 뒤집히면
 * 조용히 전량 노출된다. 그 지점을 고정한다.
 */

namespace Tests\Shop\Unit\PriceCompare;

use Tests\Shop\TestCase;
use Mublo\Core\Registry\ContractRegistry;
use Mublo\Packages\Shop\Contract\PriceCompare\PriceCompareChannelInterface;
use Mublo\Packages\Shop\PriceCompare\Channel\NaverShopping;
use Mublo\Packages\Shop\PriceCompare\PriceCompareService;
use Mublo\Packages\Shop\Helper\PublicProductCriteria;
use Mublo\Packages\Shop\PriceCompare\ProductFeedQuery;
use Mublo\Packages\Shop\PriceCompare\Writer\RssWriter;
use Mublo\Packages\Shop\PriceCompare\Writer\TsvWriter;
use Mublo\Packages\Shop\Repository\PriceCompareChannelRepository;

class PriceCompareServiceTest extends TestCase
{
    /** @param array<string, array{is_active: bool}> $stored */
    private function makeService(array $stored): PriceCompareService
    {
        $registry = new ContractRegistry();
        $registry->register(
            PriceCompareChannelInterface::class,
            'naver',
            fn() => new NaverShopping()
        );

        // 상품 조회는 실제 클래스를 쓰고 DB만 가짜로 둔다. 테이블 존재 확인이 실패해
        // 상품 0건으로 끝나므로, 이 테스트가 보는 것은 "게이트를 통과했는가"뿐이다.
        $db = $this->createMock(\Mublo\Infrastructure\Database\Database::class);
        $db->method('selectOne')->willReturn(null);
        $db->method('select')->willReturn([]);
        $query = new ProductFeedQuery($db, new PublicProductCriteria($db));

        $repository = $this->createMock(PriceCompareChannelRepository::class);
        $repository->method('findByDomain')->willReturn($stored);

        return new PriceCompareService(
            $registry,
            $query,
            new TsvWriter(),
            new RssWriter(),
            $repository
        );
    }

    /** 설정 행이 아예 없으면 꺼진 것으로 본다 — 설치만으로 열리지 않는다 */
    public function testChannelIsOffWhenNoSettingRowExists(): void
    {
        $service = $this->makeService([]);

        $this->assertNull($service->render('naver', 1, 'https://example.com'));
    }

    public function testChannelIsOffWhenStoredInactive(): void
    {
        $service = $this->makeService(['naver' => ['is_active' => false]]);

        $this->assertNull($service->render('naver', 1, 'https://example.com'));
    }

    /** 켜면 컬럼명 줄만 있는 피드라도 내용을 낸다 */
    public function testChannelServesWhenActive(): void
    {
        $service = $this->makeService(['naver' => ['is_active' => true]]);

        $feed = $service->render('naver', 1, 'https://example.com');

        $this->assertNotNull($feed);
        $this->assertSame('text/plain; charset=utf-8', $feed->contentType);
        $this->assertStringStartsWith('id' . "\t" . 'title', $feed->body);
    }

    /** 설정에 값이 없으면 채널이 선언한 기본 키를 쓴다 */
    public function testCampaignKeyFallsBackToChannelDefault(): void
    {
        $service = $this->makeService(['naver' => ['is_active' => true]]);

        $this->assertSame(
            'naver-shopping',
            $service->campaignKey(new NaverShopping(), 1)
        );
    }

    /** 운영자가 지정한 키가 기본값을 덮는다 — 이미 쓰던 키를 계속 쓸 수 있어야 한다 */
    public function testStoredCampaignKeyOverridesDefault(): void
    {
        $service = $this->makeService([
            'naver' => ['is_active' => true, 'settings' => '{"campaign_key":"np_2026"}'],
        ]);

        $this->assertSame('np_2026', $service->campaignKey(new NaverShopping(), 1));
    }

    /** 링크가 깨지는 값은 저장하지 않는다 */
    public function testSaveRejectsCampaignKeyWithUnsafeCharacters(): void
    {
        $service = $this->makeService([]);

        $result = $service->saveChannels(1, ['naver'], ['naver' => 'bad key&x=1']);

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('캠페인키', $result->getMessage());
    }

    /** 등록되지 않은 코드는 저장하지 않는다 — 오타가 켜진 채널로 남으면 안 된다 */
    public function testSaveRejectsUnknownChannelCode(): void
    {
        $service = $this->makeService([]);

        $result = $service->saveChannels(1, ['naver', 'not-a-channel']);

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('not-a-channel', $result->getMessage());
    }

    public function testSaveAcceptsRegisteredChannelCode(): void
    {
        $service = $this->makeService([]);

        $this->assertTrue($service->saveChannels(1, ['naver'])->isSuccess());
    }
}
