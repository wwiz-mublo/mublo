<?php
declare(strict_types=1);

namespace Mublo\Plugin\VisitorStats\Service;

use Mublo\Plugin\VisitorStats\Repository\ConversionEventRepository;
use Mublo\Plugin\VisitorStats\Repository\VisitorCampaignRepository;
use Mublo\Plugin\VisitorStats\Repository\VisitorCampaignKeyRepository;

/**
 * 전환 통계 Service
 *
 * 전환 기록(ConversionEventRepository)과 방문 집계(VisitorCampaignRepository)를
 * 결합하여 캠페인별 전환율, 전환 추이, 소스별 전환 현황을 제공한다.
 *
 * 전환은 오직 ConversionRecordedEvent 로만 들어온다 — 주문·상담·가입·폼 접수 모두
 * 발행 확장이 통보하며, VisitorStats 는 발행자가 누구인지도, 그쪽 테이블이 무엇인지도
 * 알지 못한다. 갈래는 계약이 실어 온 sourceType/sourceLabel 로만 구분한다.
 */
class ConversionStatsService
{
    public function __construct(
        private ConversionEventRepository $eventRepo,
        private VisitorCampaignRepository $campaignRepo,
        private VisitorCampaignKeyRepository $campaignKeyRepo,
        private VisitorStatsService $statsService,
    ) {}

    /**
     * 캠페인별 요약 (방문자 + 전환 + 전환율)
     */
    public function getCampaignSummary(int $domainId, string $period): array
    {
        [$startDate, $endDate] = $this->statsService->periodToDates($period);

        $campaignStats = $this->campaignRepo->getByKeys($domainId, $startDate, $endDate);
        $conversionStats = $this->eventRepo->summaryByCampaign($domainId, $startDate, $endDate);
        $keys = $this->campaignKeyRepo->getAll($domainId);

        $keyMap = [];
        foreach ($keys as $k) {
            $keyMap[$k['campaign_key']] = $k;
        }

        $convMap = [];
        foreach ($conversionStats as $row) {
            $convMap[$row['campaign_key']] = (int) $row['conversions'];
        }

        $items = [];
        $totalVisitors = 0;
        $totalConversions = 0;

        // 캠페인 방문 데이터 기반
        foreach ($campaignStats as $row) {
            $key = $row['campaign_key'];
            $visitors = (int) $row['visitors'];
            $conversions = $convMap[$key] ?? 0;
            $setting = $keyMap[$key] ?? null;

            $items[] = [
                'campaign_key' => $key,
                'group_name'   => $setting['group_name'] ?? '',
                'visitors'     => $visitors,
                'pageviews'    => (int) $row['pageviews'],
                'conversions'  => $conversions,
                'rate'         => $visitors > 0 ? round($conversions / $visitors * 100, 1) : 0.0,
            ];

            $totalVisitors += $visitors;
            $totalConversions += $conversions;
        }

        // 직접접속 전환 (campaign_key 없음)
        $directConversions = $convMap[''] ?? 0;
        if ($directConversions > 0) {
            $items[] = [
                'campaign_key' => '',
                'group_name'   => '',
                'visitors'     => 0,
                'pageviews'    => 0,
                'conversions'  => $directConversions,
                'rate'         => 0,
            ];
            $totalConversions += $directConversions;
        }

        return [
            'items'            => $items,
            'totalVisitors'    => $totalVisitors,
            'totalConversions' => $totalConversions,
            'totalRate'        => $totalVisitors > 0
                ? round($totalConversions / $totalVisitors * 100, 1)
                : 0.0,
        ];
    }

    /**
     * 전환 통계 (요약 + 일별 추이 + 소스별·캠페인별 현황)
     */
    public function getConversionStats(int $domainId, string $period): array
    {
        [$startDate, $endDate] = $this->statsService->periodToDates($period);

        $totals = $this->eventRepo->totals($domainId, $startDate, $endDate);
        $totalRecorded = $totals['total'];
        $totalConversions = $totals['conversions'];

        $dailyRaw = $this->eventRepo->dailyTrend($domainId, $startDate, $endDate);
        $bySourceRaw = $this->eventRepo->summaryBySource($domainId, $startDate, $endDate);
        $byCampaign = $this->eventRepo->summaryByCampaign($domainId, $startDate, $endDate);

        // 일별 추이를 빈 날짜 포함하여 채움
        $dailyMap = [];
        foreach ($dailyRaw as $row) {
            $dailyMap[$row['conv_date']] = [
                'recorded'    => (int) $row['total'],
                'conversions' => (int) $row['conversions'],
            ];
        }

        $days = (int) ((strtotime($endDate) - strtotime($startDate)) / 86400) + 1;
        $dailyTrend = [];
        $current = $startDate;
        while ($current <= $endDate) {
            $dailyTrend[] = [
                'date'        => $current,
                'recorded'    => $dailyMap[$current]['recorded'] ?? 0,
                'conversions' => $dailyMap[$current]['conversions'] ?? 0,
            ];
            $current = date('Y-m-d', strtotime($current . ' +1 day'));
        }

        $avgDaily = $days > 0 ? round($totalConversions / $days, 1) : 0.0;
        $conversionRate = $totalRecorded > 0 ? round($totalConversions / $totalRecorded * 100, 1) : 0.0;

        $bySource = array_map(static fn(array $row): array => [
            'source_type'  => (string) $row['source_type'],
            'source_label' => (string) ($row['source_label'] ?? $row['source_type']),
            'recorded'     => (int) $row['total'],
            'conversions'  => (int) $row['conversions'],
            'value_sum'    => (float) ($row['value_sum'] ?? 0),
        ], $bySourceRaw);

        // 최다 전환 캠페인/소스
        $topCampaign = null;
        $topSource = null;
        if (!empty($byCampaign)) {
            $first = $byCampaign[0];
            $topCampaign = [
                'campaign_key' => $first['campaign_key'] ?: '(직접접속)',
                'conversions'  => (int) $first['conversions'],
            ];
        }
        if (!empty($bySource)) {
            $first = $bySource[0];
            $topSource = [
                'source_label' => $first['source_label'],
                'conversions'  => $first['conversions'],
            ];
        }

        $totalValue = 0.0;
        foreach ($bySource as $row) {
            $totalValue += $row['value_sum'];
        }

        return [
            'total'          => $totalConversions,
            'recorded'       => $totalRecorded,
            'conversionRate' => $conversionRate,
            'avgDaily'       => $avgDaily,
            'totalValue'     => $totalValue,
            'topCampaign'    => $topCampaign,
            'topSource'      => $topSource,
            'dailyTrend'     => $dailyTrend,
            'bySource'       => $bySource,
            'byCampaign'     => $byCampaign,
        ];
    }

    /**
     * 전환 목록
     */
    public function getConversionList(
        int $domainId,
        string $period,
        ?string $sourceType = null,
        ?string $sourceLabel = null,
        ?string $campaignKey = null,
        int $page = 1,
        int $perPage = 20
    ): array {
        [$startDate, $endDate] = $this->statsService->periodToDates($period);

        $result = $this->eventRepo->listConversions(
            $domainId, $startDate, $endDate,
            $sourceType, $sourceLabel, $campaignKey,
            $page, $perPage
        );

        $sources = $this->eventRepo->sourceOptions($domainId, $startDate, $endDate);

        return [
            'items'       => $result['items'],
            'totalItems'  => $result['total'],
            'currentPage' => $page,
            'perPage'     => $perPage,
            'totalPages'  => (int) ceil($result['total'] / $perPage),
            // has_label 은 "라벨 없이 기록된 갈래"를 필터가 골라낼 수 있게 한다 —
            // 표시용으로 타입 문자열을 대신 채우고 나면 둘을 구분할 수 없어진다.
            'sources'     => array_map(static fn(array $row): array => [
                'source_type'  => (string) $row['source_type'],
                'source_label' => (string) ($row['source_label'] ?? $row['source_type']),
                'has_label'    => $row['source_label'] !== null,
            ], $sources),
        ];
    }

    /**
     * 대시보드용 전환 요약
     */
    public function getDashboardConversions(int $domainId, string $period): array
    {
        [$startDate, $endDate] = $this->statsService->periodToDates($period);

        $total = $this->eventRepo->totals($domainId, $startDate, $endDate)['conversions'];

        // 전기 비교
        $days = (int) ((strtotime($endDate) - strtotime($startDate)) / 86400) + 1;
        $prevEnd = date('Y-m-d', strtotime($startDate . ' -1 day'));
        $prevStart = date('Y-m-d', strtotime($prevEnd . ' -' . ($days - 1) . ' days'));
        $prevTotal = $this->eventRepo->totals($domainId, $prevStart, $prevEnd)['conversions'];

        $change = 0.0;
        if ($prevTotal > 0) {
            $change = round(($total - $prevTotal) / $prevTotal * 100, 1);
        } elseif ($total > 0) {
            $change = 100.0;
        }

        return [
            'conversions'     => $total,
            'prevConversions' => $prevTotal,
            'change'          => $change,
        ];
    }
}
