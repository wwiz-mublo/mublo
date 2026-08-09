<?php
declare(strict_types=1);

namespace Tests\AiAssistant\Integration;

use Mublo\Packages\AiAssistant\Exception\ApiException;
use Mublo\Packages\AiAssistant\Support\CanonicalJson;
use Mublo\Packages\AiAssistant\Support\InputSetDigest;
use Mublo\Packages\AiAssistant\Support\Uuid;
use Tests\AiAssistant\DatabaseTestCase;

final class AnalysisBatchTest extends DatabaseTestCase
{
    public function testV3InputsAreVerifiedBeforeAnImmutableCustomerRunIsQueued(): void
    {
        [$principal, $device, $customerId, $phoneId] = $this->customerFixture('analysis-batch', 601);
        $call = $this->uploadV3($principal, $device, $customerId, $phoneId, 'CALL_TRANSCRIPT', 'call-001', '통화 내용');
        $sms = $this->uploadV3($principal, $device, $customerId, $phoneId, 'SMS', 'sms-001', '문자 내용');
        self::assertSame('STORED', $call['disposition']);
        self::assertGreaterThan((int) $call['server_sequence'], (int) $sms['server_sequence']);

        $duplicate = $this->uploadV3($principal, $device, $customerId, $phoneId, 'CALL_TRANSCRIPT', 'call-001', '통화 내용', (string) $call['interaction_id']);
        self::assertSame('DUPLICATE', $duplicate['disposition']);
        self::assertSame($call['server_sequence'], $duplicate['server_sequence']);

        $customerHash = InputSetDigest::customers([$customerId]);
        $consentId = Uuid::v4();
        $consent = $this->analysis->registerConsent($principal, [
            'schema_version' => 'analysis-consent-v1',
            'consent_receipt_id' => $consentId,
            'device_id' => $device['device_id'],
            'consent_version' => 'onboarding-ai-v1',
            'accepted_at' => '2026-08-09T01:00:00Z',
            'selected_customer_set_sha256' => $customerHash,
            'customer_ids' => [$customerId],
        ]);
        self::assertFalse($consent['replayed']);

        $batch = $this->analysis->createBatch($principal, [
            'schema_version' => 'analysis-batch-create-v1',
            'purpose' => 'INITIAL_ONBOARDING',
            'device_id' => $device['device_id'],
            'consent_receipt_id' => $consentId,
            'selected_customer_set_sha256' => $customerHash,
            'customers' => [[
                'customer_id' => $customerId,
                'mode' => 'INITIAL',
                'collection_window' => ['from' => null, 'to' => '2026-08-10T00:00:00Z'],
                'channels' => [
                    'CALL_TRANSCRIPT' => $this->reportedChannel((string) $call['interaction_id'], '통화 내용'),
                    'SMS' => $this->reportedChannel((string) $sms['interaction_id'], '문자 내용'),
                    'KAKAO' => $this->emptyChannel('NOT_LINKED'),
                ],
            ]],
        ]);

        self::assertSame('IN_PROGRESS', $batch['status']);
        self::assertSame('QUEUED', $batch['runs'][0]['status']);
        self::assertSame(2, $batch['runs'][0]['progress']['total']);
        $manifest = $this->db->selectOne('SELECT manifest_json, manifest_sha256 FROM ai_analysis_manifests');
        self::assertNotNull($manifest);
        $decoded = json_decode((string) $manifest['manifest_json'], true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(2, $decoded['interactions']);
        self::assertSame($manifest['manifest_sha256'], $decoded['manifest_sha256']);
        self::assertStringNotContainsString('통화 내용', (string) $manifest['manifest_json']);

        $leased = $this->analysisWorker->lease([
            'worker_id' => 'worker-analysis-v2',
            'capabilities' => ['CUSTOMER_PROFILE_ANALYSIS_V2'],
        ])['job'];
        self::assertIsArray($leased);
        self::assertSame($batch['runs'][0]['run_id'], $leased['run_id']);
        $result = $this->resultV2($leased);
        $completed = $this->analysisWorker->complete((string) $leased['job_id'], [
            'schema_version' => 'worker-complete-v2',
            'lease_token' => $leased['lease_token'],
            'result' => $result,
            'cleanup_attestation' => ['plaintext_files_remaining' => 0, 'completed_at' => '2026-08-09T02:00:01Z'],
        ]);
        self::assertSame('COMPLETED', $completed['status']);
        $finished = $this->analysis->getBatch($principal, (string) $batch['batch_id']);
        self::assertSame('COMPLETED', $finished['status']);
        self::assertSame(1, $finished['terminal_customers']);
    }

    public function testInputMismatchRejectsWholeBatchWithoutPartialRuns(): void
    {
        [$principal, $device, $customerId, $phoneId] = $this->customerFixture('analysis-mismatch', 602);
        $call = $this->uploadV3($principal, $device, $customerId, $phoneId, 'CALL_TRANSCRIPT', 'call-001', '통화 내용');
        $customerHash = InputSetDigest::customers([$customerId]);
        $consentId = Uuid::v4();
        $this->analysis->registerConsent($principal, [
            'schema_version' => 'analysis-consent-v1', 'consent_receipt_id' => $consentId,
            'device_id' => $device['device_id'], 'consent_version' => 'onboarding-ai-v1',
            'accepted_at' => '2026-08-09T01:00:00Z', 'selected_customer_set_sha256' => $customerHash,
            'customer_ids' => [$customerId],
        ]);
        try {
            $this->analysis->createBatch($principal, [
                'schema_version' => 'analysis-batch-create-v1', 'purpose' => 'INITIAL_ONBOARDING',
                'device_id' => $device['device_id'], 'consent_receipt_id' => $consentId,
                'selected_customer_set_sha256' => $customerHash,
                'customers' => [[
                    'customer_id' => $customerId, 'mode' => 'INITIAL',
                    'collection_window' => ['from' => null, 'to' => '2026-08-10T00:00:00Z'],
                    'channels' => [
                        'CALL_TRANSCRIPT' => ['outcome' => 'COMPLETED', 'count' => 0, 'set_sha256' => hash('sha256', '')],
                        'SMS' => $this->emptyChannel('NO_DATA'), 'KAKAO' => $this->emptyChannel('NOT_LINKED'),
                    ],
                ]],
            ]);
            self::fail('Mismatched input set must reject the whole batch');
        } catch (ApiException $exception) {
            self::assertSame('INPUT_SET_MISMATCH', $exception->errorCode);
            self::assertSame(1, $exception->details['expected_count']);
            self::assertSame(
                hash('sha256', (string) $call['interaction_id'] . ':' . hash('sha256', '통화 내용')),
                $exception->details['expected_set_sha256']
            );
        }
        self::assertSame(0, (int) $this->db->selectOne('SELECT COUNT(*) AS c FROM ai_analysis_batches')['c']);
        self::assertSame(0, (int) $this->db->selectOne('SELECT COUNT(*) AS c FROM ai_analysis_runs')['c']);
    }

    public function testCustomerWithNoInteractionsStillCreatesARealWorkerJob(): void
    {
        [$principal, $device, $customerId] = $this->customerFixture('analysis-empty', 603);
        $customerHash = InputSetDigest::customers([$customerId]);
        $consentId = Uuid::v4();
        $this->analysis->registerConsent($principal, [
            'schema_version' => 'analysis-consent-v1', 'consent_receipt_id' => $consentId,
            'device_id' => $device['device_id'], 'consent_version' => 'onboarding-ai-v1',
            'accepted_at' => '2026-08-09T01:00:00Z', 'selected_customer_set_sha256' => $customerHash,
            'customer_ids' => [$customerId],
        ]);
        $batch = $this->analysis->createBatch($principal, [
            'schema_version' => 'analysis-batch-create-v1', 'purpose' => 'INITIAL_ONBOARDING',
            'device_id' => $device['device_id'], 'consent_receipt_id' => $consentId,
            'selected_customer_set_sha256' => $customerHash,
            'customers' => [[
                'customer_id' => $customerId, 'mode' => 'INITIAL',
                'collection_window' => ['from' => null, 'to' => '2026-08-10T00:00:00Z'],
                'channels' => [
                    'CALL_TRANSCRIPT' => $this->emptyChannel('NO_DATA'),
                    'SMS' => $this->emptyChannel('NO_DATA'),
                    'KAKAO' => $this->emptyChannel('NOT_LINKED'),
                ],
            ]],
        ]);
        self::assertSame(0, $batch['runs'][0]['progress']['total']);
        $job = $this->analysisWorker->lease([
            'worker_id' => 'worker-empty-v2', 'capabilities' => ['CUSTOMER_PROFILE_ANALYSIS_V2'],
        ])['job'];
        self::assertIsArray($job);
        self::assertSame([], $job['manifest']['interactions']);
        self::assertSame(0, $job['manifest']['input_cursor']);
    }

    /** @return array{array<string, mixed>, array<string, mixed>, string, string} */
    private function customerFixture(string $slug, int $domain): array
    {
        $principal = $this->principal($slug, $domain, 'analysis-secret');
        $device = $this->enroll($principal, 'installation-' . $slug . '-01');
        $customerId = Uuid::v4();
        $phoneId = Uuid::v4();
        $this->sync->push($principal, (string) $device['device_id'], [
            [
                'schema_version' => 'sync-record-v1', 'company_id' => $principal['company_id'],
                'object_type' => 'customer', 'object_id' => $customerId, 'operation' => 'UPSERT',
                'version' => 1, 'updated_at' => '2026-08-08T00:00:00Z', 'deleted_at' => null,
                'payload' => ['display_name' => '분석 고객', 'management_status' => 'MANAGED'],
            ],
            [
                'schema_version' => 'sync-record-v1', 'company_id' => $principal['company_id'],
                'object_type' => 'customer_phone', 'object_id' => $phoneId, 'operation' => 'UPSERT',
                'version' => 1, 'updated_at' => '2026-08-08T00:00:01Z', 'deleted_at' => null,
                'payload' => ['customer_id' => $customerId, 'normalized_phone' => '+821011112222', 'management_status' => 'MANAGED', 'is_primary' => true],
            ],
        ]);
        return [$principal, $device, $customerId, $phoneId];
    }

    /** @param array<string, mixed> $principal @param array<string, mixed> $device @return array<string, mixed> */
    private function uploadV3(array $principal, array $device, string $customerId, string $phoneId, string $channel, string $sourceId, string $plain, ?string $interactionId = null): array
    {
        $interactionId ??= Uuid::v4();
        $contentHash = hash('sha256', $plain);
        $aad = [
            'schema_version' => 'interaction-upload-v3', 'company_id' => $principal['company_id'],
            'customer_id' => $customerId, 'customer_phone_id' => $phoneId,
            'interaction_id' => $interactionId, 'device_id' => $device['device_id'], 'channel' => $channel,
            'source_record_id' => $sourceId, 'occurred_at' => '2026-08-08T01:00:00Z', 'content_sha256' => $contentHash,
        ];
        return $this->interactions->upload($principal, [
            'schema_version' => 'interaction-upload-v3', 'interaction_id' => $interactionId,
            'customer_id' => $customerId, 'customer_phone_id' => $phoneId, 'device_id' => $device['device_id'],
            'channel' => $channel, 'source_record_id' => $sourceId, 'occurred_at' => '2026-08-08T01:00:00Z',
            'content_sha256' => $contentHash,
            'envelope' => [
                'schema_version' => 'crypto-envelope-v1', 'algorithm' => 'AES-256-GCM',
                'key_wrap_algorithm' => 'RSA-OAEP-256', 'key_id' => 'worker-test-key-v1',
                'wrapped_dek' => base64_encode(str_repeat('w', 256)), 'iv' => base64_encode(str_repeat('i', 12)),
                'ciphertext' => base64_encode('encrypted:' . $contentHash), 'tag' => base64_encode(str_repeat('t', 16)),
                'aad' => base64_encode(CanonicalJson::encode($aad)), 'plaintext_sha256' => $contentHash,
            ],
        ]);
    }

    /** @return array{outcome: string, count: int, set_sha256: string} */
    private function reportedChannel(string $interactionId, string $plain): array
    {
        return ['outcome' => 'COMPLETED', 'count' => 1, 'set_sha256' => hash('sha256', $interactionId . ':' . hash('sha256', $plain))];
    }

    /** @return array{outcome: string, count: int, set_sha256: string} */
    private function emptyChannel(string $outcome): array
    {
        return ['outcome' => $outcome, 'count' => 0, 'set_sha256' => hash('sha256', '')];
    }

    /** @param array<string, mixed> $job @return array<string, mixed> */
    private function resultV2(array $job): array
    {
        $manifest = $job['manifest'];
        $result = [
            'schema_version' => 'analysis-result-v2', 'analysis_id' => Uuid::v4(),
            'run_id' => $job['run_id'], 'job_id' => $job['job_id'],
            'company_id' => $manifest['company_id'], 'customer_id' => $manifest['customer_id'],
            'manifest_id' => $manifest['manifest_id'], 'manifest_sha256' => $manifest['manifest_sha256'],
            'input_cursor' => $manifest['input_cursor'], 'base_analysis_id' => null, 'mode' => $manifest['mode'],
            'terminal_status' => 'COMPLETED', 'insufficient_reason' => null,
            'overall_summary' => '고객은 후속 연락을 기다리고 있습니다.', 'recent_summary' => '최근 미팅을 논의했습니다.',
            'stage' => '관계 형성', 'sentiment' => '긍정', 'risk' => '낮음',
            'channel_counts' => $manifest['channel_counts'],
            'facts' => [['text' => '미팅을 논의함', 'evidence_ids' => [$manifest['interactions'][0]['interaction_id']]]],
            'inferences' => [], 'needs' => [], 'objections' => [], 'commitments' => [], 'open_questions' => [],
            'next_actions' => [], 'message_drafts' => [],
            'quality' => ['coverage' => 1.0, 'warnings' => [], 'unsupported_claim_count' => 0],
            'model_provider' => 'test', 'model' => 'test-model', 'model_version' => '1',
            'prompt_version' => 'profile-v1', 'created_at' => '2026-08-09T02:00:00Z',
            'worker_id' => 'worker-analysis-v2', 'signing_key_id' => 'worker-test-ed25519-v1',
            'signature_algorithm' => 'Ed25519',
        ];
        $result['worker_signature'] = 'test-ed25519-signature';
        return $result;
    }
}
