<?php

namespace Mublo\Plugin\VisitorStats\Repository;

use Mublo\Infrastructure\Database\Database;

class VisitorPageRepository
{
    private Database $db;
    private string $table = 'plugin_visitor_pages';

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * UV + PV 증분
     */
    public function incrementVisitor(int $domainId, string $date, string $pageUrl): void
    {
        $table = $this->table;
        $pageUrl = mb_substr($pageUrl, 0, 500);

        $sql = "INSERT INTO `{$table}` (domain_id, visit_date, page_url, pageviews, visitors)
                VALUES (?, ?, ?, 1, 1)
                ON DUPLICATE KEY UPDATE
                    pageviews = pageviews + 1,
                    visitors = visitors + 1";

        $this->db->execute($sql, [$domainId, $date, $pageUrl]);
    }

    /**
     * PV만 증분
     */
    public function incrementPageview(int $domainId, string $date, string $pageUrl): void
    {
        $table = $this->table;
        $pageUrl = mb_substr($pageUrl, 0, 500);

        $sql = "INSERT INTO `{$table}` (domain_id, visit_date, page_url, pageviews)
                VALUES (?, ?, ?, 1)
                ON DUPLICATE KEY UPDATE pageviews = pageviews + 1";

        $this->db->execute($sql, [$domainId, $date, $pageUrl]);
    }

    /**
     * 기간별 페이지 통계 (PV 내림차순)
     */
    public function getTopPages(int $domainId, string $startDate, string $endDate, int $limit = 20, int $offset = 0): array
    {
        $table = $this->table;

        $sql = "SELECT `page_url`,
                    SUM(`pageviews`) AS `pageviews`,
                    SUM(`visitors`) AS `visitors`
                FROM `{$table}`
                WHERE `domain_id` = ? AND `visit_date` BETWEEN ? AND ?
                GROUP BY `page_url`
                ORDER BY `pageviews` DESC
                LIMIT ? OFFSET ?";

        return $this->db->select($sql, [$domainId, $startDate, $endDate, $limit, $offset]);
    }

    /**
     * 기간별 총 페이지 수 (페이지네이션용)
     */
    public function countPages(int $domainId, string $startDate, string $endDate): int
    {
        $table = $this->table;

        $sql = "SELECT COUNT(DISTINCT `page_url`) AS `cnt`
                FROM `{$table}`
                WHERE `domain_id` = ? AND `visit_date` BETWEEN ? AND ?";

        $rows = $this->db->select($sql, [$domainId, $startDate, $endDate]);
        return (int) ($rows[0]['cnt'] ?? 0);
    }
}
