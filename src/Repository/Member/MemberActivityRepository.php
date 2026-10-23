<?php
declare(strict_types=1);

namespace Mublo\Repository\Member;

use Mublo\Infrastructure\Database\Database;

/**
 * 회원 활동 기록 (member_activity_logs)
 *
 * 쓰기는 PageViewedEvent 마다 일어나므로 단순 INSERT 한 번으로 끝낸다.
 */
class MemberActivityRepository
{
    private const TABLE = 'member_activity_logs';

    public function __construct(
        private Database $db
    ) {
    }

    /**
     * 이 회원의 가장 최근 기록 (새로고침 중복 판단용)
     */
    public function findLatest(int $domainId, int $memberId): ?array
    {
        return $this->db->selectOne(
            'SELECT page_url, created_at FROM ' . self::TABLE
            . ' WHERE domain_id = ? AND member_id = ? ORDER BY activity_id DESC LIMIT 1',
            [$domainId, $memberId]
        );
    }

    public function insert(int $domainId, int $memberId, string $pageType, string $url, string $title): void
    {
        $this->db->execute(
            'INSERT INTO ' . self::TABLE
            . ' (domain_id, member_id, page_type, page_url, page_title) VALUES (?, ?, ?, ?, ?)',
            [$domainId, $memberId, $pageType, $url, $title]
        );
    }

    /**
     * 최근 기록 (최신순)
     */
    public function findRecent(int $domainId, int $memberId, int $limit = 30): array
    {
        $limit = max(1, min(200, $limit));

        return $this->db->select(
            'SELECT page_type, page_url, page_title, created_at FROM ' . self::TABLE
            . ' WHERE domain_id = ? AND member_id = ? ORDER BY activity_id DESC LIMIT ' . $limit,
            [$domainId, $memberId]
        );
    }

    /**
     * 보관 기간이 지난 기록 삭제 — 한 번에 너무 많이 지우지 않게 끊는다
     */
    public function purgeOlderThan(int $domainId, string $before, int $limit = 1000): int
    {
        return $this->db->execute(
            'DELETE FROM ' . self::TABLE . ' WHERE domain_id = ? AND created_at < ? LIMIT ' . max(1, $limit),
            [$domainId, $before]
        );
    }

    /**
     * 회원의 모든 사이트 기록 삭제 (탈퇴)
     */
    public function deleteByMember(int $memberId): int
    {
        return $this->db->execute(
            'DELETE FROM ' . self::TABLE . ' WHERE member_id = ?',
            [$memberId]
        );
    }
}
