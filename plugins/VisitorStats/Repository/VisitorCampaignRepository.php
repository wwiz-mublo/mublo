<?php

namespace Mublo\Plugin\VisitorStats\Repository;

use Mublo\Infrastructure\Database\Database;

class VisitorCampaignRepository
{
    private Database $db;
    private string $table = 'plugin_visitor_campaigns';

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * UV + PV 증분
     */
    public function incrementVisitor(int $domainId, string $date, string $campaignKey): void
    {
        $table = $this->table;

        $sql = "INSERT INTO `{$table}` (domain_id, visit_date, campaign_key, visitors, pageviews)
                VALUES (?, ?, ?, 1, 1)
                ON DUPLICATE KEY UPDATE
                    visitors = visitors + 1,
                    pageviews = pageviews + 1";

        $this->db->execute($sql, [$domainId, $date, $campaignKey]);
    }

    /**
     * PV만 증분
     */
    public function incrementPageview(int $domainId, string $date, string $campaignKey): void
    {
        $table = $this->table;

        $sql = "INSERT INTO `{$table}` (domain_id, visit_date, campaign_key, pageviews)
                VALUES (?, ?, ?, 1)
                ON DUPLICATE KEY UPDATE pageviews = pageviews + 1";

        $this->db->execute($sql, [$domainId, $date, $campaignKey]);
    }

    /**
     * 기간별 키별 집계
     */
    public function getByKeys(int $domainId, string $startDate, string $endDate): array
    {
        $table = $this->table;

        $sql = "SELECT `campaign_key`,
                    SUM(`visitors`) AS `visitors`,
                    SUM(`pageviews`) AS `pageviews`
                FROM `{$table}`
                WHERE `domain_id` = ? AND `visit_date` BETWEEN ? AND ?
                GROUP BY `campaign_key`
                ORDER BY `visitors` DESC";

        return $this->db->select($sql, [$domainId, $startDate, $endDate]);
    }

    /**
     * 기간별 일별 추이 (특정 키 또는 전체)
     */
    public function getTrend(int $domainId, string $startDate, string $endDate, ?string $campaignKey = null): array
    {
        $table = $this->table;
        $params = [$domainId, $startDate, $endDate];

        $where = "WHERE `domain_id` = ? AND `visit_date` BETWEEN ? AND ?";
        if ($campaignKey !== null) {
            $where .= " AND `campaign_key` = ?";
            $params[] = $campaignKey;
        }

        $sql = "SELECT `visit_date`,
                    SUM(`visitors`) AS `visitors`,
                    SUM(`pageviews`) AS `pageviews`
                FROM `{$table}`
                {$where}
                GROUP BY `visit_date`
                ORDER BY `visit_date` ASC";

        return $this->db->select($sql, $params);
    }
}
