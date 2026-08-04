<?php
declare(strict_types=1);
namespace Mublo\Packages\Board\Plugins\BoardReport\Repository;

use Mublo\Infrastructure\Database\Database;

/**
 * BoardReportRepository
 *
 * 신고·블라인드 저장소. 모든 조회/쓰기는 domain_id 스코프
 * (확장 필수사항 §1 — 도메인 격리).
 */
class BoardReportRepository
{
    public function __construct(private Database $db) {}

    /* ---------------- 신고 ---------------- */

    public function insertReport(array $data): int
    {
        $this->db->execute(
            "INSERT INTO board_reports
                (domain_id, article_id, board_id, article_title, reason, detail, reporter_id, reporter_ip)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            [
                (int) $data['domain_id'],
                (int) $data['article_id'],
                (int) $data['board_id'],
                (string) $data['article_title'],
                (string) $data['reason'],
                $data['detail'] !== '' ? (string) $data['detail'] : null,
                $data['reporter_id'] !== null ? (int) $data['reporter_id'] : null,
                $data['reporter_ip'] !== null ? (string) $data['reporter_ip'] : null,
            ]
        );

        return (int) $this->db->lastInsertId();
    }

    /** 같은 신고자(회원 또는 IP)의 같은 글 중복 신고 방지 */
    public function hasReported(int $domainId, int $articleId, ?int $memberId, ?string $ip): bool
    {
        if ($memberId !== null) {
            $row = $this->db->selectOne(
                "SELECT report_id FROM board_reports
                 WHERE domain_id = ? AND article_id = ? AND reporter_id = ? LIMIT 1",
                [$domainId, $articleId, $memberId]
            );
            return $row !== null;
        }

        if ($ip !== null && $ip !== '') {
            $row = $this->db->selectOne(
                "SELECT report_id FROM board_reports
                 WHERE domain_id = ? AND article_id = ? AND reporter_id IS NULL AND reporter_ip = ? LIMIT 1",
                [$domainId, $articleId, $ip]
            );
            return $row !== null;
        }

        return false;
    }

    /** @return array{items: array, total: int} */
    public function paginate(int $domainId, string $status, int $page, int $perPage): array
    {
        $where = 'domain_id = ?';
        $bind = [$domainId];
        if ($status !== '') {
            $where .= ' AND status = ?';
            $bind[] = $status;
        }

        $total = (int) ($this->db->selectOne(
            "SELECT COUNT(*) AS cnt FROM board_reports WHERE {$where}",
            $bind
        )['cnt'] ?? 0);

        $offset = max(0, ($page - 1) * $perPage);
        $items = $this->db->select(
            "SELECT * FROM board_reports WHERE {$where}
             ORDER BY report_id DESC LIMIT {$perPage} OFFSET {$offset}",
            $bind
        );

        return ['items' => $items, 'total' => $total];
    }

    public function updateStatus(int $domainId, int $reportId, string $status): void
    {
        $this->db->execute(
            "UPDATE board_reports SET status = ? WHERE domain_id = ? AND report_id = ?",
            [$status, $domainId, $reportId]
        );
    }

    public function findById(int $domainId, int $reportId): ?array
    {
        return $this->db->selectOne(
            "SELECT * FROM board_reports WHERE domain_id = ? AND report_id = ?",
            [$domainId, $reportId]
        );
    }

    /** 같은 글의 대기 신고를 일괄 인용(resolved)으로 (조치 완료 시 자동 전이) */
    public function resolvePendingByArticle(int $domainId, int $articleId): int
    {
        return $this->db->execute(
            "UPDATE board_reports SET status = 'resolved'
             WHERE domain_id = ? AND article_id = ? AND status = 'pending'",
            [$domainId, $articleId]
        );
    }

    /**
     * 같은 글의 누적 신고 건수 (상태 무관)
     *
     * 중복 신고가 hasReported()로 막히므로 건수 = 신고한 사람 수다.
     * 조치 여부와 무관한 값이라 처리·기각 후에도 줄지 않는다.
     */
    public function countByArticle(int $domainId, int $articleId): int
    {
        return (int) ($this->db->selectOne(
            "SELECT COUNT(*) AS cnt FROM board_reports
             WHERE domain_id = ? AND article_id = ?",
            [$domainId, $articleId]
        )['cnt'] ?? 0);
    }

    /* ---------------- 블라인드 ---------------- */

    public function findBlind(int $domainId, int $articleId): ?array
    {
        return $this->db->selectOne(
            "SELECT * FROM board_report_blinds WHERE domain_id = ? AND article_id = ?",
            [$domainId, $articleId]
        );
    }

    public function insertBlind(int $domainId, int $articleId, string $reason): void
    {
        // 이미 블라인드면 사유만 갱신
        $this->db->execute(
            "INSERT INTO board_report_blinds (domain_id, article_id, reason) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE reason = VALUES(reason)",
            [$domainId, $articleId, $reason]
        );
    }

    public function deleteBlind(int $domainId, int $articleId): void
    {
        $this->db->execute(
            "DELETE FROM board_report_blinds WHERE domain_id = ? AND article_id = ?",
            [$domainId, $articleId]
        );
    }
}
