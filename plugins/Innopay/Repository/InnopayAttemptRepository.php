<?php
declare(strict_types=1);

namespace Mublo\Plugin\Innopay\Repository;

use Mublo\Infrastructure\Database\Database;

/**
 * 결제 시도(주문번호 한 건의 진행 상태) 저장소
 */
class InnopayAttemptRepository
{
    public const READY = 'READY';
    public const APPROVING = 'APPROVING';
    public const APPROVED = 'APPROVED';
    public const COMPLETED = 'COMPLETED';
    public const FAILED = 'FAILED';
    public const CANCELLED = 'CANCELLED';
    /** 승인은 됐는데 주문 반영도 자동 취소도 못 한 상태 — 관리자 확인 필요 */
    public const NEEDS_ACTION = 'NEEDS_ACTION';
    /** 가상계좌 발급됨 — 입금 통보를 기다린다 */
    public const DEPOSIT_WAITING = 'DEPOSIT_WAITING';
    /** 가상계좌 입금이 통보되고 거래조회로 확인됨 — 주문 반영 중 */
    public const DEPOSITED = 'DEPOSITED';

    private string $table = 'plugin_innopay_attempts';

    public function __construct(private Database $db)
    {
    }

    public function find(int $domainId, string $moid): ?array
    {
        return $this->db->selectOne(
            "SELECT * FROM {$this->table} WHERE domain_id = ? AND moid = ?",
            [$domainId, $moid]
        ) ?: null;
    }

    /**
     * 결제 준비 — 같은 주문번호로 다시 결제하면 READY 로 되돌린다.
     *
     * 단, 이미 승인이 끝났거나 처리 중인 시도는 덮어쓰지 않는다(false 반환).
     * 이노페이도 승인된 moid 로는 결제창을 열지 않는다.
     */
    public function prepare(int $domainId, string $moid, array $data): bool
    {
        $existing = $this->find($domainId, $moid);

        $row = [
            'consumer' => (string) ($data['consumer'] ?? ''),
            'success_url' => mb_substr((string) ($data['success_url'] ?? ''), 0, 500),
            'fail_url' => mb_substr((string) ($data['fail_url'] ?? ''), 0, 500),
            'pay_method' => (string) ($data['pay_method'] ?? ''),
            'amount' => (int) ($data['amount'] ?? 0),
            'status' => self::READY,
            'tid' => null,
            'vbank_bank' => null,
            'vbank_account' => null,
            'vbank_due_date' => null,
            'result_code' => null,
            'result_msg' => null,
        ];

        if ($existing === null) {
            $this->db->table($this->table)->insert(['domain_id' => $domainId, 'moid' => $moid] + $row);
            return true;
        }

        $placeholders = implode(',', array_fill(0, count(self::REPREPARABLE), '?'));
        $sets = implode(', ', array_map(static fn($k) => "{$k} = ?", array_keys($row)));

        $affected = $this->db->execute(
            "UPDATE {$this->table} SET {$sets} WHERE domain_id = ? AND moid = ? AND status IN ({$placeholders})",
            [...array_values($row), $domainId, $moid, ...self::REPREPARABLE]
        );

        return $affected > 0 || ($this->find($domainId, $moid)['status'] ?? '') === self::READY;
    }

    /** 다시 결제를 시작해도 되는 상태 */
    private const REPREPARABLE = [self::READY, self::FAILED, self::CANCELLED];

    /**
     * 조건부 상태 전이 (CAS) — 같은 복귀 요청이 두 번 들어와도 승인은 한 번만 나간다.
     *
     * @param string[] $from
     */
    public function transition(int $domainId, string $moid, array $from, string $to, array $extra = []): bool
    {
        $fields = ['status' => $to] + $extra;
        $sets = implode(', ', array_map(static fn($k) => "{$k} = ?", array_keys($fields)));
        $placeholders = implode(',', array_fill(0, count($from), '?'));

        return $this->db->execute(
            "UPDATE {$this->table} SET {$sets} WHERE domain_id = ? AND moid = ? AND status IN ({$placeholders})",
            [...array_values($fields), $domainId, $moid, ...$from]
        ) > 0;
    }

    public function update(int $domainId, string $moid, array $fields): void
    {
        if ($fields === []) {
            return;
        }
        if (isset($fields['result_msg'])) {
            $fields['result_msg'] = mb_substr((string) $fields['result_msg'], 0, 255);
        }

        $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('moid', '=', $moid)
            ->update($fields);
    }

    /** 관리자 확인이 필요한 시도 목록 */
    public function findNeedsAction(int $domainId, int $limit = 20): array
    {
        return $this->db->select(
            "SELECT * FROM {$this->table} WHERE domain_id = ? AND status = ? ORDER BY updated_at DESC LIMIT " . max(1, $limit),
            [$domainId, self::NEEDS_ACTION]
        );
    }
}
