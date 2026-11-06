<?php
declare(strict_types=1);

namespace Mublo\Plugin\Innopay\Repository;

use Mublo\Infrastructure\Database\Database;

class InnopayLogRepository
{
    private string $table = 'plugin_innopay_logs';

    public function __construct(private Database $db)
    {
    }

    public function insert(array $row): void
    {
        $this->db->table($this->table)->insert($row);
    }

    /**
     * @param array{keyword?: string, step?: string, level?: string, date?: string} $filters
     */
    public function search(int $domainId, array $filters, int $limit, int $offset): array
    {
        [$where, $params] = $this->buildWhere($domainId, $filters);

        return $this->db->select(
            "SELECT * FROM {$this->table} WHERE {$where} ORDER BY log_id DESC LIMIT "
                . max(1, $limit) . ' OFFSET ' . max(0, $offset),
            $params
        );
    }

    public function count(int $domainId, array $filters): int
    {
        [$where, $params] = $this->buildWhere($domainId, $filters);
        $row = $this->db->selectOne("SELECT COUNT(*) AS cnt FROM {$this->table} WHERE {$where}", $params);
        return (int) ($row['cnt'] ?? 0);
    }

    /** 결제 시도 한 건에서 브라우저가 보낸 로그 수 — 로그 엔드포인트 남용 방지용 */
    public function countClientLogs(int $domainId, string $moid): int
    {
        $row = $this->db->selectOne(
            "SELECT COUNT(*) AS cnt FROM {$this->table} WHERE domain_id = ? AND moid = ? AND step LIKE 'CLIENT\\_%'",
            [$domainId, $moid]
        );
        return (int) ($row['cnt'] ?? 0);
    }

    /** @return array{0: string, 1: array} */
    private function buildWhere(int $domainId, array $filters): array
    {
        $where = ['domain_id = ?'];
        $params = [$domainId];

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $where[] = '(moid = ? OR tid = ?)';
            $params[] = $keyword;
            $params[] = $keyword;
        }

        $step = trim((string) ($filters['step'] ?? ''));
        if ($step !== '') {
            $where[] = 'step = ?';
            $params[] = $step;
        }

        $level = trim((string) ($filters['level'] ?? ''));
        if ($level !== '') {
            $where[] = 'level = ?';
            $params[] = $level;
        }

        $date = trim((string) ($filters['date'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $where[] = 'created_at >= ? AND created_at < DATE_ADD(?, INTERVAL 1 DAY)';
            $params[] = $date . ' 00:00:00';
            $params[] = $date . ' 00:00:00';
        }

        return [implode(' AND ', $where), $params];
    }
}
