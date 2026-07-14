<?php

namespace Mublo\Plugin\VisitorStats\Repository;

use Mublo\Infrastructure\Database\Database;

class VisitorHourlyRepository
{
    private Database $db;
    private string $table = 'plugin_visitor_hourly';

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * UV + PV 증분
     */
    public function incrementVisitor(int $domainId, string $date, int $hour): void
    {
        $table = $this->table;

        $sql = "INSERT INTO `{$table}` (domain_id, visit_date, visit_hour, visitors, pageviews)
                VALUES (?, ?, ?, 1, 1)
                ON DUPLICATE KEY UPDATE
                    visitors = visitors + 1,
                    pageviews = pageviews + 1";

        $this->db->execute($sql, [$domainId, $date, $hour]);
    }

    /**
     * PV만 증분
     */
    public function incrementPageview(int $domainId, string $date, int $hour): void
    {
        $table = $this->table;

        $sql = "INSERT INTO `{$table}` (domain_id, visit_date, visit_hour, pageviews)
                VALUES (?, ?, ?, 1)
                ON DUPLICATE KEY UPDATE pageviews = pageviews + 1";

        $this->db->execute($sql, [$domainId, $date, $hour]);
    }

    /**
     * 특정 날짜 시간대별 통계
     */
    public function getByDate(int $domainId, string $date): array
    {
        return $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('visit_date', '=', $date)
            ->orderBy('visit_hour', 'ASC')
            ->get();
    }

    /**
     * 기간별 시간대 집계 (시간대별 합계)
     */
    public function getHourlyAggregated(int $domainId, string $startDate, string $endDate): array
    {
        $table = $this->table;

        $sql = "SELECT `visit_hour`,
                    SUM(`visitors`) AS `visitors`,
                    SUM(`pageviews`) AS `pageviews`
                FROM `{$table}`
                WHERE `domain_id` = ? AND `visit_date` BETWEEN ? AND ?
                GROUP BY `visit_hour`
                ORDER BY `visit_hour` ASC";

        return $this->db->select($sql, [$domainId, $startDate, $endDate]);
    }
}
