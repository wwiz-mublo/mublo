<?php
declare(strict_types=1);

namespace Mublo\Plugin\VisitorStats\Dashboard;

use Mublo\Core\Dashboard\AbstractDashboardWidget;
use Mublo\Plugin\VisitorStats\Repository\VisitorDailyRepository;

class VisitorStatsWidget extends AbstractDashboardWidget
{
    private VisitorDailyRepository $dailyRepo;
    private ?int $domainId;

    public function __construct(VisitorDailyRepository $dailyRepo, ?int $domainId = null)
    {
        $this->dailyRepo = $dailyRepo;
        $this->domainId = $domainId;
    }

    public function id(): string
    {
        return 'plugin.visitor_stats';
    }

    public function title(): string
    {
        return '오늘 방문자';
    }

    public function defaultSlot(): int
    {
        return 2;
    }

    public function render(): string
    {
        $domainId = $this->domainId ?? 1;
        $today = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day'));

        try {
            $todayData = $this->dailyRepo->findByDate($domainId, $today);
            $yesterdayData = $this->dailyRepo->findByDate($domainId, $yesterday);
        } catch (\Throwable $e) {
            return '<div class="text-muted text-center py-3">통계 테이블이 아직 생성되지 않았습니다.</div>';
        }

        $visitors = (int) ($todayData['total_visitors'] ?? 0);
        $pageviews = (int) ($todayData['total_pageviews'] ?? 0);
        $newVisitors = (int) ($todayData['new_visitors'] ?? 0);
        $prevVisitors = (int) ($yesterdayData['total_visitors'] ?? 0);

        $changeIcon = '';
        $changeText = '';
        if ($prevVisitors > 0) {
            $changePct = round(($visitors - $prevVisitors) / $prevVisitors * 100, 1);
            if ($changePct > 0) {
                $changeIcon = '<i class="bi bi-caret-up-fill text-success"></i>';
                $changeText = '+' . $changePct . '%';
            } elseif ($changePct < 0) {
                $changeIcon = '<i class="bi bi-caret-down-fill text-danger"></i>';
                $changeText = $changePct . '%';
            }
        } elseif ($visitors > 0) {
            $changeIcon = '<i class="bi bi-caret-up-fill text-success"></i>';
            $changeText = '신규';
        }

        $items = [
            ['label' => '방문자', 'key' => 'total_visitors', 'icon' => 'bi-people-fill', 'pastel' => 'pastel-icon-blue'],
            ['label' => '페이지뷰', 'key' => 'total_pageviews', 'icon' => 'bi-eye-fill', 'pastel' => 'pastel-icon-green'],
            ['label' => '신규 방문', 'key' => 'new_visitors', 'icon' => 'bi-person-plus-fill', 'pastel' => 'pastel-icon-sky'],
        ];

        $values = [
            'total_visitors' => $visitors,
            'total_pageviews' => $pageviews,
            'new_visitors' => $newVisitors,
        ];
        $prevValues = [
            'total_visitors' => $prevVisitors,
            'total_pageviews' => (int) ($yesterdayData['total_pageviews'] ?? 0),
            'new_visitors' => (int) ($yesterdayData['new_visitors'] ?? 0),
        ];

        $html = '<div class="d-flex flex-column gap-3">';
        foreach ($items as $item) {
            $current = $values[$item['key']];
            $prev = $prevValues[$item['key']];

            $changeHtml = '';
            if ($prev > 0) {
                $pct = round(($current - $prev) / $prev * 100, 1);
                if ($pct > 0) {
                    $changeHtml = '<span class="text-success" style="font-size:12px"><i class="bi bi-arrow-up-short"></i>' . $pct . '%</span>';
                } elseif ($pct < 0) {
                    $changeHtml = '<span class="text-danger" style="font-size:12px"><i class="bi bi-arrow-down-short"></i>' . abs($pct) . '%</span>';
                }
            } elseif ($current > 0) {
                $changeHtml = '<span class="text-success" style="font-size:12px">NEW</span>';
            }

            $html .= '<div class="d-flex align-items-center gap-3 rounded-3 p-3 border border-light-subtle">';
            $html .= '<div class="' . $item['pastel'] . ' rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width:40px;height:40px"><i class="bi ' . $item['icon'] . '" style="font-size:16px"></i></div>';
            $html .= '<div class="flex-grow-1">';
            $html .= '<div class="text-muted" style="font-size:12px">' . $item['label'] . '</div>';
            $html .= '<div class="fw-bold" style="font-size:1.25rem;line-height:1.2">' . number_format($current) . '</div>';
            $html .= '</div>';
            $html .= '<div>' . $changeHtml . '</div>';
            $html .= '</div>';
        }
        $html .= '</div>';

        return $html;
    }
}
