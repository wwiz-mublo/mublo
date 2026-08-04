<?php
declare(strict_types=1);

namespace Mublo\Service\Balance;

use Mublo\Contract\Balance\BalanceResetGatewayInterface;
use Mublo\Infrastructure\Database\Database;

/**
 * 코어 잔액 원장의 파괴적 초기화 구현.
 *
 * 일반 잔액 조정은 BalanceManager의 INSERT-ONLY 경로를 사용한다. 이 클래스는 관리자
 * 데이터 초기화 트랜잭션에서만 사용하는 제한된 예외이며, 확장에는 계약만 노출한다.
 */
class BalanceResetManager implements BalanceResetGatewayInterface
{
    private const SOURCE_TYPES = ['core', 'plugin', 'package', 'admin', 'system'];

    public function __construct(private Database $db)
    {
    }

    public function resetSource(int $domainId, string $sourceType, string $sourceName): int
    {
        if (!$this->db->inTransaction()) {
            throw new \LogicException('잔액 원장 초기화는 외부 트랜잭션 안에서만 실행할 수 있습니다.');
        }

        if ($domainId <= 0) {
            throw new \InvalidArgumentException('유효한 도메인 ID가 필요합니다.');
        }

        if (!in_array($sourceType, self::SOURCE_TYPES, true)) {
            throw new \InvalidArgumentException('유효하지 않은 잔액 원장 출처 타입입니다.');
        }

        if ($sourceName === '') {
            throw new \InvalidArgumentException('잔액 원장 출처명이 필요합니다.');
        }

        // 삭제 후에는 영향 회원을 찾을 수 없으므로 먼저 집합을 확보한다.
        $memberRows = $this->db->select(
            'SELECT DISTINCT member_id
               FROM balance_logs
              WHERE domain_id = ? AND source_type = ? AND source_name = ?',
            [$domainId, $sourceType, $sourceName]
        );

        $memberIds = array_values(array_unique(array_filter(
            array_map(static fn(array $row): int => (int) ($row['member_id'] ?? 0), $memberRows),
            static fn(int $memberId): bool => $memberId > 0
        )));

        if ($memberIds === []) {
            return 0;
        }

        sort($memberIds, SORT_NUMERIC);
        $placeholders = implode(',', array_fill(0, count($memberIds), '?'));

        // BalanceManager와 같은 잠금 순서(회원 -> 원장)를 사용해 동시 조정과 직렬화한다.
        $this->db->select(
            "SELECT member_id
               FROM members
              WHERE domain_id = ? AND member_id IN ({$placeholders})
              ORDER BY member_id
              FOR UPDATE",
            array_merge([$domainId], $memberIds)
        );

        $deleted = $this->db->execute(
            'DELETE FROM balance_logs
              WHERE domain_id = ? AND source_type = ? AND source_name = ?',
            [$domainId, $sourceType, $sourceName]
        );

        // 원장을 진실로 유지한다. 존재하는 회원만 갱신하며 다른 도메인은 건드리지 않는다.
        $this->db->execute(
            "UPDATE members m
                SET m.point_balance = (
                    SELECT COALESCE(SUM(bl.amount), 0)
                      FROM balance_logs bl
                     WHERE bl.member_id = m.member_id AND bl.domain_id = ?
                ),
                    m.updated_at = CURRENT_TIMESTAMP
              WHERE m.domain_id = ? AND m.member_id IN ({$placeholders})",
            array_merge([$domainId, $domainId], $memberIds)
        );

        return $deleted;
    }
}
