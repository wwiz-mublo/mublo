<?php
declare(strict_types=1);

namespace Mublo\Plugin\VisitorStats\Service;

use Mublo\Plugin\VisitorStats\Repository\VisitorLogRepository;
use Mublo\Plugin\VisitorStats\Repository\VisitorDailyRepository;
use Mublo\Plugin\VisitorStats\Repository\VisitorHourlyRepository;
use Mublo\Plugin\VisitorStats\Repository\VisitorPageRepository;
use Mublo\Plugin\VisitorStats\Repository\VisitorReferrerRepository;
use Mublo\Plugin\VisitorStats\Repository\VisitorCampaignRepository;
use Mublo\Plugin\VisitorStats\Repository\VisitorCampaignKeyRepository;

/**
 * VisitorStatsService
 *
 * 방문자 통계 조회 로직 (관리자 화면용)
 */
class VisitorStatsService
{
    public function __construct(
        private VisitorLogRepository $logRepo,
        private VisitorDailyRepository $dailyRepo,
        private VisitorHourlyRepository $hourlyRepo,
        private VisitorPageRepository $pageRepo,
        private VisitorReferrerRepository $referrerRepo,
        private VisitorCampaignRepository $campaignRepo,
        private VisitorCampaignKeyRepository $campaignKeyRepo,
    ) {}

    /**
     * 기간별 요약 카드 데이터 + 전일 대비 증감
     */
    public function getSummary(int $domainId, string $period): array
    {
        [$startDate, $endDate] = $this->periodToDates($period);
        $summary = $this->dailyRepo->getSummary($domainId, $startDate, $endDate);

        // 전일 대비 증감 계산 (비교 기간 = 동일 길이)
        $days = (int) (strtotime($endDate) - strtotime($startDate)) / 86400 + 1;
        $prevEnd = date('Y-m-d', strtotime($startDate . ' -1 day'));
        $prevStart = date('Y-m-d', strtotime($prevEnd . ' -' . ($days - 1) . ' days'));
        $prevSummary = $this->dailyRepo->getSummary($domainId, $prevStart, $prevEnd);

        return [
            'period'     => $period,
            'startDate'  => $startDate,
            'endDate'    => $endDate,
            'visitors'   => (int) ($summary['total_visitors'] ?? 0),
            'pageviews'  => (int) ($summary['total_pageviews'] ?? 0),
            'newVisitors' => (int) ($summary['new_visitors'] ?? 0),
            'returnVisitors' => (int) ($summary['return_visitors'] ?? 0),
            'memberVisitors' => (int) ($summary['member_visitors'] ?? 0),
            'change' => [
                'visitors'  => $this->calcChange(
                    (int) ($summary['total_visitors'] ?? 0),
                    (int) ($prevSummary['total_visitors'] ?? 0)
                ),
                'pageviews' => $this->calcChange(
                    (int) ($summary['total_pageviews'] ?? 0),
                    (int) ($prevSummary['total_pageviews'] ?? 0)
                ),
                'newVisitors' => $this->calcChange(
                    (int) ($summary['new_visitors'] ?? 0),
                    (int) ($prevSummary['new_visitors'] ?? 0)
                ),
            ],
        ];
    }

    /**
     * 누적(전체 기간) 방문자 수
     */
    public function getCumulativeVisitors(int $domainId): int
    {
        return $this->dailyRepo->getTotalVisitors($domainId);
    }

    /**
     * 누적(전체 기간) 요약 — 방문자/페이지뷰/회원
     */
    public function getCumulativeSummary(int $domainId): array
    {
        $t = $this->dailyRepo->getTotals($domainId);
        return [
            'visitors'       => (int) ($t['total_visitors'] ?? 0),
            'pageviews'      => (int) ($t['total_pageviews'] ?? 0),
            'memberVisitors' => (int) ($t['member_visitors'] ?? 0),
        ];
    }

    /**
     * 일별 추이 데이터 (차트용)
     */
    public function getTrend(int $domainId, string $period): array
    {
        [$startDate, $endDate] = $this->periodToDates($period);
        $rows = $this->dailyRepo->getRange($domainId, $startDate, $endDate);

        // 빈 날짜를 0으로 채움
        $map = [];
        foreach ($rows as $row) {
            $map[$row['visit_date']] = $row;
        }

        $result = [];
        $current = $startDate;
        while ($current <= $endDate) {
            $row = $map[$current] ?? null;
            $result[] = [
                'date'      => $current,
                'visitors'  => (int) ($row['total_visitors'] ?? 0),
                'pageviews' => (int) ($row['total_pageviews'] ?? 0),
                'newVisitors' => (int) ($row['new_visitors'] ?? 0),
            ];
            $current = date('Y-m-d', strtotime($current . ' +1 day'));
        }

        return $result;
    }

    /**
     * 시간대별 분포 데이터
     */
    public function getHourly(int $domainId, string $period): array
    {
        [$startDate, $endDate] = $this->periodToDates($period);
        $rows = $this->hourlyRepo->getHourlyAggregated($domainId, $startDate, $endDate);

        // 0~23시 빈 시간대 채움
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['visit_hour']] = $row;
        }

        $result = [];
        for ($h = 0; $h < 24; $h++) {
            $row = $map[$h] ?? null;
            $result[] = [
                'hour'      => $h,
                'visitors'  => (int) ($row['visitors'] ?? 0),
                'pageviews' => (int) ($row['pageviews'] ?? 0),
            ];
        }

        return $result;
    }

    /**
     * 실시간 현황
     */
    public function getRealtime(int $domainId, int $page = 1, int $perPage = 30): array
    {
        $today = date('Y-m-d');
        $todayStats = $this->dailyRepo->findByDate($domainId, $today);
        $page = max(1, $page);
        $perPage = max(10, min(100, $perPage));
        $totalLogs = $this->logRepo->countTodayLogs($domainId);
        $totalPages = max(1, (int) ceil($totalLogs / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        return [
            'recent5min'   => $this->logRepo->countRecentVisitors($domainId, 5),
            'todayVisitors' => (int) ($todayStats['total_visitors'] ?? 0),
            'todayPageviews' => (int) ($todayStats['total_pageviews'] ?? 0),
            'recentLogs'   => $this->logRepo->getRecentLogs($domainId, $perPage, $offset),
            'pagination' => [
                'currentPage' => $page,
                'perPage' => $perPage,
                'totalItems' => $totalLogs,
                'totalPages' => $totalPages,
            ],
        ];
    }

    /**
     * 특정 IP의 최근 1개월 접속 로그
     */
    public function getIpLogs(int $domainId, string $ipAddress, int $page = 1, int $perPage = 30): array
    {
        $startDate = date('Y-m-d', strtotime('-1 month'));
        $endDate = date('Y-m-d');
        $page = max(1, $page);
        $perPage = max(10, min(100, $perPage));
        $totalLogs = $this->logRepo->countLogsByIp($domainId, $ipAddress, $startDate, $endDate);
        $totalPages = max(1, (int) ceil($totalLogs / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        return [
            'ipAddress' => $ipAddress,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'items' => $this->logRepo->getLogsByIp(
                $domainId,
                $ipAddress,
                $startDate,
                $endDate,
                $perPage,
                $offset,
            ),
            'pagination' => [
                'currentPage' => $page,
                'perPage' => $perPage,
                'totalItems' => $totalLogs,
                'totalPages' => $totalPages,
            ],
        ];
    }

    /**
     * 페이지별 통계
     */
    public function getPages(int $domainId, string $period, int $page = 1, int $perPage = 20): array
    {
        [$startDate, $endDate] = $this->periodToDates($period);
        $offset = ($page - 1) * $perPage;

        $rows = $this->pageRepo->getTopPages($domainId, $startDate, $endDate, $perPage, $offset);
        $total = $this->pageRepo->countPages($domainId, $startDate, $endDate);

        return [
            'items' => $rows,
            'totalItems' => $total,
            'currentPage' => $page,
            'perPage' => $perPage,
            'totalPages' => (int) ceil($total / $perPage),
        ];
    }

    /**
     * 유입 경로 통계
     */
    public function getReferrers(int $domainId, string $period): array
    {
        [$startDate, $endDate] = $this->periodToDates($period);

        return [
            'types'   => $this->referrerRepo->getTypeStats($domainId, $startDate, $endDate),
            'domains' => $this->referrerRepo->getTopDomains($domainId, $startDate, $endDate, 30),
        ];
    }

    /**
     * 환경 분석 (브라우저/OS/디바이스)
     */
    public function getEnvironment(int $domainId, string $period): array
    {
        [$startDate, $endDate] = $this->periodToDates($period);

        return [
            'browser'      => $this->logRepo->getEnvironmentStats($domainId, 'browser', $startDate, $endDate),
            'os'           => $this->logRepo->getEnvironmentStats($domainId, 'os', $startDate, $endDate),
            'device'       => $this->logRepo->getEnvironmentStats($domainId, 'device', $startDate, $endDate),
        ];
    }

    /**
     * 캠페인 키별 통계
     */
    public function getCampaigns(int $domainId, string $period): array
    {
        [$startDate, $endDate] = $this->periodToDates($period);

        $stats = $this->campaignRepo->getByKeys($domainId, $startDate, $endDate);
        $keys = $this->campaignKeyRepo->getAll($domainId);

        // 키 설정 맵 (campaign_key → 설정 정보)
        $keyMap = [];
        foreach ($keys as $k) {
            $keyMap[$k['campaign_key']] = $k;
        }

        // 키별 데이터에 그룹명/메모 붙이기
        $items = [];
        foreach ($stats as $row) {
            $key = $row['campaign_key'];
            $setting = $keyMap[$key] ?? null;
            $items[] = [
                'campaign_key' => $key,
                'group_name'   => $setting['group_name'] ?? '',
                'memo'         => $setting['memo'] ?? '',
                'visitors'     => (int) $row['visitors'],
                'pageviews'    => (int) $row['pageviews'],
            ];
        }

        // 그룹별 요약
        $groups = [];
        foreach ($items as $item) {
            $gn = $item['group_name'] ?: '(미분류)';
            if (!isset($groups[$gn])) {
                $groups[$gn] = ['group_name' => $gn, 'keys' => 0, 'visitors' => 0, 'pageviews' => 0];
            }
            $groups[$gn]['keys']++;
            $groups[$gn]['visitors'] += $item['visitors'];
            $groups[$gn]['pageviews'] += $item['pageviews'];
        }

        return [
            'items'  => $items,
            'groups' => array_values($groups),
        ];
    }

    /**
     * 캠페인 키별 일별 추이
     */
    public function getCampaignTrend(int $domainId, string $period, ?string $campaignKey = null): array
    {
        [$startDate, $endDate] = $this->periodToDates($period);
        $rows = $this->campaignRepo->getTrend($domainId, $startDate, $endDate, $campaignKey);

        $map = [];
        foreach ($rows as $row) {
            $map[$row['visit_date']] = $row;
        }

        $result = [];
        $current = $startDate;
        while ($current <= $endDate) {
            $row = $map[$current] ?? null;
            $result[] = [
                'date'      => $current,
                'visitors'  => (int) ($row['visitors'] ?? 0),
                'pageviews' => (int) ($row['pageviews'] ?? 0),
            ];
            $current = date('Y-m-d', strtotime($current . ' +1 day'));
        }

        return $result;
    }

    /** 직접 지정 기간의 최대 일수 — 일별 추이를 한 화면에 그릴 수 있는 범위 */
    private const CUSTOM_PERIOD_MAX_DAYS = 366;

    /**
     * 기간 문자열 → [startDate, endDate] 변환
     *
     * 프리셋(today, last_7_days …) 외에 'custom:YYYY-MM-DD:YYYY-MM-DD' 로 직접 지정한다.
     * 형식이 틀리거나 시작일이 종료일보다 늦으면 최근 7일로 본다.
     *
     * @return array{0: string, 1: string}
     */
    public function periodToDates(string $period): array
    {
        $today = date('Y-m-d');

        if (str_starts_with($period, 'custom:')) {
            return $this->customPeriodToDates($period)
                ?? [date('Y-m-d', strtotime('-6 days')), $today];
        }

        return match ($period) {
            'today'       => [$today, $today],
            'yesterday'   => [date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('-1 day'))],
            'last_7_days' => [date('Y-m-d', strtotime('-6 days')), $today],
            'last_30_days' => [date('Y-m-d', strtotime('-29 days')), $today],
            'this_month'  => [date('Y-m-01'), $today],
            default       => [date('Y-m-d', strtotime('-6 days')), $today],
        };
    }

    /**
     * 'custom:YYYY-MM-DD:YYYY-MM-DD' 해석 — 너무 긴 기간은 종료일 기준으로 자른다.
     *
     * @return array{0: string, 1: string}|null 형식이 틀리면 null
     */
    private function customPeriodToDates(string $period): ?array
    {
        if (!preg_match('/^custom:(\d{4})-(\d{2})-(\d{2}):(\d{4})-(\d{2})-(\d{2})$/', $period, $m)) {
            return null;
        }
        if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || !checkdate((int) $m[5], (int) $m[6], (int) $m[4])) {
            return null;
        }

        $startDate = "{$m[1]}-{$m[2]}-{$m[3]}";
        $endDate   = "{$m[4]}-{$m[5]}-{$m[6]}";
        if ($startDate > $endDate) {
            return null;
        }

        $earliest = date('Y-m-d', strtotime($endDate . ' -' . (self::CUSTOM_PERIOD_MAX_DAYS - 1) . ' days'));

        return [max($startDate, $earliest), $endDate];
    }

    /**
     * 전기 대비 변화율 (%)
     */
    private function calcChange(int $current, int $previous): float
    {
        if ($previous === 0) {
            return $current > 0 ? 100.0 : 0.0;
        }
        return round(($current - $previous) / $previous * 100, 1);
    }
}
