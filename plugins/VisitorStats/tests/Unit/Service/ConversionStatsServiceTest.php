<?php
declare(strict_types=1);

namespace Tests\VisitorStats\Unit\Service;

use Mublo\Contract\Tracking\ConversionSourceTypes;
use Mublo\Plugin\VisitorStats\Repository\ConversionEventRepository;
use Mublo\Plugin\VisitorStats\Repository\VisitorCampaignKeyRepository;
use Mublo\Plugin\VisitorStats\Repository\VisitorCampaignRepository;
use Mublo\Plugin\VisitorStats\Service\ConversionStatsService;
use Mublo\Plugin\VisitorStats\Service\VisitorStatsService;
use PHPUnit\Framework\TestCase;

/**
 * 전환 통계가 이벤트 저장소만 읽는지 고정한다.
 *
 * 이전에는 폼 확장의 테이블을 직접 조회했고, 그 확장이 없는 설치본에서는 전환 화면이
 * 통째로 0 이었다. 이제 전환은 ConversionRecordedEvent 로만 들어오므로 어떤 확장이
 * 붙어 있든 같은 경로로 집계된다.
 */
class ConversionStatsServiceTest extends TestCase
{
    private function service(ConversionEventRepository $eventRepo): ConversionStatsService
    {
        $statsService = $this->createMock(VisitorStatsService::class);
        $statsService->method('periodToDates')->willReturn(['2026-08-01', '2026-08-03']);

        $campaignRepo = $this->createMock(VisitorCampaignRepository::class);
        $campaignRepo->method('getByKeys')->willReturn([
            ['campaign_key' => 'summer', 'visitors' => 100, 'pageviews' => 250],
        ]);

        $campaignKeyRepo = $this->createMock(VisitorCampaignKeyRepository::class);
        $campaignKeyRepo->method('getAll')->willReturn([
            ['campaign_key' => 'summer', 'group_name' => '여름 프로모션'],
        ]);

        return new ConversionStatsService($eventRepo, $campaignRepo, $campaignKeyRepo, $statsService);
    }

    public function testConversionStatsAggregatesFromTheEventStore(): void
    {
        $repo = $this->createMock(ConversionEventRepository::class);
        $repo->method('totals')->willReturn(['total' => 10, 'conversions' => 4]);
        $repo->method('dailyTrend')->willReturn([
            ['conv_date' => '2026-08-01', 'total' => 6, 'conversions' => 3],
            // 08-02 는 통보가 없다 — 빈 날짜가 0 으로 채워지는지 본다.
            ['conv_date' => '2026-08-03', 'total' => 4, 'conversions' => 1],
        ]);
        // 소스 타입은 발행 확장이 정한다 — 코어 상수에 등재되지 않은 임의 문자열도
        // 그대로 집계된다. 아래는 실재하는 확장이 아니라 그 성질을 보이기 위한 예시다.
        // 같은 타입이라도 라벨이 다르면 갈래별로 갈라진다(폼별·상품군별).
        $repo->method('summaryBySource')->willReturn([
            ['source_type' => 'contact_form', 'source_label' => '문의 폼', 'total' => 6, 'conversions' => 3, 'value_sum' => null],
            ['source_type' => 'contact_form', 'source_label' => '견적 요청', 'total' => 2, 'conversions' => 2, 'value_sum' => null],
            ['source_type' => 'store_order', 'source_label' => '스토어 주문', 'total' => 4, 'conversions' => 1, 'value_sum' => '129000.00'],
        ]);
        $repo->method('summaryByCampaign')->willReturn([
            ['campaign_key' => 'summer', 'total' => 7, 'conversions' => 3, 'value_sum' => null, 'top_source' => '문의 폼'],
        ]);

        $result = $this->service($repo)->getConversionStats(1, 'last_7_days');

        $this->assertSame(4, $result['total']);
        $this->assertSame(10, $result['recorded']);
        $this->assertSame(40.0, $result['conversionRate']);
        $this->assertSame(129000.0, $result['totalValue']);

        // 기간 전체가 날짜별로 채워진다 (3일)
        $this->assertCount(3, $result['dailyTrend']);
        $this->assertSame(
            ['date' => '2026-08-02', 'recorded' => 0, 'conversions' => 0],
            $result['dailyTrend'][1]
        );

        // 같은 타입이라도 라벨이 다르면 별도 행으로 남는다 (뭉뚱그리지 않는다)
        $this->assertCount(3, $result['bySource']);
        $this->assertSame('문의 폼', $result['bySource'][0]['source_label']);
        $this->assertSame('견적 요청', $result['bySource'][1]['source_label']);

        // 최다 소스는 계약이 실어 온 라벨로 나온다
        $this->assertSame('문의 폼', $result['topSource']['source_label']);
        $this->assertSame(3, $result['topSource']['conversions']);
        $this->assertSame('summer', $result['topCampaign']['campaign_key']);
    }

    public function testEmptyEventStoreYieldsZeroesInsteadOfFailing(): void
    {
        $repo = $this->createMock(ConversionEventRepository::class);
        $repo->method('totals')->willReturn(['total' => 0, 'conversions' => 0]);
        $repo->method('dailyTrend')->willReturn([]);
        $repo->method('summaryBySource')->willReturn([]);
        $repo->method('summaryByCampaign')->willReturn([]);

        $result = $this->service($repo)->getConversionStats(1, 'last_7_days');

        $this->assertSame(0, $result['total']);
        $this->assertSame(0.0, $result['conversionRate']);
        $this->assertNull($result['topSource']);
        $this->assertNull($result['topCampaign']);
        $this->assertCount(3, $result['dailyTrend']);
    }

    public function testCampaignSummaryJoinsVisitsWithEventConversions(): void
    {
        $repo = $this->createMock(ConversionEventRepository::class);
        $repo->method('summaryByCampaign')->willReturn([
            ['campaign_key' => 'summer', 'total' => 30, 'conversions' => 25, 'value_sum' => null, 'top_source' => '문의 폼'],
            // 캠페인 없이 들어온 전환은 (직접접속) 행으로 따로 붙는다
            ['campaign_key' => '', 'total' => 5, 'conversions' => 5, 'value_sum' => null, 'top_source' => null],
        ]);

        $result = $this->service($repo)->getCampaignSummary(1, 'last_7_days');

        $this->assertSame(100, $result['totalVisitors']);
        $this->assertSame(30, $result['totalConversions']);

        $this->assertSame('summer', $result['items'][0]['campaign_key']);
        $this->assertSame('여름 프로모션', $result['items'][0]['group_name']);
        $this->assertSame(25, $result['items'][0]['conversions']);
        $this->assertSame(25.0, $result['items'][0]['rate']);

        $this->assertSame('', $result['items'][1]['campaign_key']);
        $this->assertSame(5, $result['items'][1]['conversions']);
    }

    public function testConversionListPassesSourceFilterThrough(): void
    {
        $repo = $this->createMock(ConversionEventRepository::class);
        $repo->expects($this->once())
            ->method('listConversions')
            ->with(1, '2026-08-01', '2026-08-03', 'contact_form', '견적 요청', '', 2, 20)
            ->willReturn([
                'items' => [['conversion_id' => 9, 'source_type' => 'contact_form', 'status' => 'success']],
                'total' => 41,
            ]);
        $repo->method('sourceOptions')->willReturn([
            ['source_type' => 'contact_form', 'source_label' => '문의 폼'],
            // 발행 쪽이 라벨을 안 실어 보낸 경우 (코어에 등재된 실제 타입)
            ['source_type' => ConversionSourceTypes::MEMBER_SIGNUP, 'source_label' => null],
        ]);

        // 타입 안의 갈래(라벨)까지 필터로 내려가고, 빈 문자열 캠페인 키는
        // "직접접속"이라 null 로 뭉개지면 안 된다
        $result = $this->service($repo)->getConversionList(1, 'last_7_days', 'contact_form', '견적 요청', '', 2);

        $this->assertSame(41, $result['totalItems']);
        $this->assertSame(3, $result['totalPages']);
        $this->assertSame(2, $result['currentPage']);

        // 라벨이 없는 소스는 표시용으로 타입 문자열이 채워지되, 필터가 둘을
        // 구분할 수 있도록 has_label 로 표시된다
        $this->assertTrue($result['sources'][0]['has_label']);
        $this->assertSame('member_signup', $result['sources'][1]['source_label']);
        $this->assertFalse($result['sources'][1]['has_label']);
    }

    public function testDashboardComparesWithPreviousPeriod(): void
    {
        $repo = $this->createMock(ConversionEventRepository::class);
        $repo->method('totals')->willReturnCallback(
            static fn(int $domainId, string $start): array => $start === '2026-08-01'
                ? ['total' => 12, 'conversions' => 8]
                : ['total' => 6, 'conversions' => 4]
        );

        $result = $this->service($repo)->getDashboardConversions(1, 'last_7_days');

        $this->assertSame(8, $result['conversions']);
        $this->assertSame(4, $result['prevConversions']);
        $this->assertSame(100.0, $result['change']);
    }
}
