<?php
declare(strict_types=1);

namespace Mublo\Packages\AiAssistant\Service;

use Mublo\Packages\AiAssistant\Exception\ApiException;
use Mublo\Packages\AiAssistant\Repository\InteractionRepository;
use Mublo\Packages\AiAssistant\Security\WorkerSecurity;
use Mublo\Packages\AiAssistant\Support\Time;
use Mublo\Packages\AiAssistant\Support\Uuid;

final class WorkerJobService
{
    public function __construct(
        private InteractionRepository $repository,
        private WorkerSecurity $security
    ) {
    }

    public function authenticate(?string $token): void
    {
        $this->security->authenticate($token);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function lease(array $input): array
    {
        $workerId = trim((string) ($input['worker_id'] ?? ''));
        $capabilities = $input['capabilities'] ?? [];
        if (strlen($workerId) < 3 || strlen($workerId) > 128 || !is_array($capabilities)
            || !in_array('TEXT_ANALYSIS', $capabilities, true)) {
            throw new ApiException('WORKER_CAPABILITY_INVALID', 'Worker ID 또는 기능이 올바르지 않습니다.', 422);
        }
        $row = $this->repository->lease($workerId, 120);
        if ($row === null) {
            return ['job' => null, 'retry_after_seconds' => 10];
        }
        $envelope = json_decode((string) $row['envelope_json'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($envelope)) {
            throw new \RuntimeException('Stored crypto envelope must be an object');
        }
        return [
            'job' => [
                'job_id' => (string) $row['job_id'],
                'interaction_id' => (string) $row['interaction_id'],
                'company_id' => (string) $row['company_id'],
                'customer_id' => (string) $row['customer_id'],
                'customer_phone_id' => (string) $row['customer_phone_id'],
                'device_id' => (string) $row['device_id'],
                'channel' => (string) $row['channel'],
                'occurred_at' => Time::api((string) $row['occurred_at']),
                'input_sha256' => (string) $row['envelope_sha256'],
                'envelope' => $envelope,
                'lease_token' => (string) $row['lease_token'],
                'lease_expires_at' => Time::api((string) $row['lease_expires_at']),
            ],
        ];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function complete(string $jobId, array $input): array
    {
        if (!Uuid::isValid($jobId)) {
            throw new ApiException('WORKER_JOB_ID_INVALID', '작업 ID 형식이 올바르지 않습니다.', 422);
        }
        $leaseToken = (string) ($input['lease_token'] ?? '');
        $result = $input['result'] ?? null;
        if (!is_array($result)) {
            throw new ApiException('ANALYSIS_RESULT_INVALID', '분석 결과가 필요합니다.', 422);
        }
        $job = $this->repository->requireLease($jobId, $leaseToken);
        $this->validateResult($job, $result);
        $this->security->verifyResultSignature($result);
        $this->repository->complete($job, $result);
        return ['job_id' => $jobId, 'status' => 'COMPLETED', 'analysis_id' => $result['analysis_id']];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function fail(string $jobId, array $input): array
    {
        if (!Uuid::isValid($jobId)) {
            throw new ApiException('WORKER_JOB_ID_INVALID', '작업 ID 형식이 올바르지 않습니다.', 422);
        }
        $job = $this->repository->requireLease($jobId, (string) ($input['lease_token'] ?? ''));
        $errorCode = strtoupper(trim((string) ($input['error_code'] ?? 'WORKER_FAILED')));
        if (!preg_match('/^[A-Z0-9_]{3,64}$/', $errorCode)) {
            throw new ApiException('WORKER_ERROR_INVALID', 'Worker 오류 코드가 올바르지 않습니다.', 422);
        }
        $retryable = (bool) ($input['retryable'] ?? true);
        $this->repository->fail($job, $errorCode, $retryable);
        return ['job_id' => $jobId, 'status' => $retryable && (int) $job['attempts'] < 5 ? 'QUEUED' : 'DEAD_LETTER'];
    }

    /** @param array<string, mixed> $job @param array<string, mixed> $result */
    private function validateResult(array $job, array $result): void
    {
        $required = ['schema_version', 'analysis_id', 'company_id', 'customer_id', 'input_cursor',
            'overall_summary', 'recent_summary', 'facts', 'inferences', 'next_actions', 'model',
            'prompt_version', 'created_at', 'worker_signature'];
        foreach ($required as $field) {
            if (!array_key_exists($field, $result)) {
                throw new ApiException('ANALYSIS_RESULT_INVALID', '분석 결과 필수 항목이 누락되었습니다.', 422);
            }
        }
        if ($result['schema_version'] !== 'analysis-result-v1'
            || $result['company_id'] !== $job['company_id']
            || $result['customer_id'] !== $job['customer_id']
            || $result['input_cursor'] !== $job['interaction_id']
            || !Uuid::isValid((string) $result['analysis_id'])) {
            throw new ApiException('ANALYSIS_SCOPE_MISMATCH', '분석 결과의 작업 범위가 일치하지 않습니다.', 422);
        }
        foreach (['facts', 'inferences', 'next_actions'] as $field) {
            if (!is_array($result[$field])) {
                throw new ApiException('ANALYSIS_RESULT_INVALID', '분석 결과 목록 형식이 올바르지 않습니다.', 422);
            }
        }
        if (mb_strlen((string) $result['overall_summary']) > 4000
            || mb_strlen((string) $result['recent_summary']) > 4000
            || strtotime((string) $result['created_at']) === false) {
            throw new ApiException('ANALYSIS_RESULT_INVALID', '분석 결과 길이 또는 시각이 올바르지 않습니다.', 422);
        }
    }
}
