<?php

namespace Mublo\Packages\Shop\Repository;

use Mublo\Infrastructure\Database\Database;
use Mublo\Infrastructure\Database\DatabaseManager;

class ActionExecutionRepository
{
    public function __construct(private ?Database $database = null)
    {
        $this->database ??= DatabaseManager::getInstance()->connect();
    }

    public function create(array $data): int
    {
        return $this->database->table('shop_action_executions')->insert($data);
    }

    public function findByKey(string $key): ?array
    {
        $row = $this->database->table('shop_action_executions')
            ->where('execution_key', '=', $key)->first();
        return $row ?: null;
    }

    public function find(int $executionId): ?array
    {
        $row = $this->database->table('shop_action_executions')
            ->where('execution_id', '=', $executionId)->first();
        return $row ?: null;
    }

    /** @return int[] */
    public function findDueIds(int $domainId, int $limit): array
    {
        $rows = $this->database->select(
            "SELECT execution_id FROM shop_action_executions
             WHERE domain_id = ? AND status IN ('PENDING','FAILED')
               AND attempts < max_attempts
               AND (next_attempt_at IS NULL OR next_attempt_at <= ?)
             ORDER BY execution_id ASC LIMIT ?",
            [$domainId, date('Y-m-d H:i:s'), max(1, $limit)]
        );
        return array_map(static fn(array $row): int => (int) $row['execution_id'], $rows);
    }

    /**
     * lease 시간 동안 끝나지 않은 RUNNING 작업을 재시도 큐로 되돌린다.
     * 이미 소비한 attempts는 유지해 무한 복구를 막는다.
     */
    public function recoverStaleRunning(int $domainId, int $leaseSeconds = 300): int
    {
        $now = date('Y-m-d H:i:s');
        $staleBefore = date('Y-m-d H:i:s', time() - max(30, $leaseSeconds));

        return $this->database->execute(
            "UPDATE shop_action_executions
             SET status='FAILED',
                 last_error='실행 프로세스가 완료되지 않아 재시도 대기열로 복구되었습니다.',
                 next_attempt_at=?, updated_at=?
             WHERE domain_id=? AND status='RUNNING'
               AND started_at IS NOT NULL AND started_at <= ?",
            [$now, $now, $domainId, $staleBefore]
        );
    }

    public function claim(int $executionId): bool
    {
        return $this->database->execute(
            "UPDATE shop_action_executions
             SET status='RUNNING', attempts=attempts+1, started_at=?, updated_at=?
             WHERE execution_id=? AND status IN ('PENDING','FAILED') AND attempts < max_attempts",
            [date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), $executionId]
        ) > 0;
    }

    public function markSuccess(int $executionId): void
    {
        $this->database->table('shop_action_executions')->where('execution_id', '=', $executionId)->update([
            'status' => 'SUCCESS', 'last_error' => null, 'next_attempt_at' => null,
            'completed_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function markFailed(int $executionId, int $attempts, string $error): void
    {
        $delay = min(3600, 30 * (2 ** max(0, $attempts - 1)));
        $this->database->table('shop_action_executions')->where('execution_id', '=', $executionId)->update([
            'status' => 'FAILED',
            'last_error' => mb_substr($error, 0, 1000),
            'next_attempt_at' => date('Y-m-d H:i:s', time() + $delay),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
