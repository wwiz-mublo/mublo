<?php
declare(strict_types=1);

namespace Mublo\Packages\AiAssistant\Repository;

use Mublo\Infrastructure\Database\Database;
use Mublo\Packages\AiAssistant\Exception\ApiException;
use Mublo\Packages\AiAssistant\Support\CanonicalJson;
use Mublo\Packages\AiAssistant\Support\Time;

final class AnalysisRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function findConsent(string $companyId, string $receiptId): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM ai_analysis_consent_receipts WHERE company_id = ? AND consent_receipt_id = ? LIMIT 1',
            [$companyId, $receiptId]
        );
    }

    /** @param array<string, mixed> $receipt */
    public function createConsent(array $receipt): void
    {
        $this->db->insert(
            'INSERT INTO ai_analysis_consent_receipts
                (consent_receipt_id, company_id, user_id, device_id, consent_version, accepted_at,
                 selected_customer_set_sha256, customer_ids_json, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $receipt['consent_receipt_id'], $receipt['company_id'], $receipt['user_id'], $receipt['device_id'],
                $receipt['consent_version'], $receipt['accepted_at'], $receipt['selected_customer_set_sha256'],
                CanonicalJson::encode($receipt['customer_ids']), Time::database(),
            ]
        );
    }

    /** @param array<string, mixed> $batch @param list<array<string, mixed>> $runs */
    public function createBatch(array $batch, array $runs): void
    {
        $this->db->transaction(function () use ($batch, $runs): void {
            $now = Time::database();
            $this->db->insert(
                'INSERT INTO ai_analysis_batches
                    (batch_id, company_id, requested_by_user_id, device_id, consent_receipt_id, purpose,
                     selected_customer_set_sha256, status, total_customers, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $batch['batch_id'], $batch['company_id'], $batch['user_id'], $batch['device_id'],
                    $batch['consent_receipt_id'], $batch['purpose'], $batch['selected_customer_set_sha256'],
                    'IN_PROGRESS', count($runs), $now, $now,
                ]
            );
            foreach ($runs as $run) {
                $this->db->insert(
                    'INSERT INTO ai_analysis_runs
                        (run_id, batch_id, company_id, customer_id, mode, base_analysis_id, from_cursor,
                         input_cursor, status, stage, progress_processed, progress_total, progress_sequence,
                         retryable, reason_code, analysis_id, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, NULL, NULL, ?, ?)',
                    [
                        $run['run_id'], $batch['batch_id'], $batch['company_id'], $run['customer_id'],
                        $run['mode'], $run['base_analysis_id'], $run['from_cursor'], $run['input_cursor'],
                        'QUEUED', 'WAITING_WORKER', 0, count($run['manifest']['interactions']), $now, $now,
                    ]
                );
                $this->db->insert(
                    'INSERT INTO ai_analysis_manifests
                        (manifest_id, run_id, company_id, customer_id, manifest_sha256, manifest_json, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [
                        $run['manifest']['manifest_id'], $run['run_id'], $batch['company_id'], $run['customer_id'],
                        $run['manifest']['manifest_sha256'], CanonicalJson::encode($run['manifest']), $now,
                    ]
                );
                $this->db->insert(
                    'INSERT INTO ai_analysis_jobs_v2
                        (job_id, run_id, company_id, status, attempts, available_at, lease_owner,
                         lease_token_hash, lease_expires_at, last_error_code, created_at, updated_at)
                     VALUES (?, ?, ?, ?, 0, ?, NULL, NULL, NULL, NULL, ?, ?)',
                    [$run['job_id'], $run['run_id'], $batch['company_id'], 'QUEUED', $now, $now, $now]
                );
            }
        });
    }

    /** @return array<string, mixed>|null */
    public function findBatch(string $companyId, string $batchId): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM ai_analysis_batches WHERE company_id = ? AND batch_id = ? LIMIT 1',
            [$companyId, $batchId]
        );
    }

    /** @return list<array<string, mixed>> */
    public function listRuns(string $companyId, string $batchId): array
    {
        return $this->db->select(
            'SELECT * FROM ai_analysis_runs WHERE company_id = ? AND batch_id = ? ORDER BY created_at, run_id',
            [$companyId, $batchId]
        );
    }

    /** @return array<string, mixed>|null */
    public function findRun(string $companyId, string $runId): ?array
    {
        return $this->db->selectOne(
            'SELECT r.*, m.manifest_id, m.manifest_sha256, v.result_json
               FROM ai_analysis_runs r
               JOIN ai_analysis_manifests m ON m.run_id = r.run_id
               LEFT JOIN ai_analysis_results_v2 v ON v.run_id = r.run_id
              WHERE r.company_id = ? AND r.run_id = ? LIMIT 1',
            [$companyId, $runId]
        );
    }

    public function retry(string $companyId, string $runId): void
    {
        $this->db->transaction(function () use ($companyId, $runId): void {
            $run = $this->db->selectOne(
                'SELECT * FROM ai_analysis_runs WHERE company_id = ? AND run_id = ? LIMIT 1',
                [$companyId, $runId]
            );
            if ($run === null) {
                throw new ApiException('ANALYSIS_RUN_NOT_FOUND', '분석 실행을 찾을 수 없습니다.', 404);
            }
            if ((int) $run['retryable'] !== 1) {
                throw new ApiException('ANALYSIS_RETRY_NOT_ALLOWED', '현재 상태에서는 다시 시도할 수 없습니다.', 409);
            }
            $now = Time::database();
            $this->db->execute(
                "UPDATE ai_analysis_runs SET status = 'QUEUED', stage = 'WAITING_WORKER', retryable = 0,
                    reason_code = NULL, progress_processed = 0, progress_sequence = progress_sequence + 1,
                    updated_at = ? WHERE company_id = ? AND run_id = ?",
                [$now, $companyId, $runId]
            );
            $this->db->execute(
                "UPDATE ai_analysis_jobs_v2 SET status = 'QUEUED', available_at = ?, lease_owner = NULL,
                    lease_token_hash = NULL, lease_expires_at = NULL, last_error_code = NULL, updated_at = ?
                  WHERE company_id = ? AND run_id = ?",
                [$now, $now, $companyId, $runId]
            );
        });
    }
}
