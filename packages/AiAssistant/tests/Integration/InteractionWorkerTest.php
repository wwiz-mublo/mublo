<?php
declare(strict_types=1);

namespace Tests\AiAssistant\Integration;

use Mublo\Packages\AiAssistant\Exception\ApiException;
use Mublo\Packages\AiAssistant\Support\CanonicalJson;
use Mublo\Packages\AiAssistant\Support\Uuid;
use Tests\AiAssistant\DatabaseTestCase;

final class InteractionWorkerTest extends DatabaseTestCase
{
    public function testAnalysisUploadRejectsPhoneThatIsNotInServerDirectory(): void
    {
        $principal = $this->principal('unregistered-analysis-company', 403, 'unregistered-secret');
        $device = $this->enroll($principal, 'installation-unregistered-analysis-01');
        $customerId = Uuid::v4();
        $this->sync->push(
            $principal,
            (string) $device['device_id'],
            [$this->customerRecord($principal, $customerId)]
        );
        $input = $this->interaction($principal, $device, $customerId, Uuid::v4(), Uuid::v4());

        try {
            $this->interactions->upload($principal, $input);
            self::fail('Analysis must not accept an unregistered phone');
        } catch (ApiException $exception) {
            self::assertSame('CUSTOMER_PHONE_NOT_REGISTERED', $exception->errorCode);
            self::assertSame(422, $exception->statusCode);
        }
        self::assertSame(0, (int) $this->db->selectOne('SELECT COUNT(*) AS count_value FROM ai_interactions')['count_value']);
    }

    public function testEncryptedTranscriptMovesThroughWorkerWithoutPlaintextAtRest(): void
    {
        $principal = $this->principal('interaction-company', 401, 'interaction-secret');
        $device = $this->enroll($principal, 'installation-interaction-01');
        $customerId = Uuid::v4();
        $phoneId = Uuid::v4();
        $this->sync->push($principal, (string) $device['device_id'], [
            $this->customerRecord($principal, $customerId),
            $this->phoneRecord($principal, $customerId, $phoneId),
        ]);
        $interactionId = Uuid::v4();
        $input = $this->interaction($principal, $device, $customerId, $phoneId, $interactionId);

        $created = $this->interactions->upload($principal, $input);
        self::assertSame('QUEUED', $created['status']);
        self::assertFalse($created['replayed']);
        $stored = $this->db->selectOne('SELECT envelope_json FROM ai_interactions WHERE interaction_id = ?', [$interactionId]);
        self::assertNotNull($stored);
        self::assertStringNotContainsString('고객과 다음 주 미팅', (string) $stored['envelope_json']);

        $leased = $this->workerJobs->lease(['worker_id' => 'worker-test-01', 'capabilities' => ['TEXT_ANALYSIS']]);
        $job = $leased['job'];
        self::assertIsArray($job);
        self::assertSame($interactionId, $job['interaction_id']);
        self::assertSame($phoneId, $job['customer_phone_id']);

        $result = $this->analysisResult($principal, $customerId, $interactionId);
        $completed = $this->workerJobs->complete((string) $job['job_id'], [
            'lease_token' => $job['lease_token'],
            'result' => $result,
        ]);
        self::assertSame('COMPLETED', $completed['status']);
        self::assertSame('다음 주 미팅을 준비합니다.', $this->interactions->latestAnalysis($principal, $customerId)['recent_summary']);
    }

    public function testAadTenantMismatchAndInvalidWorkerSignatureAreRejected(): void
    {
        $principal = $this->principal('secure-company', 402, 'secure-secret');
        $device = $this->enroll($principal, 'installation-secure-01');
        $customerId = Uuid::v4();
        $phoneId = Uuid::v4();
        $this->sync->push($principal, (string) $device['device_id'], [
            $this->customerRecord($principal, $customerId),
            $this->phoneRecord($principal, $customerId, $phoneId),
        ]);
        $interactionId = Uuid::v4();
        $input = $this->interaction($principal, $device, $customerId, $phoneId, $interactionId);
        $aad = json_decode((string) base64_decode((string) $input['envelope']['aad'], true), true, 512, JSON_THROW_ON_ERROR);
        $aad['company_id'] = Uuid::v4();
        $input['envelope']['aad'] = base64_encode(CanonicalJson::encode($aad));
        try {
            $this->interactions->upload($principal, $input);
            self::fail('Mismatched AAD must fail');
        } catch (ApiException $exception) {
            self::assertSame('CRYPTO_AAD_MISMATCH', $exception->errorCode);
        }

        $validInput = $this->interaction($principal, $device, $customerId, $phoneId, Uuid::v4());
        $this->interactions->upload($principal, $validInput);
        $job = $this->workerJobs->lease(['worker_id' => 'worker-test-02', 'capabilities' => ['TEXT_ANALYSIS']])['job'];
        self::assertIsArray($job);
        $result = $this->analysisResult($principal, $customerId, (string) $job['interaction_id']);
        $result['worker_signature'] = 'hmac-sha256:' . str_repeat('0', 64);
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Worker 결과 서명이 올바르지 않습니다.');
        $this->workerJobs->complete((string) $job['job_id'], ['lease_token' => $job['lease_token'], 'result' => $result]);
    }

    /** @param array<string, mixed> $principal @param array<string, mixed> $device @return array<string, mixed> */
    private function interaction(
        array $principal,
        array $device,
        string $customerId,
        string $phoneId,
        string $interactionId
    ): array
    {
        $aad = [
            'channel' => 'CALL_TRANSCRIPT',
            'company_id' => $principal['company_id'],
            'customer_id' => $customerId,
            'customer_phone_id' => $phoneId,
            'device_id' => $device['device_id'],
            'interaction_id' => $interactionId,
            'occurred_at' => '2026-08-08T01:00:00Z',
        ];
        return [
            'schema_version' => 'interaction-upload-v2',
            'interaction_id' => $interactionId,
            'customer_id' => $customerId,
            'customer_phone_id' => $phoneId,
            'device_id' => $device['device_id'],
            'channel' => 'CALL_TRANSCRIPT',
            'occurred_at' => '2026-08-08T01:00:00Z',
            'duration_sec' => 90,
            'direction' => 'OUTGOING',
            'envelope' => [
                'schema_version' => 'crypto-envelope-v1',
                'algorithm' => 'AES-256-GCM',
                'key_wrap_algorithm' => 'RSA-OAEP-256',
                'key_id' => 'worker-test-key-v1',
                'wrapped_dek' => base64_encode(str_repeat('w', 256)),
                'iv' => base64_encode(str_repeat('i', 12)),
                'ciphertext' => base64_encode('encrypted customer transcript'),
                'tag' => base64_encode(str_repeat('t', 16)),
                'aad' => base64_encode(CanonicalJson::encode($aad)),
                'plaintext_sha256' => hash('sha256', '고객과 다음 주 미팅'),
            ],
        ];
    }

    /** @param array<string, mixed> $principal @return array<string, mixed> */
    private function customerRecord(array $principal, string $customerId): array
    {
        return [
            'schema_version' => 'sync-record-v1', 'company_id' => $principal['company_id'],
            'object_type' => 'customer', 'object_id' => $customerId, 'operation' => 'UPSERT',
            'version' => 1, 'updated_at' => '2026-08-08T00:00:00Z', 'deleted_at' => null,
            'payload' => ['display_name' => '암호화 고객', 'management_status' => 'MANAGED'],
        ];
    }

    /** @param array<string, mixed> $principal @return array<string, mixed> */
    private function phoneRecord(array $principal, string $customerId, string $phoneId): array
    {
        return [
            'schema_version' => 'sync-record-v1', 'company_id' => $principal['company_id'],
            'object_type' => 'customer_phone', 'object_id' => $phoneId, 'operation' => 'UPSERT',
            'version' => 1, 'updated_at' => '2026-08-08T00:00:01Z', 'deleted_at' => null,
            'payload' => [
                'customer_id' => $customerId, 'normalized_phone' => '+821012345678',
                'management_status' => 'MANAGED', 'is_primary' => true,
            ],
        ];
    }

    /** @param array<string, mixed> $principal @return array<string, mixed> */
    private function analysisResult(array $principal, string $customerId, string $interactionId): array
    {
        $result = [
            'schema_version' => 'analysis-result-v1', 'analysis_id' => Uuid::v4(),
            'company_id' => $principal['company_id'], 'customer_id' => $customerId,
            'input_cursor' => $interactionId, 'overall_summary' => '미팅을 준비 중입니다.',
            'recent_summary' => '다음 주 미팅을 준비합니다.', 'facts' => [], 'inferences' => [],
            'next_actions' => [], 'model' => 'test-analyzer', 'prompt_version' => 'test-v1',
            'created_at' => '2026-08-08T02:00:00Z',
        ];
        $result['worker_signature'] = 'hmac-sha256:' . hash_hmac('sha256', CanonicalJson::encode($result), $this->workerSigningSecret);
        return $result;
    }
}
