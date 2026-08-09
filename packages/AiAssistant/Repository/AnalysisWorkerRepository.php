<?php
declare(strict_types=1);

namespace Mublo\Packages\AiAssistant\Repository;

use Mublo\Infrastructure\Database\Database;
use Mublo\Packages\AiAssistant\Exception\ApiException;
use Mublo\Packages\AiAssistant\Support\CanonicalJson;
use Mublo\Packages\AiAssistant\Support\Time;
use Mublo\Packages\AiAssistant\Support\TokenCodec;

final class AnalysisWorkerRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function lease(string $workerId, int $leaseSeconds): ?array
    {
        return $this->db->transaction(function () use ($workerId, $leaseSeconds): ?array {
            $now = Time::database();
            $row = $this->db->selectOne(
                "SELECT j.*, m.manifest_json, m.manifest_sha256
                   FROM ai_analysis_jobs_v2 j
                   JOIN ai_analysis_manifests m ON m.run_id = j.run_id
                  WHERE (j.status = 'QUEUED' AND j.available_at <= ?)
                     OR (j.status = 'LEASED' AND j.lease_expires_at < ?)
                  ORDER BY j.created_at ASC LIMIT 1",
                [$now, $now]
            );
            if ($row === null) {
                return null;
            }
            $token = TokenCodec::generate(32);
            $expires = Time::database(time() + $leaseSeconds);
            $updated = $this->db->execute(
                "UPDATE ai_analysis_jobs_v2 SET status = 'LEASED', attempts = attempts + 1,
                    lease_owner = ?, lease_token_hash = ?, lease_expires_at = ?, updated_at = ?
                  WHERE job_id = ? AND ((status = 'QUEUED' AND available_at <= ?)
                     OR (status = 'LEASED' AND lease_expires_at < ?))",
                [$workerId, TokenCodec::hash($token), $expires, $now, $row['job_id'], $now, $now]
            );
            if ($updated !== 1) {
                return null;
            }
            $this->db->execute(
                "UPDATE ai_analysis_runs SET status = 'LEASED', stage = 'DOWNLOADING',
                    progress_sequence = progress_sequence + 1, updated_at = ? WHERE run_id = ?",
                [$now, $row['run_id']]
            );
            $row['lease_token'] = $token;
            $row['lease_expires_at'] = $expires;
            $row['attempts'] = (int) $row['attempts'] + 1;
            return $row;
        });
    }

    /** @return array<string, mixed> */
    public function requireLease(string $jobId, string $leaseToken): array
    {
        $row = $this->db->selectOne(
            "SELECT j.*, m.manifest_id, m.manifest_sha256, m.manifest_json, r.customer_id, r.input_cursor
               FROM ai_analysis_jobs_v2 j
               JOIN ai_analysis_manifests m ON m.run_id = j.run_id
               JOIN ai_analysis_runs r ON r.run_id = j.run_id
              WHERE j.job_id = ? AND j.status = 'LEASED' LIMIT 1",
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

    /** @return array<string, mixed> */
    public function renew(array $job, string $workerId, int $leaseSeconds): array
    {
        if (!hash_equals((string) $job['lease_owner'], $workerId)) {
            throw new ApiException('WORKER_LEASE_OWNER_MISMATCH', 'lease 소유 Worker가 아닙니다.', 409);
        }
        $now = Time::database();
        $expires = Time::database(time() + $leaseSeconds);
        $this->db->execute(
            'UPDATE ai_analysis_jobs_v2 SET lease_expires_at = ?, updated_at = ? WHERE job_id = ?',
            [$expires, $now, $job['job_id']]
        );
        return ['job_id' => (string) $job['job_id'], 'lease_expires_at' => Time::api($expires)];
    }

    /** @param array<string, mixed> $job @param array<string, mixed> $result */
    public function complete(array $job, array $result): void
    {
        $this->db->transaction(function () use ($job, $result): void {
            $now = Time::database();
            $this->db->insert(
                'INSERT INTO ai_analysis_results_v2
                    (analysis_id, job_id, run_id, company_id, customer_id, manifest_sha256,
                     terminal_status, result_json, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $result['analysis_id'], $job['job_id'], $job['run_id'], $job['company_id'],
                    $job['customer_id'], $job['manifest_sha256'], $result['terminal_status'],
                    CanonicalJson::encode($result), $now,
                ]
            );
            $this->db->execute(
                "UPDATE ai_analysis_jobs_v2 SET status = 'COMPLETED', lease_token_hash = NULL,
                    lease_expires_at = NULL, updated_at = ? WHERE job_id = ? AND status = 'LEASED'",
                [$now, $job['job_id']]
            );
            $this->db->execute(
                'UPDATE ai_analysis_runs SET status = ?, stage = ?, progress_processed = progress_total,
                    progress_sequence = progress_sequence + 1, retryable = 0, reason_code = ?, analysis_id = ?,
                    updated_at = ? WHERE run_id = ?',
                [
                    $result['terminal_status'], 'COMPLETED', $result['insufficient_reason'] ?? null,
                    $result['analysis_id'], $now, $job['run_id'],
                ]
            );
            $this->db->execute(
                'UPDATE ai_analysis_batches SET updated_at = ? WHERE batch_id =
                    (SELECT batch_id FROM ai_analysis_runs WHERE run_id = ?)',
                [$now, $job['run_id']]
            );
        });
    }

    /** @param array<string, mixed> $job */
    public function fail(array $job, string $errorCode, bool $retryable): string
    {
        $attempts = (int) $job['attempts'];
        $willRetry = $retryable && $attempts < 5;
        $jobStatus = $willRetry ? 'QUEUED' : 'DEAD_LETTER';
        $runStatus = $willRetry ? 'RETRY_WAIT' : 'FAILED_FINAL';
        $now = Time::database();
        $available = Time::database(time() + min(900, 30 * (2 ** max(0, $attempts - 1))));
        $this->db->transaction(function () use ($job, $errorCode, $jobStatus, $runStatus, $willRetry, $now, $available): void {
            $this->db->execute(
                'UPDATE ai_analysis_jobs_v2 SET status = ?, available_at = ?, lease_owner = NULL,
                    lease_token_hash = NULL, lease_expires_at = NULL, last_error_code = ?, updated_at = ? WHERE job_id = ?',
                [$jobStatus, $available, $errorCode, $now, $job['job_id']]
            );
            $this->db->execute(
                'UPDATE ai_analysis_runs SET status = ?, stage = ?, retryable = ?, reason_code = ?,
                    progress_sequence = progress_sequence + 1, updated_at = ? WHERE run_id = ?',
                [$runStatus, 'WAITING_WORKER', $willRetry ? 1 : 0, $errorCode, $now, $job['run_id']]
            );
        });
        return $jobStatus;
    }

    public function hasJob(string $jobId): bool
    {
        return $this->db->selectOne('SELECT job_id FROM ai_analysis_jobs_v2 WHERE job_id = ? LIMIT 1', [$jobId]) !== null;
    }

    /** @param array<string, mixed> $heartbeat */
    public function heartbeat(array $heartbeat): void
    {
        $now = Time::database();
        $existing = $this->db->selectOne('SELECT worker_id FROM ai_worker_heartbeats WHERE worker_id = ? LIMIT 1', [$heartbeat['worker_id']]);
        $values = [
            $heartbeat['status'], CanonicalJson::encode($heartbeat['capabilities']), $heartbeat['active_jobs'],
            $heartbeat['service_version'], $now, $now, $heartbeat['worker_id'],
        ];
        if ($existing === null) {
            $this->db->insert(
                'INSERT INTO ai_worker_heartbeats
                    (status, capabilities_json, active_job_count, version, observed_at, received_at, worker_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                $values
            );
            return;
        }
        $this->db->execute(
            'UPDATE ai_worker_heartbeats SET status = ?, capabilities_json = ?, active_job_count = ?,
                version = ?, observed_at = ?, received_at = ? WHERE worker_id = ?',
            $values
        );
    }
}
