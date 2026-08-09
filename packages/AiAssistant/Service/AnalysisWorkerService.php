<?php
declare(strict_types=1);

namespace Mublo\Packages\AiAssistant\Service;

use Mublo\Packages\AiAssistant\Exception\ApiException;
use Mublo\Packages\AiAssistant\Repository\AnalysisWorkerRepository;
use Mublo\Packages\AiAssistant\Security\WorkerSecurity;
use Mublo\Packages\AiAssistant\Security\WorkerResultVerifierInterface;
use Mublo\Packages\AiAssistant\Support\Time;
use Mublo\Packages\AiAssistant\Support\Uuid;

final class AnalysisWorkerService
{
    public function __construct(
        private AnalysisWorkerRepository $repository,
        private WorkerSecurity $authentication,
        private WorkerResultVerifierInterface $signatures
    ) {
    }

    public function authenticate(?string $token): void
    {
        $this->authentication->authenticate($token);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function heartbeat(array $input): array
    {
        $required = ['schema_version', 'worker_id', 'service_version', 'build_digest', 'status', 'capabilities', 'max_concurrency', 'active_jobs', 'progress_sequence'];
        foreach ($required as $field) {
            if (!array_key_exists($field, $input)) {
                throw new ApiException('WORKER_HEARTBEAT_INVALID', 'Worker heartbeat 필수 항목이 누락되었습니다.', 422);
            }
        }
        if ($input['schema_version'] !== 'worker-heartbeat-v1'
            || !in_array($input['status'], ['STARTING', 'READY', 'BUSY', 'DRAINING', 'DEGRADED'], true)
            || !is_array($input['capabilities'])
            || !in_array('CUSTOMER_PROFILE_ANALYSIS_V2', $input['capabilities'], true)
            || !preg_match('/^[a-f0-9]{64}$/', (string) $input['build_digest'])) {
            throw new ApiException('WORKER_HEARTBEAT_INVALID', 'Worker heartbeat 형식이 올바르지 않습니다.', 422);
        }
        $workerId = trim((string) $input['worker_id']);
        if (strlen($workerId) < 3 || strlen($workerId) > 128) {
            throw new ApiException('WORKER_HEARTBEAT_INVALID', 'Worker ID가 올바르지 않습니다.', 422);
        }
        $this->repository->heartbeat($input);
        return ['worker_id' => $workerId, 'accepted' => true, 'server_time' => gmdate('Y-m-d\TH:i:s\Z')];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function lease(array $input): array
    {
        $workerId = trim((string) ($input['worker_id'] ?? ''));
        $capabilities = $input['capabilities'] ?? [];
        if (strlen($workerId) < 3 || strlen($workerId) > 128 || !is_array($capabilities)
            || !in_array('CUSTOMER_PROFILE_ANALYSIS_V2', $capabilities, true)) {
            throw new ApiException('WORKER_CAPABILITY_INVALID', 'Worker ID 또는 기능이 올바르지 않습니다.', 422);
        }
        $row = $this->repository->lease($workerId, 120);
        if ($row === null) {
            return ['job' => null, 'retry_after_seconds' => 10];
        }
        $manifest = json_decode((string) $row['manifest_json'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($manifest)) {
            throw new \RuntimeException('Stored analysis manifest must be an object');
        }
        return ['job' => [
            'schema_version' => 'worker-job-v2',
            'job_id' => (string) $row['job_id'],
            'run_id' => (string) $row['run_id'],
            'manifest' => $manifest,
            'lease_token' => (string) $row['lease_token'],
            'lease_expires_at' => Time::api((string) $row['lease_expires_at']),
            'attempt' => (int) $row['attempts'],
        ]];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function renew(string $jobId, array $input): array
    {
        $this->assertJobId($jobId);
        $job = $this->repository->requireLease($jobId, (string) ($input['lease_token'] ?? ''));
        return $this->repository->renew($job, trim((string) ($input['worker_id'] ?? '')), 120);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function complete(string $jobId, array $input): array
    {
        $this->assertJobId($jobId);
        if (($input['schema_version'] ?? null) !== 'worker-complete-v2'
            || !is_array($input['result'] ?? null) || !is_array($input['cleanup_attestation'] ?? null)
            || ($input['cleanup_attestation']['plaintext_files_remaining'] ?? null) !== 0
            || strtotime((string) ($input['cleanup_attestation']['completed_at'] ?? '')) === false) {
            throw new ApiException('WORKER_COMPLETE_INVALID', 'Worker 완료 요청 또는 정리 확인이 올바르지 않습니다.', 422);
        }
        $job = $this->repository->requireLease($jobId, (string) ($input['lease_token'] ?? ''));
        /** @var array<string, mixed> $result */
        $result = $input['result'];
        $required = ['schema_version', 'analysis_id', 'run_id', 'job_id', 'company_id', 'customer_id',
            'manifest_id', 'manifest_sha256', 'input_cursor', 'mode', 'terminal_status', 'channel_counts',
            'facts', 'inferences', 'next_actions', 'quality', 'model_provider', 'model', 'model_version',
            'prompt_version', 'created_at', 'worker_id', 'signing_key_id', 'signature_algorithm', 'worker_signature'];
        foreach ($required as $field) {
            if (!array_key_exists($field, $result)) {
                throw new ApiException('ANALYSIS_RESULT_INVALID', '분석 결과 필수 항목이 누락되었습니다.', 422);
            }
        }
        if ($result['schema_version'] !== 'analysis-result-v2'
            || $result['run_id'] !== $job['run_id'] || $result['job_id'] !== $job['job_id']
            || $result['company_id'] !== $job['company_id'] || $result['customer_id'] !== $job['customer_id']
            || $result['manifest_id'] !== $job['manifest_id']
            || $result['manifest_sha256'] !== $job['manifest_sha256']
            || (int) $result['input_cursor'] !== (int) $job['input_cursor']
            || $result['worker_id'] !== $job['lease_owner']
            || !Uuid::isValid((string) $result['analysis_id'])
            || !in_array($result['terminal_status'], ['COMPLETED', 'INSUFFICIENT_DATA'], true)
            || strtotime((string) $result['created_at']) === false) {
            throw new ApiException('ANALYSIS_SCOPE_MISMATCH', '분석 결과의 manifest 범위가 일치하지 않습니다.', 422);
        }
        if (!is_array($result['channel_counts']) || !is_array($result['facts'])
            || !is_array($result['inferences']) || !is_array($result['next_actions'])
            || !is_array($result['quality'])) {
            throw new ApiException('ANALYSIS_RESULT_INVALID', '분석 결과 목록 또는 품질 형식이 올바르지 않습니다.', 422);
        }
        if ($result['terminal_status'] === 'INSUFFICIENT_DATA'
            && !in_array($result['insufficient_reason'] ?? null, ['NO_INTERACTIONS', 'NO_USABLE_TEXT', 'QUALITY_TOO_LOW', 'INSUFFICIENT_CONTEXT'], true)) {
            throw new ApiException('ANALYSIS_RESULT_INVALID', '데이터 부족 결과에는 안정적인 부족 사유가 필요합니다.', 422);
        }
        $manifest = json_decode((string) $job['manifest_json'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($manifest)) {
            throw new \RuntimeException('Stored analysis manifest must be an object');
        }
        $evidenceIds = [];
        foreach ($manifest['interactions'] ?? [] as $interaction) {
            if (is_array($interaction)) {
                $evidenceIds[(string) ($interaction['interaction_id'] ?? '')] = true;
            }
        }
        foreach (['facts', 'inferences'] as $field) {
            foreach ($result[$field] as $item) {
                if (!is_array($item) || trim((string) ($item['text'] ?? '')) === ''
                    || !is_array($item['evidence_ids'] ?? null) || $item['evidence_ids'] === []) {
                    throw new ApiException('ANALYSIS_EVIDENCE_INVALID', '사실과 추론에는 근거 interaction이 필요합니다.', 422);
                }
                foreach ($item['evidence_ids'] as $evidenceId) {
                    if (!isset($evidenceIds[(string) $evidenceId])) {
                        throw new ApiException('ANALYSIS_EVIDENCE_INVALID', 'manifest에 없는 interaction을 근거로 사용할 수 없습니다.', 422);
                    }
                }
            }
        }
        foreach ($result['channel_counts'] as $counts) {
            if (!is_array($counts) || !isset($counts['input'], $counts['usable'], $counts['skipped'])
                || !is_int($counts['input']) || !is_int($counts['usable']) || !is_int($counts['skipped'])
                || $counts['input'] < 0 || $counts['usable'] < 0 || $counts['skipped'] < 0
                || $counts['usable'] + $counts['skipped'] !== $counts['input']) {
                throw new ApiException('ANALYSIS_COUNT_INVALID', '분석 결과의 채널 건수가 일관되지 않습니다.', 422);
            }
        }
        $this->signatures->verifyResultSignature($result);
        $this->repository->complete($job, $result);
        return ['job_id' => $jobId, 'run_id' => $job['run_id'], 'status' => $result['terminal_status'], 'analysis_id' => $result['analysis_id']];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function fail(string $jobId, array $input): array
    {
        $this->assertJobId($jobId);
        $job = $this->repository->requireLease($jobId, (string) ($input['lease_token'] ?? ''));
        $errorCode = strtoupper(trim((string) ($input['error_code'] ?? 'WORKER_FAILED')));
        if (!preg_match('/^[A-Z0-9_]{3,64}$/', $errorCode)) {
            throw new ApiException('WORKER_ERROR_INVALID', 'Worker 오류 코드가 올바르지 않습니다.', 422);
        }
        $status = $this->repository->fail($job, $errorCode, (bool) ($input['retryable'] ?? true));
        return ['job_id' => $jobId, 'run_id' => $job['run_id'], 'status' => $status];
    }

    public function hasJob(string $jobId): bool
    {
        return Uuid::isValid($jobId) && $this->repository->hasJob($jobId);
    }

    private function assertJobId(string $jobId): void
    {
        if (!Uuid::isValid($jobId)) {
            throw new ApiException('WORKER_JOB_ID_INVALID', '작업 ID 형식이 올바르지 않습니다.', 422);
        }
    }
}
