<?php

namespace Mublo\Core\Dashboard\Widget;

use Mublo\Core\Context\Context;
use Mublo\Core\Dashboard\AbstractDashboardWidget;
use Mublo\Repository\Member\MemberRepository;

class MemberStatsWidget extends AbstractDashboardWidget
{
    public function canView(Context $context): bool
    {
        return true;
    }

    private MemberRepository $memberRepo;
    private \Closure|int|null $domainIdResolver;

    public function __construct(MemberRepository $memberRepo, \Closure|int|null $domainId = null)
    {
        $this->memberRepo = $memberRepo;
        $this->domainIdResolver = $domainId;
    }

    private function getDomainId(): ?int
    {
        if ($this->domainIdResolver instanceof \Closure) {
            return ($this->domainIdResolver)();
        }
        return $this->domainIdResolver;
    }

    public function id(): string
    {
        return 'core.member_stats';
    }

    public function title(): string
    {
        return '회원 현황';
    }

    public function defaultSlot(): int
    {
        return 2;
    }

    public function render(): string
    {
        $domainId = $this->getDomainId();
        $conditions = $domainId ? ['domain_id' => $domainId] : [];

        $totalMembers = $this->memberRepo->countBy($conditions);
        $todayCount = $this->countByDate(date('Y-m-d'));
        $monthCount = $this->countSinceDate(date('Y-m-01'));

        $items = [
            ['label' => '전체 회원', 'value' => number_format($totalMembers), 'icon' => 'bi-people-fill', 'pastel' => 'pastel-icon-blue'],
            ['label' => '오늘 가입', 'value' => number_format($todayCount), 'icon' => 'bi-person-plus-fill', 'pastel' => 'pastel-icon-green'],
            ['label' => '이번 달 가입', 'value' => number_format($monthCount), 'icon' => 'bi-calendar-check', 'pastel' => 'pastel-icon-sky'],
        ];

        $html = '<div class="d-flex flex-column gap-3">';
        foreach ($items as $item) {
            $html .= '<div class="d-flex align-items-center gap-3 rounded-3 p-3 border border-light-subtle">';
            $html .= '<div class="' . $item['pastel'] . ' rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width:40px;height:40px"><i class="bi ' . $item['icon'] . '" style="font-size:16px"></i></div>';
            $html .= '<div class="flex-grow-1">';
            $html .= '<div class="text-muted" style="font-size:12px">' . $item['label'] . '</div>';
            $html .= '<div class="fw-bold" style="font-size:1.25rem;line-height:1.2">' . $item['value'] . '</div>';
            $html .= '</div>';
            $html .= '</div>';
        }
        $html .= '</div>';

        return $html;
    }

    private function countByDate(string $date): int
    {
        try {
            $db = $this->memberRepo->getDb();
            $query = $db->table('members')
                ->whereRaw('DATE(created_at) = ?', [$date]);

            if ($domainId = $this->getDomainId()) {
                $query->where('domain_id', '=', $domainId);
            }

            return $query->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function countSinceDate(string $date): int
    {
        try {
            $db = $this->memberRepo->getDb();
            $query = $db->table('members')
                ->where('created_at', '>=', $date);

            if ($domainId = $this->getDomainId()) {
                $query->where('domain_id', '=', $domainId);
            }

            return $query->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
