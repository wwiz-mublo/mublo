<?php

namespace Tests\VisitorStats\Unit\Service;

use Mublo\Core\Session\SessionInterface;
use Mublo\Plugin\VisitorStats\Repository\VisitorCampaignRepository;
use Mublo\Plugin\VisitorStats\Repository\VisitorDailyRepository;
use Mublo\Plugin\VisitorStats\Repository\VisitorHourlyRepository;
use Mublo\Plugin\VisitorStats\Repository\VisitorLogRepository;
use Mublo\Plugin\VisitorStats\Repository\VisitorPageRepository;
use Mublo\Plugin\VisitorStats\Repository\VisitorReferrerRepository;
use Mublo\Plugin\VisitorStats\Service\GeoIpService;
use Mublo\Plugin\VisitorStats\Service\VisitorCollector;
use PHPUnit\Framework\TestCase;

/**
 * GeoIP 선택 기능: 미설정 시 수집이 기존과 동일하게 유지되는지 검증
 */
final class GeoIpTest extends TestCase
{
    public function testServiceReturnsEmptyWhenDbFileMissing(): void
    {
        $service = new GeoIpService(sys_get_temp_dir() . '/no-such-geoip-' . uniqid() . '.mmdb');

        self::assertFalse($service->isAvailable());
        self::assertSame(['country_code' => '', 'city' => ''], $service->lookup('8.8.8.8'));
        self::assertSame(['country_code' => '', 'city' => ''], $service->lookup(''));
    }

    public function testCollectorOmitsGeoColumnsWhenLookupIsEmpty(): void
    {
        $data = $this->trackWithGeo(['country_code' => '', 'city' => '']);

        self::assertArrayNotHasKey('country_code', $data);
        self::assertArrayNotHasKey('city', $data);
    }

    public function testCollectorOmitsGeoColumnsWithoutGeoService(): void
    {
        $data = $this->trackWithGeo(null);

        self::assertArrayNotHasKey('country_code', $data);
        self::assertArrayNotHasKey('city', $data);
    }

    public function testCollectorStoresGeoColumnsWhenResolved(): void
    {
        $data = $this->trackWithGeo(['country_code' => 'KR', 'city' => '서울특별시 강남구']);

        self::assertSame('KR', $data['country_code']);
        self::assertSame('서울특별시 강남구', $data['city']);
        self::assertSame('203.0.113.7', $data['ip_address']);
    }

    /**
     * @param array{country_code: string, city: string}|null $geo null이면 GeoIpService 미주입
     * @return array<string, mixed> insertIgnore에 전달된 데이터
     */
    private function trackWithGeo(?array $geo): array
    {
        $captured = [];
        $logRepo = $this->createMock(VisitorLogRepository::class);
        $logRepo->method('isNewVisitor')->willReturn(true);
        $logRepo->expects(self::once())
            ->method('insertIgnore')
            ->willReturnCallback(function (array $data) use (&$captured): bool {
                $captured = $data;
                return false; // 중복 경로로 빠르게 종료
            });

        $session = $this->createMock(SessionInterface::class);
        $session->method('has')->willReturn(false);
        $session->method('getId')->willReturn('sess-1');

        $geoService = null;
        if ($geo !== null) {
            $geoService = $this->createMock(GeoIpService::class);
            $geoService->method('lookup')->with('203.0.113.7')->willReturn($geo);
        }

        $collector = new VisitorCollector(
            $logRepo,
            $this->createMock(VisitorDailyRepository::class),
            $this->createMock(VisitorHourlyRepository::class),
            $this->createMock(VisitorPageRepository::class),
            $this->createMock(VisitorReferrerRepository::class),
            $this->createMock(VisitorCampaignRepository::class),
            $session,
            $geoService,
        );

        $collector->track(
            domainId: 1,
            ipAddress: '203.0.113.7',
            userAgent: 'Mozilla/5.0',
            uri: '/goods?id=1',
            referer: null,
            memberId: null,
            siteDomain: 'example.com',
        );

        return $captured;
    }
}
