<?php

namespace Tests\VisitorStats\Unit\Service;

use Mublo\Plugin\VisitorStats\Repository\VisitorCampaignKeyRepository;
use Mublo\Plugin\VisitorStats\Repository\VisitorCampaignRepository;
use Mublo\Plugin\VisitorStats\Repository\VisitorDailyRepository;
use Mublo\Plugin\VisitorStats\Repository\VisitorHourlyRepository;
use Mublo\Plugin\VisitorStats\Repository\VisitorLogRepository;
use Mublo\Plugin\VisitorStats\Repository\VisitorPageRepository;
use Mublo\Plugin\VisitorStats\Repository\VisitorReferrerRepository;
use Mublo\Plugin\VisitorStats\Service\VisitorStatsService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class VisitorStatsServiceTest extends TestCase
{
    private VisitorLogRepository&MockObject $logRepo;
    private VisitorDailyRepository&MockObject $dailyRepo;
    private VisitorStatsService $service;

    protected function setUp(): void
    {
        $this->logRepo = $this->createMock(VisitorLogRepository::class);
        $this->dailyRepo = $this->createMock(VisitorDailyRepository::class);
        $this->service = new VisitorStatsService(
            $this->logRepo,
            $this->dailyRepo,
            $this->createMock(VisitorHourlyRepository::class),
            $this->createMock(VisitorPageRepository::class),
            $this->createMock(VisitorReferrerRepository::class),
            $this->createMock(VisitorCampaignRepository::class),
            $this->createMock(VisitorCampaignKeyRepository::class),
        );
    }

    public function testRealtimeClampsPageAndUsesServerSideOffsetWithinDomain(): void
    {
        $domainId = 7;
        $this->dailyRepo->expects(self::once())
            ->method('findByDate')
            ->with($domainId, date('Y-m-d'))
            ->willReturn(['total_visitors' => 12, 'total_pageviews' => 34]);
        $this->logRepo->expects(self::once())
            ->method('countTodayLogs')
            ->with($domainId)
            ->willReturn(95);
        $this->logRepo->expects(self::once())
            ->method('countRecentVisitors')
            ->with($domainId, 5)
            ->willReturn(4);
        $this->logRepo->expects(self::once())
            ->method('getRecentLogs')
            ->with($domainId, 30, 90)
            ->willReturn([['log_id' => 95]]);

        $result = $this->service->getRealtime($domainId, 99, 30);

        self::assertSame(4, $result['recent5min']);
        self::assertSame(12, $result['todayVisitors']);
        self::assertSame(34, $result['todayPageviews']);
        self::assertSame([['log_id' => 95]], $result['recentLogs']);
        self::assertSame([
            'currentPage' => 4,
            'perPage' => 30,
            'totalItems' => 95,
            'totalPages' => 4,
        ], $result['pagination']);
    }

    public function testIpHistoryIsPaginatedAndKeepsDomainAndIpBoundary(): void
    {
        $domainId = 9;
        $ipAddress = '2001:db8::1';
        $startDate = date('Y-m-d', strtotime('-1 month'));
        $endDate = date('Y-m-d');

        $this->logRepo->expects(self::once())
            ->method('countLogsByIp')
            ->with($domainId, $ipAddress, $startDate, $endDate)
            ->willReturn(45);
        $this->logRepo->expects(self::once())
            ->method('getLogsByIp')
            ->with($domainId, $ipAddress, $startDate, $endDate, 20, 40)
            ->willReturn([['log_id' => 45]]);

        $result = $this->service->getIpLogs($domainId, $ipAddress, 3, 20);

        self::assertSame($ipAddress, $result['ipAddress']);
        self::assertSame([['log_id' => 45]], $result['items']);
        self::assertSame([
            'currentPage' => 3,
            'perPage' => 20,
            'totalItems' => 45,
            'totalPages' => 3,
        ], $result['pagination']);
    }

    public function testPaginationSizeIsBounded(): void
    {
        $domainId = 3;
        $this->dailyRepo->method('findByDate')->willReturn([]);
        $this->logRepo->method('countTodayLogs')->willReturn(0);
        $this->logRepo->method('countRecentVisitors')->willReturn(0);
        $this->logRepo->expects(self::once())
            ->method('getRecentLogs')
            ->with($domainId, 100, 0)
            ->willReturn([]);

        $result = $this->service->getRealtime($domainId, 1, 1000);

        self::assertSame(100, $result['pagination']['perPage']);
        self::assertSame(1, $result['pagination']['totalPages']);
    }
}
