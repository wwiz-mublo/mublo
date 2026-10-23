<?php
declare(strict_types=1);

namespace Mublo\Service\Member;

use Mublo\Contract\Member\MemberSegmentQueryInterface;
use Mublo\Infrastructure\Database\Database;

/**
 * MemberSegmentQueryInterface 구현 — 사이트·상태·등급·가입일로 회원 ID 를 고른다.
 */
class MemberSegmentQueryService implements MemberSegmentQueryInterface
{
    public function __construct(
        private Database $db
    ) {
    }

    public function findActiveMemberIds(
        int $domainId,
        array $levelValues = [],
        ?string $joinedFrom = null,
        ?string $joinedTo = null,
        int $limit = 10000,
        int $afterMemberId = 0
    ): array {
        $limit = max(1, min(50000, $limit));

        // 관리자·최고관리자 등급은 대상에서 늘 뺀다
        $where = ['m.domain_id = ?', "m.status = 'active'", 'COALESCE(l.is_admin, 0) = 0', 'COALESCE(l.is_super, 0) = 0'];
        $params = [$domainId];
        if ($afterMemberId > 0) {
            $where[] = 'm.member_id > ?';
            $params[] = $afterMemberId;
        }

        $levelValues = array_values(array_unique(array_filter(array_map('intval', $levelValues), fn(int $v) => $v > 0)));
        if ($levelValues !== []) {
            $where[] = 'm.level_value IN (' . implode(',', array_fill(0, count($levelValues), '?')) . ')';
            array_push($params, ...$levelValues);
        }

        if ($joinedFrom !== null && $this->isDate($joinedFrom)) {
            $where[] = 'm.created_at >= ?';
            $params[] = $joinedFrom . ' 00:00:00';
        }
        if ($joinedTo !== null && $this->isDate($joinedTo)) {
            $where[] = 'm.created_at <= ?';
            $params[] = $joinedTo . ' 23:59:59';
        }

        $rows = $this->db->select(
            'SELECT m.member_id FROM members m'
            . ' LEFT JOIN member_levels l ON l.level_value = m.level_value'
            . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY m.member_id ASC LIMIT ' . $limit,
            $params
        );

        return array_map(fn(array $r) => (int) $r['member_id'], $rows);
    }

    private function isDate(string $value): bool
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $d !== false && $d->format('Y-m-d') === $value;
    }
}
