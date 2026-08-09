<?php
declare(strict_types=1);

namespace Mublo\Packages\AiAssistant\Repository;

use Mublo\Infrastructure\Database\Database;
use Mublo\Packages\AiAssistant\Exception\ApiException;
use Mublo\Packages\AiAssistant\Support\CanonicalJson;
use Mublo\Packages\AiAssistant\Support\Time;
use Mublo\Packages\AiAssistant\Support\TokenCodec;
use Mublo\Packages\AiAssistant\Support\Uuid;

final class InteractionRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function find(string $companyId, string $interactionId): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM ai_interactions WHERE company_id = ? AND interaction_id = ? LIMIT 1',
            [$companyId, $interactionId]
        );
    }

    /** @param array<string, mixed> $interaction @return array{job_id: string} */
    public function create(array $interaction): array
    {
        return $this->db->transaction(function () use ($interaction): array {
            $now = Time::database();
            $jobId = Uuid::v4();
            $this->db->insert(
                'INSERT INTO ai_interactions
                    (interaction_id, company_id, customer_id, customer_phone_id, device_id, channel, occurred_at,
                     envelope_json, envelope_sha256, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $interaction['interaction_id'], $interaction['company_id'], $interaction['customer_id'],
                    $interaction['customer_phone_id'], $interaction['device_id'], $interaction['channel'], $interaction['occurred_at'],
                    $interaction['envelope_json'], $interaction['envelope_sha256'], 'QUEUED', $now, $now,
                ]
            );
            $this->db->insert(
                'INSERT INTO ai_analysis_jobs
                    (job_id, interaction_id, company_id, customer_id, status, attempts, available_at,
                     lease_owner, lease_token_hash, lease_expires_at, last_error_code, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, 0, ?, NULL, NULL, NULL, NULL, ?, ?)',
                [$jobId, $interaction['interaction_id'], $interaction['company_id'], $interaction['customer_id'], 'QUEUED', $now, $now, $now]
            );
            return ['job_id' => $jobId];
        });
    }

    /** @return array<string, mixed>|null */
    public function lease(string $workerId, int $leaseSeconds): ?array
    {
        return $this->db->transaction(function () use ($workerId, $leaseSeconds): ?array {
            $now = Time::database();
            $row = $this->db->selectOne(
                "SELECT j.*, i.customer_phone_id, i.device_id, i.channel, i.occurred_at, i.envelope_json, i.envelope_sha256
                   FROM ai_analysis_jobs j
                   JOIN ai_interactions i ON i.interaction_id = j.interaction_id
                  WHERE (j.status = 'QUEUED' AND j.available_at <= ?)
                     OR (j.status = 'LEASED' AND j.lease_expires_at < ?)
                  ORDER BY j.created_at ASC LIMIT 1",
                [$now, $now]
            );
            if ($row === null) {
                return null;
            }
            $leaseToken = TokenCodec::generate(32);
            $expiresAt = Time::database(time() + $leaseSeconds);
            $updated = $this->db->execute(
                "UPDATE ai_analysis_jobs
                    SET status = 'LEASED', attempts = attempts + 1, lease_owner = ?, lease_token_hash = ?,
                        lease_expires_at = ?, updated_at = ?
                  WHERE job_id = ? AND ((status = 'QUEUED' AND available_at <= ?)
                     OR (status = 'LEASED' AND lease_expires_at < ?))",
                [$workerId, TokenCodec::hash($leaseToken), $expiresAt, $now, $row['job_id'], $now, $now]
            );
            if ($updated !== 1) {
                return null;
            }
            $row['lease_token'] = $leaseToken;
            $row['lease_expires_at'] = $expiresAt;
            return $row;
        });
    }

    /** @return array<string, mixed> */
    public function requireLease(string $jobId, string $leaseToken): array
    {
        $row = $this->db->selectOne(
            "SELECT * FROM ai_analysis_jobs WHERE job_id = ? AND status = 'LEASED' LIMIT 1",
            [$jobId]
        );
        if ($row === null || !hash_equals((string) $row['lease_token_hash'], TokenCodec::hash($leaseToken))) {
            throw new ApiException('WORKER_LEASE_INVALID', '유효한 작업 lease가 아닙니다.', 409);
        }
        if (strtotime((string) $row['lease_expires_at'] . ' UTC') < time()) {
            throw new ApiException('WORKER_LEASE_EXPIRED', '작업 lease가 만료되었습니다.', 409);
        }
        return $row;
    }

    /** @param array<string, mixed> $job @param array<string, mixed> $result */
    public function complete(array $job, array $result): void
    {
        $this->db->transaction(function () use ($job, $result): void {
            $now = Time::database();
            $this->db->insert(
                'INSERT INTO ai_analysis_results
                    (analysis_id, job_id, interaction_id, company_id, customer_id, input_cursor,
                     result_json, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $result['analysis_id'], $job['job_id'], $job['interaction_id'], $job['company_id'],
                    $job['customer_id'], $result['input_cursor'], CanonicalJson::encode($result), $now,
                ]
            );
            $updated = $this->db->execute(
                "UPDATE ai_analysis_jobs SET status = 'COMPLETED', lease_token_hash = NULL,
                    lease_expires_at = NULL, updated_at = ? WHERE job_id = ? AND status = 'LEASED'",
                [$now, $job['job_id']]
            );
            if ($updated !== 1) {
                throw new ApiException('WORKER_LEASE_INVALID', '작업 완료 권한이 사라졌습니다.', 409);
            }
            $this->db->execute(
                "UPDATE ai_interactions SET status = 'COMPLETED', updated_at = ?
                  WHERE interaction_id = ? AND company_id = ?",
                [$now, $job['interaction_id'], $job['company_id']]
            );
        });
    }

    public function fail(array $job, string $errorCode, bool $retryable): void
    {
        $now = Time::database();
        $attempts = (int) $job['attempts'];
        $status = $retryable && $attempts < 5 ? 'QUEUED' : 'DEAD_LETTER';
        $availableAt = Time::database(time() + min(900, 30 * (2 ** max(0, $attempts - 1))));
        $this->db->execute(
            'UPDATE ai_analysis_jobs SET status = ?, available_at = ?, lease_owner = NULL,
                lease_token_hash = NULL, lease_expires_at = NULL, last_error_code = ?, updated_at = ?
              WHERE job_id = ?',
            [$status, $availableAt, $errorCode, $now, $job['job_id']]
        );
    }

    /** @return array<string, mixed>|null */
    public function latestAnalysis(string $companyId, string $customerId): ?array
    {
        $row = $this->db->selectOne(
            'SELECT result_json FROM ai_analysis_results
              WHERE company_id = ? AND customer_id = ? ORDER BY created_at DESC LIMIT 1',
            [$companyId, $customerId]
        );
        if ($row === null) {
            return null;
        }
        $result = json_decode((string) $row['result_json'], true, 512, JSON_THROW_ON_ERROR);
        return is_array($result) ? $result : null;
    }
}
