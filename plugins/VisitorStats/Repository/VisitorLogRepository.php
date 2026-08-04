<?php
declare(strict_types=1);

namespace Mublo\Plugin\VisitorStats\Repository;

use Mublo\Infrastructure\Database\Database;

class VisitorLogRepository
{
    private Database $db;
    private string $table = 'plugin_visitor_logs';

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function getDb(): Database
    {
        return $this->db;
    }

    /**
     * 로그 INSERT IGNORE (UK 중복 시 무시)
     *
     * @return bool 실제 삽입 여부
     */
    public function insertIgnore(array $data): bool
    {
        $table = $this->table;
        $columns = implode(', ', array_map(fn($k) => "`{$k}`", array_keys($data)));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));

        $sql = "INSERT IGNORE INTO `{$table}` ({$columns}) VALUES ({$placeholders})";
        $affected = $this->db->execute($sql, array_values($data));

        return $affected > 0;
    }

    /**
     * 신규 방문자 여부 (해당 도메인+IP로 오늘 이전 로그 존재 여부)
     */
    public function isNewVisitor(int $domainId, string $ipAddress, string $today): bool
    {
        $row = $this->db->table($this->table)
            ->select('log_id')
            ->where('domain_id', '=', $domainId)
            ->where('ip_address', '=', $ipAddress)
            ->where('visit_date', '<', $today)
            ->first();

        return $row === null;
    }

    /**
     * 최근 N분 이내 방문자 수
     */
    public function countRecentVisitors(int $domainId, int $minutes = 5): int
    {
        $since = date('Y-m-d H:i:s', time() - ($minutes * 60));

        return $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('created_at', '>=', $since)
            ->count();
    }

    /**
     * 최근 접속 로그 목록 (실시간 화면용)
     */
    public function getRecentLogs(int $domainId, int $limit = 50, int $offset = 0): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);

        return $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('visit_date', '=', date('Y-m-d'))
            ->orderBy('created_at', 'DESC')
            ->limit($limit)
            ->offset($offset)
            ->get();
    }

    /**
     * 오늘 실시간 로그 총 개수
     */
    public function countTodayLogs(int $domainId): int
    {
        return $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('visit_date', '=', date('Y-m-d'))
            ->count();
    }

    /**
     * 특정 IP의 기간 내 접속 로그 목록
     */
    public function getLogsByIp(
        int $domainId,
        string $ipAddress,
        string $startDate,
        string $endDate,
        int $limit = 30,
        int $offset = 0,
    ): array {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);

        return $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('ip_address', '=', $ipAddress)
            ->where('visit_date', '>=', $startDate)
            ->where('visit_date', '<=', $endDate)
            ->orderBy('created_at', 'DESC')
            ->limit($limit)
            ->offset($offset)
            ->get();
    }

    /**
     * 특정 IP의 기간 내 접속 로그 수
     */
    public function countLogsByIp(int $domainId, string $ipAddress, string $startDate, string $endDate): int
    {
        return $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('ip_address', '=', $ipAddress)
            ->where('visit_date', '>=', $startDate)
            ->where('visit_date', '<=', $endDate)
            ->count();
    }

    /**
     * 오래된 로그 삭제 (도메인별)
     */
    public function purgeOldLogs(int $days = 30, ?int $domainId = null): int
    {
        $cutoff = date('Y-m-d', strtotime("-{$days} days"));
        $table = $this->table;

        if ($domainId !== null) {
            return $this->db->execute(
                "DELETE FROM `{$table}` WHERE `visit_date` < ? AND `domain_id` = ?",
                [$cutoff, $domainId]
            );
        }

        return $this->db->execute(
            "DELETE FROM `{$table}` WHERE `visit_date` < ?",
            [$cutoff]
        );
    }

    /**
     * 환경 분석용 집계 (브라우저/OS/디바이스)
     */
    public function getEnvironmentStats(int $domainId, string $field, string $startDate, string $endDate): array
    {
        $table = $this->table;
        $allowedFields = ['browser', 'os', 'device'];
        if (!in_array($field, $allowedFields, true)) {
            return [];
        }

        $sql = "SELECT `{$field}` AS `name`, COUNT(*) AS `count`
                FROM `{$table}`
                WHERE `domain_id` = ? AND `visit_date` BETWEEN ? AND ?
                GROUP BY `{$field}`
                ORDER BY `count` DESC";

        return $this->db->select($sql, [$domainId, $startDate, $endDate]);
    }
}
