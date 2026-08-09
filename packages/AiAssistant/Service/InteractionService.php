<?php
declare(strict_types=1);

namespace Mublo\Packages\AiAssistant\Service;

use Mublo\Packages\AiAssistant\Exception\ApiException;
use Mublo\Packages\AiAssistant\Repository\DeviceRepository;
use Mublo\Packages\AiAssistant\Repository\CustomerDirectoryRepository;
use Mublo\Packages\AiAssistant\Repository\InteractionRepository;
use Mublo\Packages\AiAssistant\Security\WorkerSecurity;
use Mublo\Packages\AiAssistant\Support\CanonicalJson;
use Mublo\Packages\AiAssistant\Support\Time;
use Mublo\Packages\AiAssistant\Support\Uuid;

final class InteractionService
{
    public function __construct(
        private InteractionRepository $repository,
        private DeviceRepository $devices,
        private CustomerDirectoryRepository $directory,
        private WorkerSecurity $workerSecurity
    ) {
    }

    /** @return array<string, mixed> */
    public function workerKey(): array
    {
        return $this->workerSecurity->publicKey();
    }

    /** @param array<string, mixed> $principal @param array<string, mixed> $input @return array<string, mixed> */
    public function upload(array $principal, array $input): array
    {
        $required = ['schema_version', 'interaction_id', 'customer_id', 'customer_phone_id', 'device_id', 'channel', 'occurred_at', 'envelope'];
        foreach ($required as $field) {
            if (!array_key_exists($field, $input)) {
                throw new ApiException('INTERACTION_INVALID', 'interaction 필수 항목이 누락되었습니다.', 422);
            }
        }
        $allowed = array_merge($required, ['duration_sec', 'direction']);
        if (array_diff(array_keys($input), $allowed) !== []) {
            throw new ApiException('INTERACTION_INVALID', '허용되지 않은 interaction 항목이 있습니다.', 422);
        }
        if ($input['schema_version'] !== 'interaction-upload-v2' || $input['channel'] !== 'CALL_TRANSCRIPT') {
            throw new ApiException('INTERACTION_TYPE_UNSUPPORTED', '지원하지 않는 interaction 형식입니다.', 422);
        }
        $interactionId = (string) $input['interaction_id'];
        $customerId = (string) $input['customer_id'];
        $customerPhoneId = (string) $input['customer_phone_id'];
        $deviceId = (string) $input['device_id'];
        if (!Uuid::isValid($interactionId) || !Uuid::isValid($customerId)
            || !Uuid::isValid($customerPhoneId) || !Uuid::isValid($deviceId)) {
            throw new ApiException('INTERACTION_ID_INVALID', 'interaction 식별자 형식이 올바르지 않습니다.', 422);
        }
        $companyId = (string) $principal['company_id'];
        if ($this->devices->findActive($companyId, $deviceId) === null) {
            throw new ApiException('DEVICE_NOT_FOUND', '기기를 찾을 수 없습니다.', 404);
        }
        $this->directory->requireManagedPhone($companyId, $customerId, $customerPhoneId);
        $occurredTimestamp = strtotime((string) $input['occurred_at']);
        if ($occurredTimestamp === false) {
            throw new ApiException('INTERACTION_TIME_INVALID', '통화 시각이 올바르지 않습니다.', 422);
        }
        $envelope = $input['envelope'];
        if (!is_array($envelope)) {
            throw new ApiException('CRYPTO_ENVELOPE_INVALID', '암호화 봉투가 필요합니다.', 422);
        }
        $this->validateEnvelope($envelope, [
            'company_id' => $companyId,
            'customer_id' => $customerId,
            'customer_phone_id' => $customerPhoneId,
            'interaction_id' => $interactionId,
            'device_id' => $deviceId,
            'channel' => 'CALL_TRANSCRIPT',
            'occurred_at' => gmdate('Y-m-d\TH:i:s\Z', $occurredTimestamp),
        ]);
        $envelopeJson = CanonicalJson::encode($envelope);
        $envelopeHash = hash('sha256', $envelopeJson);
        $existing = $this->repository->find($companyId, $interactionId);
        if ($existing !== null) {
            if (!hash_equals((string) $existing['envelope_sha256'], $envelopeHash)) {
                throw new ApiException('INTERACTION_CONFLICT', '같은 interaction ID의 내용이 다릅니다.', 409);
            }
            return $this->serialize($existing, true);
        }
        $created = $this->repository->create([
            'interaction_id' => $interactionId,
            'company_id' => $companyId,
            'customer_id' => $customerId,
            'customer_phone_id' => $customerPhoneId,
            'device_id' => $deviceId,
            'channel' => 'CALL_TRANSCRIPT',
            'occurred_at' => Time::database($occurredTimestamp),
            'envelope_json' => $envelopeJson,
            'envelope_sha256' => $envelopeHash,
        ]);
        return [
            'interaction_id' => $interactionId,
            'job_id' => $created['job_id'],
            'status' => 'QUEUED',
            'replayed' => false,
        ];
    }

    /** @param array<string, mixed> $principal @return array<string, mixed> */
    public function latestAnalysis(array $principal, string $customerId): array
    {
        if (!Uuid::isValid($customerId)) {
            throw new ApiException('CUSTOMER_ID_INVALID', '고객 ID 형식이 올바르지 않습니다.', 422);
        }
        $result = $this->repository->latestAnalysis((string) $principal['company_id'], $customerId);
        if ($result === null) {
            throw new ApiException('ANALYSIS_NOT_FOUND', '분석 결과가 아직 없습니다.', 404);
        }
        return $result;
    }

    /** @param array<string, mixed> $envelope @param array<string, string> $expectedAad */
    private function validateEnvelope(array $envelope, array $expectedAad): void
    {
        $fields = ['schema_version', 'algorithm', 'key_wrap_algorithm', 'key_id', 'wrapped_dek', 'iv', 'ciphertext', 'tag', 'aad', 'plaintext_sha256'];
        if (array_diff($fields, array_keys($envelope)) !== [] || array_diff(array_keys($envelope), $fields) !== []) {
            throw new ApiException('CRYPTO_ENVELOPE_INVALID', '암호화 봉투 형식이 올바르지 않습니다.', 422);
        }
        if ($envelope['schema_version'] !== 'crypto-envelope-v1' || $envelope['algorithm'] !== 'AES-256-GCM'
            || $envelope['key_wrap_algorithm'] !== 'RSA-OAEP-256') {
            throw new ApiException('CRYPTO_ENVELOPE_UNSUPPORTED', '지원하지 않는 암호화 방식입니다.', 422);
        }
        $this->workerSecurity->assertKeyId((string) $envelope['key_id']);
        foreach (['wrapped_dek', 'iv', 'ciphertext', 'tag', 'aad'] as $field) {
            $decoded = base64_decode((string) $envelope[$field], true);
            if ($decoded === false || ($field !== 'ciphertext' && $decoded === '')) {
                throw new ApiException('CRYPTO_ENVELOPE_INVALID', '암호화 봉투 인코딩이 올바르지 않습니다.', 422);
            }
        }
        $wrappedDek = base64_decode((string) $envelope['wrapped_dek'], true);
        $iv = base64_decode((string) $envelope['iv'], true);
        $ciphertext = base64_decode((string) $envelope['ciphertext'], true);
        $tag = base64_decode((string) $envelope['tag'], true);
        if (!is_string($wrappedDek) || strlen($wrappedDek) < 256 || strlen($wrappedDek) > 1024
            || !is_string($iv) || strlen($iv) !== 12
            || !is_string($tag) || strlen($tag) !== 16
            || !is_string($ciphertext) || $ciphertext === ''
            || strlen((string) $envelope['ciphertext']) > 6_000_000
            || !preg_match('/^[a-f0-9]{64}$/', (string) $envelope['plaintext_sha256'])) {
            throw new ApiException('CRYPTO_ENVELOPE_INVALID', '암호화 봉투 크기 또는 checksum이 올바르지 않습니다.', 422);
        }
        $aadJson = base64_decode((string) $envelope['aad'], true);
        $aad = $aadJson === false ? null : json_decode($aadJson, true);
        if (!is_array($aad)) {
            throw new ApiException('CRYPTO_AAD_INVALID', '암호화 AAD가 올바르지 않습니다.', 422);
        }
        foreach ($expectedAad as $key => $value) {
            if (($aad[$key] ?? null) !== $value) {
                throw new ApiException('CRYPTO_AAD_MISMATCH', '암호화 AAD의 요청 범위가 일치하지 않습니다.', 422);
            }
        }
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function serialize(array $row, bool $replayed): array
    {
        return [
            'interaction_id' => (string) $row['interaction_id'],
            'status' => (string) $row['status'],
            'replayed' => $replayed,
        ];
    }
}
