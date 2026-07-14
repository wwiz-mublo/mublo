<?php

namespace Mublo\Plugin\VisitorStats\Service;

use Mublo\Plugin\VisitorStats\Repository\ConversionRepository;
use Mublo\Plugin\VisitorStats\Repository\VisitorCampaignRepository;
use Mublo\Plugin\VisitorStats\Repository\VisitorCampaignKeyRepository;

/**
 * 전환 통계 Service
 *
 * ConversionRepository(form_submissions)와 VisitorCampaignRepository(방문 집계)를
 * 결합하여 캠페인별 전환율, 전환 추이, 폼별 전환 현황 등을 제공한다.
 */
class ConversionStatsService
{
    public function __construct(
        private ConversionRepository $conversionRepo,
        private VisitorCampaignRepository $campaignRepo,
        private VisitorCampaignKeyRepository $campaignKeyRepo,
        private VisitorStatsService $statsService,
    ) {}

    /**
     * AutoForm 테이블 존재 여부
     */
    public function isAvailable(): bool
    {
        return $this->conversionRepo->hasTable();
    }

    /**
     * 캠페인별 요약 (방문자 + 전환 + 전환율)
     */
    public function getCampaignSummary(int $domainId, string $period): array
    {
        if (!$this->isAvailable()) {
            return ['items' => [], 'totalVisitors' => 0, 'totalConversions' => 0, 'totalRate' => 0];
        }

        [$startDate, $endDate] = $this->statsService->periodToDates($period);

        $campaignStats = $this->campaignRepo->getByKeys($domainId, $startDate, $endDate);
        $conversionStats = $this->conversionRepo->getConversionsByCampaign($domainId, $startDate, $endDate);
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
                'rate'         => $visitors > 0 ? round($conversions / $visitors * 100, 1) : 0,
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
                : 0,
        ];
    }

    /**
     * 전환 통계 (요약 + 일별 추이 + 폼별 현황)
     */
    public function getConversionStats(int $domainId, string $period): array
    {
        if (!$this->isAvailable()) {
            return [
                'total' => 0, 'avgDaily' => 0, 'topCampaign' => null, 'topForm' => null,
                'dailyTrend' => [], 'byForm' => [], 'byCampaign' => [],
            ];
        }

        [$startDate, $endDate] = $this->statsService->periodToDates($period);

        $totals = $this->conversionRepo->getTotalConversions($domainId, $startDate, $endDate);
        $totalSubmissions = $totals['submissions'];
        $totalConversions = $totals['conversions'];

        $dailyRaw = $this->conversionRepo->getDailyConversions($domainId, $startDate, $endDate);
        $byForm = $this->conversionRepo->getConversionsByForm($domainId, $startDate, $endDate);
        $byCampaign = $this->conversionRepo->getCampaignConversionDetail($domainId, $startDate, $endDate);

        // 일별 추이를 빈 날짜 포함하여 채움
        $dailyMap = [];
        foreach ($dailyRaw as $row) {
            $dailyMap[$row['conv_date']] = [
                'submissions' => (int) $row['submissions'],
                'conversions' => (int) $row['conversions'],
            ];
        }

        $days = (int) ((strtotime($endDate) - strtotime($startDate)) / 86400) + 1;
        $dailyTrend = [];
        $current = $startDate;
        while ($current <= $endDate) {
            $dailyTrend[] = [
                'date'        => $current,
                'submissions' => $dailyMap[$current]['submissions'] ?? 0,
                'conversions' => $dailyMap[$current]['conversions'] ?? 0,
            ];
            $current = date('Y-m-d', strtotime($current . ' +1 day'));
        }

        $avgDaily = $days > 0 ? round($totalConversions / $days, 1) : 0;
        $conversionRate = $totalSubmissions > 0 ? round($totalConversions / $totalSubmissions * 100, 1) : 0;

        // 최다 전환 캠페인/폼
        $topCampaign = null;
        $topForm = null;
        if (!empty($byCampaign)) {
            $first = $byCampaign[0];
            $topCampaign = [
                'campaign_key' => $first['campaign_key'] ?: '(직접접속)',
                'conversions'  => (int) $first['conversions'],
            ];
        }
        if (!empty($byForm)) {
            $first = $byForm[0];
            $topForm = [
                'form_name'   => $first['form_name'] ?? '(삭제된 폼)',
                'conversions' => (int) $first['conversions'],
            ];
        }

        return [
            'total'          => $totalConversions,
            'submissions'    => $totalSubmissions,
            'conversionRate' => $conversionRate,
            'avgDaily'       => $avgDaily,
            'topCampaign'    => $topCampaign,
            'topForm'        => $topForm,
            'dailyTrend'     => $dailyTrend,
            'byForm'         => $byForm,
            'byCampaign'     => $byCampaign,
        ];
    }

    /**
     * 전환 목록
     */
    public function getConversionList(
        int $domainId,
        string $period,
        ?int $formId = null,
        ?string $campaignKey = null,
        int $page = 1,
        int $perPage = 20
    ): array {
        if (!$this->isAvailable()) {
            return [
                'items' => [], 'totalItems' => 0, 'currentPage' => $page,
                'perPage' => $perPage, 'totalPages' => 0, 'forms' => [],
            ];
        }

        [$startDate, $endDate] = $this->statsService->periodToDates($period);

        $result = $this->conversionRepo->getConversionList(
            $domainId, $startDate, $endDate,
            $formId, $campaignKey,
            $page, $perPage
        );

        // IP 마스킹
        foreach ($result['items'] as &$item) {
            $item['ip_address'] = $this->maskIp($item['ip_address'] ?? '');
        }

        $forms = $this->conversionRepo->getFormList($domainId);

        return [
            'items'       => $result['items'],
            'totalItems'  => $result['total'],
            'currentPage' => $page,
            'perPage'     => $perPage,
            'totalPages'  => (int) ceil($result['total'] / $perPage),
            'forms'       => $forms,
        ];
    }

    /**
     * 특정 폼의 캠페인별 전환 + 일별 추이
     */
    public function getFormConversions(int $domainId, int $formId, string $period): array
    {
        if (!$this->isAvailable()) {
            return ['total' => 0, 'campaignTotal' => 0, 'campaignRate' => 0, 'byCampaign' => [], 'dailyTrend' => []];
        }

        [$startDate, $endDate] = $this->statsService->periodToDates($period);

        $byCampaign = $this->conversionRepo->getFormConversionsByCampaign(
            $domainId, $formId, $startDate, $endDate
        );

        $dailyRaw = $this->conversionRepo->getFormDailyConversions(
            $domainId, $formId, $startDate, $endDate
        );

        $dailyMap = [];
        foreach ($dailyRaw as $row) {
            $dailyMap[$row['conv_date']] = (int) $row['conversions'];
        }

        $dailyTrend = [];
        $current = $startDate;
        while ($current <= $endDate) {
            $dailyTrend[] = [
                'date'        => $current,
                'conversions' => $dailyMap[$current] ?? 0,
            ];
            $current = date('Y-m-d', strtotime($current . ' +1 day'));
        }

        $total = 0;
        $campaignTotal = 0;
        foreach ($byCampaign as $row) {
            $cnt = (int) $row['conversions'];
            $total += $cnt;
            if ($row['campaign_key'] !== '') {
                $campaignTotal += $cnt;
            }
        }

        return [
            'total'            => $total,
            'campaignTotal'    => $campaignTotal,
            'campaignRate'     => $total > 0 ? round($campaignTotal / $total * 100, 1) : 0,
            'byCampaign'       => $byCampaign,
            'dailyTrend'       => $dailyTrend,
        ];
    }

    /**
     * 대시보드용 전환 요약
     */
    public function getDashboardConversions(int $domainId, string $period): array
    {
        if (!$this->isAvailable()) {
            return ['conversions' => 0, 'change' => 0.0];
        }

        [$startDate, $endDate] = $this->statsService->periodToDates($period);

        $totals = $this->conversionRepo->getTotalConversions($domainId, $startDate, $endDate);
        $total = $totals['conversions'];

        // 전기 비교
        $days = (int) ((strtotime($endDate) - strtotime($startDate)) / 86400) + 1;
        $prevEnd = date('Y-m-d', strtotime($startDate . ' -1 day'));
        $prevStart = date('Y-m-d', strtotime($prevEnd . ' -' . ($days - 1) . ' days'));
        $prevTotals = $this->conversionRepo->getTotalConversions($domainId, $prevStart, $prevEnd);
        $prevTotal = $prevTotals['conversions'];

        $change = 0.0;
        if ($prevTotal > 0) {
            $change = round(($total - $prevTotal) / $prevTotal * 100, 1);
        } elseif ($total > 0) {
            $change = 100.0;
        }

        return [
            'conversions' => $total,
            'change'      => $change,
        ];
    }

    private function maskIp(string $ip): string
    {
        if (str_contains($ip, '.')) {
            $parts = explode('.', $ip);
            if (count($parts) === 4) {
                $parts[3] = 'xxx';
                return implode('.', $parts);
            }
        }
        return $ip;
    }
}
