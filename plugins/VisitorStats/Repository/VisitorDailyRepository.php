<?php
declare(strict_types=1);

namespace Mublo\Plugin\VisitorStats\Repository;

use Mublo\Infrastructure\Database\Database;

class VisitorDailyRepository
{
    private Database $db;
    private string $table = 'plugin_visitor_daily';

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * UV 증분 (INSERT ... ON DUPLICATE KEY UPDATE)
     */
    public function incrementVisitor(int $domainId, string $date, array $flags): void
    {
        $table = $this->table;

        $isNew = $flags['is_new'] ? 1 : 0;
        $isReturn = $flags['is_new'] ? 0 : 1;
        $isMember = $flags['is_member'] ? 1 : 0;
        $isGuest = $flags['is_member'] ? 0 : 1;
        $isPc = $flags['device'] === 'pc' ? 1 : 0;
        $isMobile = $flags['device'] === 'mobile' ? 1 : 0;
        $isTablet = $flags['device'] === 'tablet' ? 1 : 0;

        $sql = "INSERT INTO `{$table}`
                    (domain_id, visit_date, total_visitors, total_pageviews,
                     new_visitors, return_visitors, member_visitors, guest_visitors,
                     pc_visitors, mobile_visitors, tablet_visitors)
                VALUES (?, ?, 1, 1, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    total_visitors = total_visitors + 1,
                    total_pageviews = total_pageviews + 1,
                    new_visitors = new_visitors + VALUES(new_visitors),
                    return_visitors = return_visitors + VALUES(return_visitors),
                    member_visitors = member_visitors + VALUES(member_visitors),
                    guest_visitors = guest_visitors + VALUES(guest_visitors),
                    pc_visitors = pc_visitors + VALUES(pc_visitors),
                    mobile_visitors = mobile_visitors + VALUES(mobile_visitors),
                    tablet_visitors = tablet_visitors + VALUES(tablet_visitors)";

        $this->db->execute($sql, [
            $domainId, $date,
            $isNew, $isReturn, $isMember, $isGuest,
            $isPc, $isMobile, $isTablet,
        ]);
    }

    /**
     * PV만 증분 (UV가 아닌 페이지뷰)
     */
    public function incrementPageview(int $domainId, string $date): void
    {
        $table = $this->table;

        $sql = "INSERT INTO `{$table}` (domain_id, visit_date, total_pageviews)
                VALUES (?, ?, 1)
                ON DUPLICATE KEY UPDATE total_pageviews = total_pageviews + 1";

        $this->db->execute($sql, [$domainId, $date]);
    }

    /**
     * 특정 날짜 일별 통계
     */
    public function findByDate(int $domainId, string $date): ?array
    {
        return $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('visit_date', '=', $date)
            ->first();
    }

    /**
     * 기간별 일별 통계 목록
     */
    public function getRange(int $domainId, string $startDate, string $endDate): array
    {
        return $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('visit_date', '>=', $startDate)
            ->where('visit_date', '<=', $endDate)
            ->orderBy('visit_date', 'ASC')
            ->get();
    }

    /**
     * 기간 합계 (요약 카드용)
     */
    public function getSummary(int $domainId, string $startDate, string $endDate): array
    {
        $table = $this->table;

        $sql = "SELECT
                    COALESCE(SUM(total_visitors), 0) AS total_visitors,
                    COALESCE(SUM(total_pageviews), 0) AS total_pageviews,
                    COALESCE(SUM(new_visitors), 0) AS new_visitors,
                    COALESCE(SUM(return_visitors), 0) AS return_visitors,
                    COALESCE(SUM(member_visitors), 0) AS member_visitors,
                    COALESCE(SUM(guest_visitors), 0) AS guest_visitors,
                    COALESCE(SUM(pc_visitors), 0) AS pc_visitors,
                    COALESCE(SUM(mobile_visitors), 0) AS mobile_visitors,
                    COALESCE(SUM(tablet_visitors), 0) AS tablet_visitors
                FROM `{$table}`
                WHERE `domain_id` = ? AND `visit_date` BETWEEN ? AND ?";

        $rows = $this->db->select($sql, [$domainId, $startDate, $endDate]);
        return $rows[0] ?? [];
    }

    /**
     * 누적(전체 기간) 방문자 합계
     */
    public function getTotalVisitors(int $domainId): int
    {
        $table = $this->table;
        $rows = $this->db->select(
            "SELECT COALESCE(SUM(total_visitors), 0) AS total FROM `{$table}` WHERE `domain_id` = ?",
            [$domainId]
        );
        return (int) ($rows[0]['total'] ?? 0);
    }

    /**
     * 누적(전체 기간) 합계 — 방문자/페이지뷰/회원
     */
    public function getTotals(int $domainId): array
    {
        $table = $this->table;
        $rows = $this->db->select(
            "SELECT COALESCE(SUM(total_visitors), 0)  AS total_visitors,
                    COALESCE(SUM(total_pageviews), 0) AS total_pageviews,
                    COALESCE(SUM(member_visitors), 0) AS member_visitors
             FROM `{$table}` WHERE `domain_id` = ?",
            [$domainId]
        );
        return $rows[0] ?? [];
    }
}
