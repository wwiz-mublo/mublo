<?php
declare(strict_types=1);

namespace Tests\Innopay\Support;

use Mublo\Plugin\Innopay\Repository\InnopayAttemptRepository;

/**
 * 메모리 결제 시도 저장소 — 실제 저장소와 같은 상태 전이 규칙을 따른다.
 */
final class MemoryAttemptRepository extends InnopayAttemptRepository
{
    /** @var array<string, array> */
    public array $rows = [];

    public function find(int $domainId, string $moid): ?array
    {
        return $this->rows["{$domainId}:{$moid}"] ?? null;
    }

    public function prepare(int $domainId, string $moid, array $data): bool
    {
        $existing = $this->find($domainId, $moid);
        if ($existing !== null && !in_array($existing['status'], [self::READY, self::FAILED, self::CANCELLED], true)) {
            return false;
        }
        $this->rows["{$domainId}:{$moid}"] = [
            'domain_id' => $domainId,
            'moid' => $moid,
            'consumer' => (string) ($data['consumer'] ?? ''),
            'success_url' => (string) ($data['success_url'] ?? ''),
            'fail_url' => (string) ($data['fail_url'] ?? ''),
            'pay_method' => (string) ($data['pay_method'] ?? ''),
            'amount' => (int) ($data['amount'] ?? 0),
            'status' => self::READY,
            'tid' => null,
            'result_code' => null,
            'result_msg' => null,
            'updated_at' => '2026-11-06 12:00:00',
        ];
        return true;
    }

    public function transition(int $domainId, string $moid, array $from, string $to, array $extra = []): bool
    {
        $row = $this->find($domainId, $moid);
        if ($row === null || !in_array($row['status'], $from, true)) {
            return false;
        }
        $this->rows["{$domainId}:{$moid}"] = ['status' => $to] + $extra + $row;
        return true;
    }

    public function update(int $domainId, string $moid, array $fields): void
    {
        $key = "{$domainId}:{$moid}";
        if (isset($this->rows[$key])) {
            $this->rows[$key] = $fields + $this->rows[$key];
        }
    }

    public function findNeedsAction(int $domainId, int $limit = 20): array
    {
        return array_values(array_filter($this->rows, fn($r) => $r['domain_id'] === $domainId && $r['status'] === self::NEEDS_ACTION));
    }
}
