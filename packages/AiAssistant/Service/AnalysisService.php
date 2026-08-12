<?php
declare(strict_types=1);

namespace Mublo\Packages\AiAssistant\Service;

use Mublo\Packages\AiAssistant\Exception\ApiException;
use Mublo\Packages\AiAssistant\Repository\AnalysisRepository;
use Mublo\Packages\AiAssistant\Repository\CustomerDirectoryRepository;
use Mublo\Packages\AiAssistant\Repository\DeviceRepository;
use Mublo\Packages\AiAssistant\Repository\InteractionRepository;
use Mublo\Packages\AiAssistant\Support\CanonicalJson;
use Mublo\Packages\AiAssistant\Support\InputSetDigest;
use Mublo\Packages\AiAssistant\Support\Time;
use Mublo\Packages\AiAssistant\Support\Uuid;

final class AnalysisService
{
    private const CHANNELS = ['CALL_TRANSCRIPT', 'SMS', 'KAKAO', 'VOICE_MEMO'];
    private const TERMINAL = ['COMPLETED', 'INSUFFICIENT_DATA', 'FAILED_FINAL', 'CANCELLED'];

    public function __construct(
        private AnalysisRepository $repository,
        private InteractionRepository $interactions,
        private DeviceRepository $devices,
        private CustomerDirectoryRepository $directory
    ) {
    }

    /** @param array<string, mixed> $principal @param array<string, mixed> $input @return array<string, mixed> */
    public function registerConsent(array $principal, array $input): array
    {
        $required = ['schema_version', 'consent_receipt_id', 'device_id', 'consent_version', 'accepted_at', 'selected_customer_set_sha256', 'customer_ids'];
        $this->assertExactFields($input, $required, $required, 'ANALYSIS_CONSENT_INVALID');
        if ($input['schema_version'] !== 'analysis-consent-v1') {
            throw new ApiException('SCHEMA_VERSION_UNSUPPORTED', '지원하지 않는 동의 영수증 형식입니다.', 422);
        }
        $companyId = (string) $principal['company_id'];
        $receiptId = (string) $input['consent_receipt_id'];
        $deviceId = (string) $input['device_id'];
        if (!Uuid::isValid($receiptId) || !Uuid::isValid($deviceId)) {
            throw new ApiException('ANALYSIS_CONSENT_INVALID', '동의 영수증 식별자 형식이 올바르지 않습니다.', 422);
        }
        if ($this->devices->findActive($companyId, $deviceId) === null) {
            throw new ApiException('DEVICE_NOT_FOUND', '기기를 찾을 수 없습니다.', 404);
        }
        $customerIds = $this->customerIds($input['customer_ids']);
        foreach ($customerIds as $customerId) {
            $this->directory->requireManagedCustomer($companyId, $customerId);
        }
        $setHash = InputSetDigest::customers($customerIds);
        if (!hash_equals($setHash, (string) $input['selected_customer_set_sha256'])) {
            throw new ApiException('CUSTOMER_SET_MISMATCH', '선택 고객 집합이 동의 화면과 일치하지 않습니다.', 409);
        }
        $accepted = strtotime((string) $input['accepted_at']);
        $version = trim((string) $input['consent_version']);
        if ($accepted === false || $version === '' || strlen($version) > 64) {
            throw new ApiException('ANALYSIS_CONSENT_INVALID', '동의 시각 또는 버전이 올바르지 않습니다.', 422);
        }
        $existing = $this->repository->findConsent($companyId, $receiptId);
        if ($existing !== null) {
            if (!hash_equals((string) $existing['selected_customer_set_sha256'], $setHash)
                || !hash_equals((string) $existing['device_id'], $deviceId)
                || !hash_equals((string) $existing['consent_version'], $version)) {
                throw new ApiException('ANALYSIS_CONSENT_CONFLICT', '같은 동의 영수증 ID의 내용이 다릅니다.', 409);
            }
            return ['consent_receipt_id' => $receiptId, 'accepted' => true, 'replayed' => true];
        }
        $this->repository->createConsent([
            'consent_receipt_id' => $receiptId,
            'company_id' => $companyId,
            'user_id' => (string) $principal['user_id'],
            'device_id' => $deviceId,
            'consent_version' => $version,
            'accepted_at' => Time::database($accepted),
            'selected_customer_set_sha256' => $setHash,
            'customer_ids' => $customerIds,
        ]);
        return ['consent_receipt_id' => $receiptId, 'accepted' => true, 'replayed' => false];
    }

    /** @param array<string, mixed> $principal @param array<string, mixed> $input @return array<string, mixed> */
    public function createBatch(array $principal, array $input): array
    {
        $required = ['schema_version', 'purpose', 'device_id', 'consent_receipt_id', 'selected_customer_set_sha256', 'customers'];
        $this->assertExactFields($input, $required, $required, 'ANALYSIS_BATCH_INVALID');
        if ($input['schema_version'] !== 'analysis-batch-create-v1') {
            throw new ApiException('SCHEMA_VERSION_UNSUPPORTED', '지원하지 않는 분석 배치 형식입니다.', 422);
        }
        $purpose = (string) $input['purpose'];
        if (!in_array($purpose, ['INITIAL_ONBOARDING', 'INCREMENTAL', 'REBUILD'], true)) {
            throw new ApiException('ANALYSIS_BATCH_INVALID', '분석 목적이 올바르지 않습니다.', 422);
        }
        $companyId = (string) $principal['company_id'];
        $deviceId = (string) $input['device_id'];
        $consentId = (string) $input['consent_receipt_id'];
        if (!Uuid::isValid($deviceId) || !Uuid::isValid($consentId)
            || $this->devices->findActive($companyId, $deviceId) === null) {
            throw new ApiException('ANALYSIS_BATCH_INVALID', '기기 또는 동의 영수증 식별자가 올바르지 않습니다.', 422);
        }
        if (!is_array($input['customers']) || $input['customers'] === [] || count($input['customers']) > 100) {
            throw new ApiException('ANALYSIS_BATCH_INVALID', '분석할 고객은 1~100명이어야 합니다.', 422);
        }
        $customerIds = [];
        foreach ($input['customers'] as $customer) {
            if (!is_array($customer) || !Uuid::isValid((string) ($customer['customer_id'] ?? ''))) {
                throw new ApiException('ANALYSIS_BATCH_INVALID', '고객 요청 형식이 올바르지 않습니다.', 422);
            }
            $customerIds[] = (string) $customer['customer_id'];
        }
        if (count(array_unique($customerIds)) !== count($customerIds)) {
            throw new ApiException('ANALYSIS_BATCH_INVALID', '같은 고객을 한 배치에 중복할 수 없습니다.', 422);
        }
        $setHash = InputSetDigest::customers($customerIds);
        if (!hash_equals($setHash, (string) $input['selected_customer_set_sha256'])) {
            throw new ApiException('CUSTOMER_SET_MISMATCH', '선택 고객 집합 checksum이 일치하지 않습니다.', 409);
        }
        $consent = $this->repository->findConsent($companyId, $consentId);
        if ($consent === null || !hash_equals((string) $consent['device_id'], $deviceId)
            || !hash_equals((string) $consent['selected_customer_set_sha256'], $setHash)) {
            throw new ApiException('ANALYSIS_CONSENT_REQUIRED', '현재 고객 선택에 대한 유효한 분석 동의가 필요합니다.', 409);
        }

        $batchId = Uuid::v4();
        $runs = [];
        foreach ($input['customers'] as $customer) {
            /** @var array<string, mixed> $customer */
            $runs[] = $this->prepareRun($companyId, $customer);
        }
        $this->repository->createBatch([
            'batch_id' => $batchId,
            'company_id' => $companyId,
            'user_id' => (string) $principal['user_id'],
            'device_id' => $deviceId,
            'consent_receipt_id' => $consentId,
            'purpose' => $purpose,
            'selected_customer_set_sha256' => $setHash,
        ], $runs);
        return $this->getBatch($principal, $batchId);
    }

    /** @param array<string, mixed> $principal @return array<string, mixed> */
    public function getBatch(array $principal, string $batchId): array
    {
        if (!Uuid::isValid($batchId)) {
            throw new ApiException('ANALYSIS_BATCH_NOT_FOUND', '분석 배치를 찾을 수 없습니다.', 404);
        }
        $batch = $this->repository->findBatch((string) $principal['company_id'], $batchId);
        if ($batch === null) {
            throw new ApiException('ANALYSIS_BATCH_NOT_FOUND', '분석 배치를 찾을 수 없습니다.', 404);
        }
        $runs = array_map(fn(array $run): array => $this->serializeRun($run), $this->repository->listRuns((string) $principal['company_id'], $batchId));
        $terminal = count(array_filter($runs, static fn(array $run): bool => in_array($run['status'], self::TERMINAL, true)));
        $status = $terminal === count($runs) ? 'COMPLETED' : 'IN_PROGRESS';
        if (array_filter($runs, static fn(array $run): bool => $run['status'] === 'ACTION_REQUIRED') !== []) {
            $status = 'ACTION_REQUIRED';
        }
        return [
            'schema_version' => 'analysis-batch-status-v1',
            'batch_id' => (string) $batch['batch_id'],
            'purpose' => (string) $batch['purpose'],
            'status' => $status,
            'total_customers' => (int) $batch['total_customers'],
            'terminal_customers' => $terminal,
            'poll_after_seconds' => 3,
            'runs' => $runs,
            'created_at' => self::rfc3339((string) $batch['created_at']),
            'updated_at' => self::rfc3339((string) $batch['updated_at']),
        ];
    }

    /** @param array<string, mixed> $principal @return array<string, mixed> */
    public function getRun(array $principal, string $runId): array
    {
        if (!Uuid::isValid($runId)) {
            throw new ApiException('ANALYSIS_RUN_NOT_FOUND', '분석 실행을 찾을 수 없습니다.', 404);
        }
        $run = $this->repository->findRun((string) $principal['company_id'], $runId);
        if ($run === null) {
            throw new ApiException('ANALYSIS_RUN_NOT_FOUND', '분석 실행을 찾을 수 없습니다.', 404);
        }
        $result = $run['result_json'] === null ? null : json_decode((string) $run['result_json'], true, 512, JSON_THROW_ON_ERROR);
        return $this->serializeRun($run) + [
            'manifest_id' => (string) $run['manifest_id'],
            'manifest_sha256' => (string) $run['manifest_sha256'],
            'result' => $result,
        ];
    }

    /** @param array<string, mixed> $principal @return array<string, mixed> */
    public function retry(array $principal, string $runId): array
    {
        if (!in_array((string) ($principal['role'] ?? ''), ['OWNER', 'MANAGER'], true)) {
            throw new ApiException('ROLE_FORBIDDEN', '분석 재시도는 OWNER 또는 MANAGER만 할 수 있습니다.', 403);
        }
        $this->repository->retry((string) $principal['company_id'], $runId);
        return $this->getRun($principal, $runId);
    }

    /** @param array<string, mixed> $request @return array<string, mixed> */
    private function prepareRun(string $companyId, array $request): array
    {
        $required = ['customer_id', 'mode', 'collection_window', 'channels'];
        $this->assertExactFields($request, $required, array_merge($required, ['base_analysis_id', 'from_cursor']), 'ANALYSIS_BATCH_INVALID');
        $customerId = (string) $request['customer_id'];
        $this->directory->requireManagedCustomer($companyId, $customerId);
        $mode = (string) $request['mode'];
        if (!in_array($mode, ['INITIAL', 'INCREMENTAL', 'REBUILD'], true)) {
            throw new ApiException('ANALYSIS_BATCH_INVALID', '고객 분석 mode가 올바르지 않습니다.', 422);
        }
        $window = $request['collection_window'];
        if (!is_array($window) || array_diff(['from', 'to'], array_keys($window)) !== []
            || array_diff(array_keys($window), ['from', 'to']) !== []) {
            throw new ApiException('ANALYSIS_BATCH_INVALID', '수집 기간 형식이 올바르지 않습니다.', 422);
        }
        $fromTime = $window['from'] === null ? null : strtotime((string) $window['from']);
        $toTime = strtotime((string) $window['to']);
        if (($window['from'] !== null && $fromTime === false) || $toTime === false || ($fromTime !== null && $fromTime >= $toTime)) {
            throw new ApiException('ANALYSIS_WINDOW_INVALID', '수집 기간이 올바르지 않습니다.', 422);
        }
        $channels = $request['channels'];
        if (!is_array($channels) || array_diff(['CALL_TRANSCRIPT', 'SMS', 'KAKAO'], array_keys($channels)) !== []
            || array_diff(array_keys($channels), self::CHANNELS) !== []) {
            throw new ApiException('ANALYSIS_BATCH_INVALID', '필수 채널 수집 결과가 누락되었습니다.', 422);
        }
        $fromCursor = isset($request['from_cursor']) ? (int) $request['from_cursor'] : null;
        $allRows = [];
        $channelCounts = [];
        foreach ($channels as $channel => $reported) {
            if (!is_array($reported)) {
                throw new ApiException('ANALYSIS_BATCH_INVALID', '채널 수집 결과 형식이 올바르지 않습니다.', 422);
            }
            $this->assertExactFields($reported, ['outcome', 'count', 'set_sha256'], ['outcome', 'count', 'set_sha256'], 'ANALYSIS_BATCH_INVALID');
            $outcome = (string) $reported['outcome'];
            if (!is_int($reported['count']) || $reported['count'] < 0 || $reported['count'] > 20000
                || !preg_match('/^[a-f0-9]{64}$/', (string) $reported['set_sha256'])) {
                throw new ApiException('ANALYSIS_BATCH_INVALID', '채널 건수 또는 checksum 형식이 올바르지 않습니다.', 422);
            }
            if (in_array($outcome, ['PERMISSION_DENIED', 'UNSUPPORTED', 'FAILED'], true)) {
                throw new ApiException('ANALYSIS_INPUT_INCOMPLETE', $channel . ' 수집이 완료되지 않아 분석을 시작할 수 없습니다.', 409);
            }
            if (!in_array($outcome, ['COMPLETED', 'NO_DATA', 'NOT_LINKED'], true)) {
                throw new ApiException('ANALYSIS_BATCH_INVALID', '채널 수집 결과 값이 올바르지 않습니다.', 422);
            }
            $rows = $this->interactions->listManifestInputs(
                $companyId,
                $customerId,
                (string) $channel,
                $fromCursor,
                $fromTime === null ? null : Time::database($fromTime),
                Time::database($toTime)
            );
            $actualHash = InputSetDigest::interactions($rows);
            if ((int) $reported['count'] !== count($rows) || !hash_equals($actualHash, (string) $reported['set_sha256'])) {
                throw new ApiException('INPUT_SET_MISMATCH', $channel . ' 실제 저장 데이터와 앱의 완료 보고가 일치하지 않습니다.', 409, [
                    'channel' => $channel,
                    'expected_count' => count($rows),
                    'expected_set_sha256' => $actualHash,
                ]);
            }
            if ($outcome !== 'COMPLETED' && count($rows) !== 0) {
                throw new ApiException('INPUT_SET_MISMATCH', $channel . ' 결과가 없음으로 보고됐지만 저장 데이터가 존재합니다.', 409);
            }
            $channelCounts[$channel] = ['input' => count($rows), 'usable' => count($rows), 'skipped' => 0];
            array_push($allRows, ...$rows);
        }
        usort($allRows, static fn(array $a, array $b): int => (int) $a['server_sequence'] <=> (int) $b['server_sequence']);
        $inputCursor = $allRows === [] ? ($fromCursor ?? 0) : max(array_map(static fn(array $row): int => (int) $row['server_sequence'], $allRows));
        $runId = Uuid::v4();
        $manifest = [
            'schema_version' => 'analysis-manifest-v1',
            'manifest_id' => Uuid::v4(),
            'run_id' => $runId,
            'company_id' => $companyId,
            'customer_id' => $customerId,
            'mode' => $mode,
            'base_analysis_id' => $request['base_analysis_id'] ?? null,
            'from_cursor' => $fromCursor,
            'input_cursor' => $inputCursor,
            'channel_counts' => $channelCounts,
            'interactions' => array_map(static fn(array $row): array => [
                'interaction_id' => (string) $row['interaction_id'],
                'customer_phone_id' => (string) $row['customer_phone_id'],
                'device_id' => (string) $row['device_id'],
                'channel' => (string) $row['channel'],
                'source_record_id' => (string) $row['source_record_id'],
                'occurred_at' => self::rfc3339((string) $row['occurred_at']),
                'content_sha256' => (string) $row['content_sha256'],
                'server_sequence' => (int) $row['server_sequence'],
                'envelope' => json_decode((string) $row['envelope_json'], true, 512, JSON_THROW_ON_ERROR),
            ], $allRows),
            'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
        $manifest['manifest_sha256'] = hash('sha256', CanonicalJson::encode($manifest));
        return [
            'run_id' => $runId,
            'job_id' => Uuid::v4(),
            'customer_id' => $customerId,
            'mode' => $mode,
            'base_analysis_id' => $request['base_analysis_id'] ?? null,
            'from_cursor' => $fromCursor,
            'input_cursor' => $inputCursor,
            'manifest' => $manifest,
        ];
    }

    /** @param mixed $value @return list<string> */
    private function customerIds(mixed $value): array
    {
        if (!is_array($value) || $value === [] || count($value) > 100) {
            throw new ApiException('ANALYSIS_CONSENT_INVALID', '동의 고객은 1~100명이어야 합니다.', 422);
        }
        $ids = [];
        foreach ($value as $id) {
            if (!is_string($id) || !Uuid::isValid($id)) {
                throw new ApiException('ANALYSIS_CONSENT_INVALID', '동의 고객 ID 형식이 올바르지 않습니다.', 422);
            }
            $ids[] = $id;
        }
        if (count(array_unique($ids)) !== count($ids)) {
            throw new ApiException('ANALYSIS_CONSENT_INVALID', '동의 고객을 중복할 수 없습니다.', 422);
        }
        return $ids;
    }

    /** @param array<string, mixed> $value @param list<string> $required @param list<string> $allowed */
    private function assertExactFields(array $value, array $required, array $allowed, string $code): void
    {
        if (array_diff($required, array_keys($value)) !== [] || array_diff(array_keys($value), $allowed) !== []) {
            throw new ApiException($code, '요청 필드 구성이 올바르지 않습니다.', 422);
        }
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function serializeRun(array $row): array
    {
        return [
            'run_id' => (string) $row['run_id'],
            'customer_id' => (string) $row['customer_id'],
            'status' => (string) $row['status'],
            'stage' => (string) $row['stage'],
            'progress' => [
                'processed' => $row['progress_processed'] === null ? null : (int) $row['progress_processed'],
                'total' => $row['progress_total'] === null ? null : (int) $row['progress_total'],
                'sequence' => (int) $row['progress_sequence'],
            ],
            'retryable' => (bool) $row['retryable'],
            'reason_code' => $row['reason_code'] === null ? null : (string) $row['reason_code'],
            'analysis_id' => $row['analysis_id'] === null ? null : (string) $row['analysis_id'],
            'updated_at' => self::rfc3339((string) $row['updated_at']),
        ];
    }

    private static function rfc3339(string $databaseTime): string
    {
        $timestamp = strtotime($databaseTime . ' UTC');
        return $timestamp === false ? $databaseTime : gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }
}
