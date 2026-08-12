<?php
declare(strict_types=1);

namespace Mublo\Packages\AiAssistant\Repository;

use Mublo\Infrastructure\Database\Database;
use Mublo\Packages\AiAssistant\Support\Time;

final class MessageScheduleRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @param array<string, mixed> $schedule */
    public function create(array $schedule): int
    {
        return $this->db->transaction(function () use ($schedule): int {
            $this->db->insert(
                'INSERT INTO ai_message_schedules
                    (schedule_id, company_id, device_id, customer_id, customer_phone_id,
                     dispatch_id, revision, channel, message_class, content_ciphertext,
                     fallback_ciphertext, status, device_status, scheduled_at, expires_at,
                     dispatched_at, completed_at, last_error, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, NULL, NULL, NULL, ?, ?)',
                [
                    $schedule['schedule_id'], $schedule['company_id'], $schedule['device_id'],
                    $schedule['customer_id'], $schedule['customer_phone_id'], $schedule['dispatch_id'],
                    $schedule['revision'], $schedule['channel'], $schedule['message_class'],
                    $schedule['content_ciphertext'], $schedule['fallback_ciphertext'], 'APPROVED',
                    $schedule['scheduled_at'], $schedule['expires_at'], $schedule['created_at'],
                    $schedule['updated_at'],
                ]
            );
            return $this->db->insert(
                'INSERT INTO ai_schedule_dispatch_outbox
                    (schedule_id, company_id, device_id, dispatch_id, revision, status,
                     attempt_count, available_at, lease_token_hash, lease_expires_at,
                     fcm_message_id, last_error, acknowledged_at, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, 0, ?, NULL, NULL, NULL, NULL, NULL, ?, ?)',
                [
                    $schedule['schedule_id'], $schedule['company_id'], $schedule['device_id'],
                    $schedule['dispatch_id'], $schedule['revision'], 'PENDING',
                    $schedule['scheduled_at'], $schedule['created_at'], $schedule['updated_at'],
                ]
            );
        });
    }

    /** @return array<string, mixed>|null */
    public function find(string $companyId, string $scheduleId): ?array
    {
        return $this->db->selectOne(
            'SELECT s.*, o.outbox_id, o.status AS outbox_status, o.attempt_count,
                    d.fcm_token, d.status AS device_record_status,
                    p.phone_ciphertext
               FROM ai_message_schedules s
               JOIN ai_schedule_dispatch_outbox o ON o.dispatch_id = s.dispatch_id
               JOIN ai_devices d ON d.device_id = s.device_id AND d.company_id = s.company_id
               JOIN ai_customer_phones p ON p.customer_phone_id = s.customer_phone_id
                    AND p.company_id = s.company_id
              WHERE s.company_id = ? AND s.schedule_id = ? LIMIT 1',
            [$companyId, $scheduleId]
        );
    }

    public function cancel(string $companyId, string $scheduleId): bool
    {
        return $this->db->transaction(function () use ($companyId, $scheduleId): bool {
            $now = Time::database();
            $changed = $this->db->execute(
                "UPDATE ai_message_schedules
                    SET status = 'CANCELED', device_status = 'CANCELED', completed_at = ?, updated_at = ?
                  WHERE company_id = ? AND schedule_id = ?
                    AND status NOT IN ('SENT', 'CANCELED')",
                [$now, $now, $companyId, $scheduleId]
            );
            $this->db->execute(
                "UPDATE ai_schedule_dispatch_outbox
                    SET status = 'CANCELED', lease_token_hash = NULL, lease_expires_at = NULL, updated_at = ?
                  WHERE company_id = ? AND schedule_id = ? AND status NOT IN ('ACKED', 'CANCELED')",
                [$now, $companyId, $scheduleId]
            );
            return $changed > 0;
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function claimDue(int $limit, int $leaseSeconds): array
    {
        return $this->db->transaction(function () use ($limit, $leaseSeconds): array {
            $now = Time::database();
            $rows = $this->db->select(
                "SELECT o.*, s.status AS schedule_status, s.expires_at, d.fcm_token,
                        d.status AS device_record_status
                   FROM ai_schedule_dispatch_outbox o
                   JOIN ai_message_schedules s ON s.schedule_id = o.schedule_id
                   JOIN ai_devices d ON d.device_id = o.device_id AND d.company_id = o.company_id
                  WHERE o.status IN ('PENDING', 'RETRY', 'LEASED')
                    AND o.available_at <= ?
                    AND (o.lease_expires_at IS NULL OR o.lease_expires_at < ?)
                  ORDER BY o.available_at ASC, o.outbox_id ASC
                  LIMIT " . max(1, min(100, $limit)),
                [$now, $now]
            );
            $claimed = [];
            foreach ($rows as $row) {
                $token = bin2hex(random_bytes(24));
                $updated = $this->db->execute(
                    "UPDATE ai_schedule_dispatch_outbox
                        SET status = 'LEASED', lease_token_hash = ?, lease_expires_at = ?, updated_at = ?
                      WHERE outbox_id = ? AND status IN ('PENDING', 'RETRY', 'LEASED')
                        AND (lease_expires_at IS NULL OR lease_expires_at < ?)",
                    [hash('sha256', $token), Time::database(time() + $leaseSeconds), $now, $row['outbox_id'], $now]
                );
                if ($updated === 1) {
                    $row['lease_token'] = $token;
                    $claimed[] = $row;
                }
            }
            return $claimed;
        });
    }

    public function markPushAccepted(int $outboxId, string $leaseToken, string $messageId, int $nextAttemptAt): bool
    {
        $now = Time::database();
        return $this->db->transaction(function () use ($outboxId, $leaseToken, $messageId, $nextAttemptAt, $now): bool {
            $updated = $this->db->execute(
                "UPDATE ai_schedule_dispatch_outbox
                    SET status = 'RETRY', attempt_count = attempt_count + 1,
                        available_at = ?, lease_token_hash = NULL, lease_expires_at = NULL,
                        fcm_message_id = ?, last_error = NULL, updated_at = ?
                  WHERE outbox_id = ? AND status = 'LEASED' AND lease_token_hash = ?",
                [Time::database($nextAttemptAt), $messageId, $now, $outboxId, hash('sha256', $leaseToken)]
            );
            if ($updated === 1) {
                $this->db->execute(
                    "UPDATE ai_message_schedules
                        SET status = 'DISPATCHING', dispatched_at = COALESCE(dispatched_at, ?),
                            updated_at = ?
                      WHERE schedule_id = (
                          SELECT schedule_id FROM ai_schedule_dispatch_outbox WHERE outbox_id = ?
                      ) AND status = 'APPROVED'",
                    [$now, $now, $outboxId]
                );
            }
            return $updated === 1;
        });
    }

    public function markPushFailed(
        int $outboxId,
        string $leaseToken,
        string $error,
        int $nextAttemptAt,
        bool $terminal
    ): bool {
        $now = Time::database();
        return $this->db->transaction(function () use (
            $outboxId, $leaseToken, $error, $nextAttemptAt, $terminal, $now
        ): bool {
            $status = $terminal ? 'DEAD' : 'RETRY';
            $updated = $this->db->execute(
                'UPDATE ai_schedule_dispatch_outbox
                    SET status = ?, attempt_count = attempt_count + 1, available_at = ?,
                        lease_token_hash = NULL, lease_expires_at = NULL, last_error = ?, updated_at = ?
                  WHERE outbox_id = ? AND status = ? AND lease_token_hash = ?',
                [
                    $status, Time::database($nextAttemptAt), mb_substr($error, 0, 500), $now,
                    $outboxId, 'LEASED', hash('sha256', $leaseToken),
                ]
            );
            if ($updated === 1 && $terminal) {
                $this->db->execute(
                    "UPDATE ai_message_schedules
                        SET status = 'FAILED', completed_at = ?, last_error = ?, updated_at = ?
                      WHERE schedule_id = (
                          SELECT schedule_id FROM ai_schedule_dispatch_outbox WHERE outbox_id = ?
                      ) AND status NOT IN ('SENT', 'CANCELED')",
                    [$now, mb_substr($error, 0, 500), $now, $outboxId]
                );
            }
            return $updated === 1;
        });
    }

    public function acknowledge(
        string $companyId,
        string $scheduleId,
        string $dispatchId,
        int $revision,
        string $deviceStatus,
        ?string $error
    ): bool {
        return $this->db->transaction(function () use (
            $companyId, $scheduleId, $dispatchId, $revision, $deviceStatus, $error
        ): bool {
            $row = $this->db->selectOne(
                'SELECT status FROM ai_message_schedules
                  WHERE company_id = ? AND schedule_id = ? AND dispatch_id = ? AND revision = ? LIMIT 1',
                [$companyId, $scheduleId, $dispatchId, $revision]
            );
            if ($row === null) return false;
            if ((string) $row['status'] === 'CANCELED') return true;
            // 단말의 실패 우선 정책과 맞춘다. 여러 조각 SMS 중 하나가 실패한 뒤
            // 늦게 도착한 SENT/DELIVERED ACK가 최종 실패를 되돌리면 안 된다.
            if ((string) $row['status'] === 'FAILED'
                && !in_array($deviceStatus, ['FAILED', 'CANCELED'], true)
            ) return true;
            $terminal = in_array($deviceStatus, ['SENT', 'DELIVERED', 'FAILED', 'CANCELED'], true);
            $scheduleStatus = match ($deviceStatus) {
                'SENT', 'DELIVERED' => 'SENT',
                'FAILED' => 'FAILED',
                'CANCELED' => 'CANCELED',
                default => 'DISPATCHING',
            };
            $now = Time::database();
            $this->db->execute(
                'UPDATE ai_message_schedules
                    SET status = ?, device_status = ?, completed_at = ?, last_error = ?, updated_at = ?
                  WHERE company_id = ? AND schedule_id = ? AND dispatch_id = ? AND revision = ?',
                [
                    $scheduleStatus, $deviceStatus, $terminal ? $now : null,
                    $error === null ? null : mb_substr($error, 0, 500), $now,
                    $companyId, $scheduleId, $dispatchId, $revision,
                ]
            );
            $this->db->execute(
                "UPDATE ai_schedule_dispatch_outbox
                    SET status = 'ACKED', acknowledged_at = COALESCE(acknowledged_at, ?),
                        lease_token_hash = NULL, lease_expires_at = NULL, updated_at = ?
                  WHERE company_id = ? AND schedule_id = ? AND dispatch_id = ? AND revision = ?
                    AND status NOT IN ('CANCELED', 'DEAD')",
                [$now, $now, $companyId, $scheduleId, $dispatchId, $revision]
            );
            return true;
        });
    }
}
