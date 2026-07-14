<?php

namespace Mublo\Plugin\VisitorStats\Repository;

use Mublo\Infrastructure\Database\Database;

class VisitorReferrerRepository
{
    private Database $db;
    private string $table = 'plugin_visitor_referrers';

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * 유입 경로 증분
     */
    public function incrementVisitor(int $domainId, string $date, string $refererType, string $refererDomain): void
    {
        $table = $this->table;
        $refererDomain = mb_substr($refererDomain, 0, 200);

        $sql = "INSERT INTO `{$table}` (domain_id, visit_date, referer_type, referer_domain, visitors)
                VALUES (?, ?, ?, ?, 1)
                ON DUPLICATE KEY UPDATE visitors = visitors + 1";

        $this->db->execute($sql, [$domainId, $date, $refererType, $refererDomain]);
    }

    /**
     * 기간별 유입 타입 집계
     */
    public function getTypeStats(int $domainId, string $startDate, string $endDate): array
    {
        $table = $this->table;

        $sql = "SELECT `referer_type`,
                    SUM(`visitors`) AS `visitors`
                FROM `{$table}`
                WHERE `domain_id` = ? AND `visit_date` BETWEEN ? AND ?
                GROUP BY `referer_type`
                ORDER BY `visitors` DESC";

        return $this->db->select($sql, [$domainId, $startDate, $endDate]);
    }

    /**
     * 기간별 유입 도메인 상세 (도메인별 방문자 수)
     */
    public function getTopDomains(int $domainId, string $startDate, string $endDate, int $limit = 30): array
    {
        $table = $this->table;

        $sql = "SELECT `referer_type`, `referer_domain`,
                    SUM(`visitors`) AS `visitors`
                FROM `{$table}`
                WHERE `domain_id` = ? AND `visit_date` BETWEEN ? AND ?
                    AND `referer_domain` != ''
                GROUP BY `referer_type`, `referer_domain`
                ORDER BY `visitors` DESC
                LIMIT ?";

        return $this->db->select($sql, [$domainId, $startDate, $endDate, $limit]);
    }
}
