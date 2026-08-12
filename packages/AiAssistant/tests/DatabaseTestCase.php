<?php
declare(strict_types=1);

namespace Tests\AiAssistant;

use Mublo\Contract\Security\SensitiveValueCodecInterface;
use Mublo\Infrastructure\Database\Database;
use Mublo\Packages\AiAssistant\Repository\AuthTokenRepository;
use Mublo\Packages\AiAssistant\Repository\AnalysisRepository;
use Mublo\Packages\AiAssistant\Repository\AnalysisWorkerRepository;
use Mublo\Packages\AiAssistant\Repository\CompanyUserRepository;
use Mublo\Packages\AiAssistant\Repository\CustomerDirectoryRepository;
use Mublo\Packages\AiAssistant\Repository\DeviceRepository;
use Mublo\Packages\AiAssistant\Repository\IdempotencyRepository;
use Mublo\Packages\AiAssistant\Repository\InteractionRepository;
use Mublo\Packages\AiAssistant\Repository\MessagingPolicyRepository;
use Mublo\Packages\AiAssistant\Repository\MessagingDispatchRepository;
use Mublo\Packages\AiAssistant\Repository\MessageScheduleRepository;
use Mublo\Packages\AiAssistant\Repository\SyncRecordRepository;
use Mublo\Packages\AiAssistant\Repository\SaasRepository;
use Mublo\Packages\AiAssistant\Repository\SubscriptionRepository;
use Mublo\Packages\AiAssistant\Security\WorkerSecurity;
use Mublo\Packages\AiAssistant\Security\WorkerResultVerifierInterface;
use Mublo\Packages\AiAssistant\Service\AuthService;
use Mublo\Packages\AiAssistant\Service\AnalysisService;
use Mublo\Packages\AiAssistant\Service\AnalysisWorkerService;
use Mublo\Packages\AiAssistant\Service\CompanyProvisioningService;
use Mublo\Packages\AiAssistant\Service\CustomerSyncService;
use Mublo\Packages\AiAssistant\Service\DeviceService;
use Mublo\Packages\AiAssistant\Service\IdempotencyService;
use Mublo\Packages\AiAssistant\Service\InteractionService;
use Mublo\Packages\AiAssistant\Service\MessagingPolicyService;
use Mublo\Packages\AiAssistant\Service\MessageScheduleService;
use Mublo\Packages\AiAssistant\Service\WorkerJobService;
use Mublo\Packages\AiAssistant\Service\SaasService;
use Mublo\Packages\AiAssistant\Service\SubscriptionService;
use PDO;
use PHPUnit\Framework\TestCase;

abstract class DatabaseTestCase extends TestCase
{
    protected Database $db;
    protected CompanyProvisioningService $provisioning;
    protected AuthService $auth;
    protected DeviceService $devices;
    protected CustomerSyncService $sync;
    protected IdempotencyService $idempotency;
    protected InteractionService $interactions;
    protected AnalysisService $analysis;
    protected MessagingPolicyService $messaging;
    protected MessageScheduleService $schedules;
    protected WorkerJobService $workerJobs;
    protected AnalysisWorkerService $analysisWorker;
    protected SaasService $saas;
    protected SubscriptionService $subscription;
    protected string $workerSigningSecret = 'test-worker-signing-secret-at-least-32-bytes';

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db = new Database($pdo);
        $this->createSchema($pdo);

        $companyUsers = new CompanyUserRepository($this->db);
        $tokens = new AuthTokenRepository($this->db);
        $deviceRepository = new DeviceRepository($this->db);
        $this->provisioning = new CompanyProvisioningService($companyUsers);
        $this->auth = new AuthService($companyUsers, $tokens);
        $this->devices = new DeviceService($deviceRepository);
        $codec = new class implements SensitiveValueCodecInterface {
            public function encrypt(string $plainText): string { return 'test:' . base64_encode($plainText); }
            public function decrypt(string $encoded): ?string {
                if (!str_starts_with($encoded, 'test:')) return null;
                $value = base64_decode(substr($encoded, 5), true);
                return is_string($value) ? $value : null;
            }
            public function createSearchIndex(string $value): string { return hash('sha256', 'test|' . $value); }
            public function matchSearchIndex(string $storedIndex, string $searchValue): bool {
                return hash_equals($storedIndex, $this->createSearchIndex($searchValue));
            }
            public function processFieldValue(string $value, bool $encrypted, bool $searchable, ?string $searchIndexValue = null): array {
                return [
                    'field_value' => $encrypted ? $this->encrypt($value) : $value,
                    'search_index' => $searchable ? $this->createSearchIndex($searchIndexValue ?? $value) : null,
                ];
            }
            public function readFieldValue(?string $storedValue, bool $encrypted): ?string {
                if ($storedValue === null) return null;
                return $encrypted ? $this->decrypt($storedValue) : $storedValue;
            }
        };
        $directory = new CustomerDirectoryRepository($this->db, $codec);
        $this->subscription = new SubscriptionService(new SubscriptionRepository($this->db));
        $this->sync = new CustomerSyncService(
            new SyncRecordRepository($this->db, $codec),
            $deviceRepository,
            $directory,
            $this->subscription
        );
        $this->messaging = new MessagingPolicyService(
            $directory,
            new MessagingPolicyRepository($this->db),
            new MessagingDispatchRepository($this->db)
        );
        $this->schedules = new MessageScheduleService(
            new MessageScheduleRepository($this->db),
            $deviceRepository,
            $directory,
            $codec
        );
        $this->idempotency = new IdempotencyService(new IdempotencyRepository($this->db));
        $publicKey = <<<'PEM'
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAz8wxRDXulkx50ypWsJz4
KYdOYelSKbJ5WgSdpZIxIf1xS1raV3+pQZchp1BAGMIAzd9w4h2pPz8J2/he6lgm
n8W/qtoCbMFom9Htn8AVJ9VxN8gMwtbwVSkEb0neZM9HuPcM7mM8bibOKWFanzEP
RSzFxvd09nw4uAMlkpIFVuZNcLTt+TC86Q2BNaHCTDmzJKlyswwfMQJvgdRmjHS3
sJpS87OPD38Jm3q9wjfHM/IdRDnT4N2mV/0x/CSMEJHPiOnXppystHgm8S/GIumq
uDgpOHs1qmlMdW3oRLO922wVryH4DQYfb9tIKuE1qezDNnush4wOWYhH3cANPYFy
VQIDAQAB
-----END PUBLIC KEY-----
PEM;
        $workerSecurity = new WorkerSecurity(
            'worker-test-key-v1',
            $publicKey,
            'test-worker-token',
            $this->workerSigningSecret
        );
        $workerV2Security = new class implements WorkerResultVerifierInterface {
            public function readiness(): array
            {
                return ['ready' => true, 'signing_key_id' => 'test-signing-key-v1'];
            }

            public function verifyResultSignature(array $result): void
            {
                if (($result['worker_signature'] ?? null) !== 'test-ed25519-signature') {
                    throw new \RuntimeException('Unexpected test signature');
                }
            }
        };
        $interactionRepository = new InteractionRepository($this->db);
        $this->interactions = new InteractionService(
            $interactionRepository,
            $deviceRepository,
            $directory,
            $workerSecurity
        );
        $this->analysis = new AnalysisService(
            new AnalysisRepository($this->db),
            $interactionRepository,
            $deviceRepository,
            $directory
        );
        $this->workerJobs = new WorkerJobService($interactionRepository, $workerSecurity);
        $this->analysisWorker = new AnalysisWorkerService(
            new AnalysisWorkerRepository($this->db),
            $workerSecurity,
            $workerV2Security
        );
        $this->saas = new SaasService(new SaasRepository($this->db, $codec));
    }

    private function createSchema(PDO $pdo): void
    {
        $statements = [
            'CREATE TABLE ai_companies (
                company_id TEXT PRIMARY KEY, framework_domain_id INTEGER UNIQUE, slug TEXT UNIQUE,
                name TEXT, status TEXT, created_at TEXT, updated_at TEXT
            )',
            'CREATE TABLE ai_subscription_plans (
                plan_code TEXT PRIMARY KEY, name TEXT, monthly_price_krw INTEGER,
                customer_limit INTEGER, status TEXT, created_at TEXT, updated_at TEXT
            )',
            'CREATE TABLE ai_company_subscriptions (
                company_id TEXT PRIMARY KEY, plan_code TEXT, status TEXT,
                started_at TEXT, created_at TEXT, updated_at TEXT
            )',
            'CREATE TABLE ai_company_users (
                user_id TEXT PRIMARY KEY, company_id TEXT, login_id TEXT, nickname TEXT,
                password_hash TEXT, role TEXT, status TEXT, created_at TEXT, updated_at TEXT,
                UNIQUE(company_id, login_id)
            )',
            'CREATE TABLE ai_auth_tokens (
                token_id TEXT PRIMARY KEY, company_id TEXT, user_id TEXT, access_hash TEXT UNIQUE,
                refresh_hash TEXT UNIQUE, access_expires_at TEXT, refresh_expires_at TEXT,
                revoked_at TEXT, rotated_to_token_id TEXT, created_at TEXT
            )',
            'CREATE TABLE ai_devices (
                device_id TEXT PRIMARY KEY, company_id TEXT, enrolled_by_user_id TEXT,
                installation_hash TEXT, name TEXT, platform TEXT, public_key TEXT, fcm_token TEXT,
                capabilities_json TEXT, health_json TEXT, app_version TEXT, os_version TEXT,
                status TEXT, last_seen_at TEXT, created_at TEXT, updated_at TEXT,
                UNIQUE(company_id, installation_hash)
            )',
            'CREATE TABLE ai_sync_records (
                record_id INTEGER PRIMARY KEY AUTOINCREMENT, company_id TEXT, object_type TEXT,
                object_id TEXT, operation TEXT, object_version INTEGER, payload_json TEXT, search_token TEXT,
                source_device_id TEXT, object_updated_at TEXT, deleted_at TEXT,
                change_sequence INTEGER, created_at TEXT, updated_at TEXT,
                UNIQUE(company_id, object_type, object_id)
            )',
            'CREATE TABLE ai_sync_changes (
                sequence_id INTEGER PRIMARY KEY AUTOINCREMENT, company_id TEXT, object_type TEXT,
                object_id TEXT, operation TEXT, object_version INTEGER, payload_json TEXT,
                object_updated_at TEXT, deleted_at TEXT, created_at TEXT
            )',
            'CREATE TABLE ai_idempotency_keys (
                idempotency_id INTEGER PRIMARY KEY AUTOINCREMENT, company_id TEXT, endpoint TEXT,
                key_hash TEXT, request_hash TEXT, response_json TEXT, response_status INTEGER,
                expires_at TEXT, created_at TEXT, UNIQUE(company_id, endpoint, key_hash)
            )',
            'CREATE TABLE ai_customer_directory (
                customer_id TEXT PRIMARY KEY, company_id TEXT, display_name_ciphertext TEXT,
                management_status TEXT, object_version INTEGER, deleted_at TEXT,
                created_at TEXT, updated_at TEXT
            )',
            'CREATE TABLE ai_customer_phones (
                customer_phone_id TEXT PRIMARY KEY, company_id TEXT, customer_id TEXT,
                phone_ciphertext TEXT, phone_lookup_token TEXT, management_status TEXT,
                is_primary INTEGER, object_version INTEGER, deleted_at TEXT,
                created_at TEXT, updated_at TEXT
            )',
            'CREATE TABLE ai_contact_permissions (
                permission_id TEXT PRIMARY KEY, company_id TEXT, customer_phone_id TEXT,
                channel TEXT, purpose TEXT, status TEXT, legal_basis TEXT, captured_at TEXT,
                source TEXT, expires_at TEXT, permission_version INTEGER, created_at TEXT, updated_at TEXT,
                UNIQUE(company_id, customer_phone_id, channel, purpose)
            )',
            'CREATE TABLE ai_suppression_entries (
                suppression_id TEXT PRIMARY KEY, company_id TEXT, customer_phone_id TEXT,
                phone_lookup_token TEXT, channel TEXT, reason TEXT, source TEXT,
                suppression_version INTEGER, created_at TEXT, lifted_at TEXT, updated_at TEXT,
                UNIQUE(company_id, phone_lookup_token, channel)
            )',
            'CREATE TABLE ai_suppression_events (
                event_id TEXT PRIMARY KEY, company_id TEXT, customer_phone_id TEXT,
                phone_lookup_token TEXT, channel TEXT, action TEXT, reason TEXT, source TEXT,
                occurred_at TEXT, suppression_version INTEGER, created_at TEXT,
                UNIQUE(company_id, phone_lookup_token, channel, suppression_version)
            )',
            'CREATE TABLE ai_campaign_recipient_snapshots (
                snapshot_id TEXT PRIMARY KEY, snapshot_batch_id TEXT, campaign_id TEXT,
                company_id TEXT, customer_id TEXT, customer_phone_id TEXT, channel TEXT,
                message_class TEXT, content_version INTEGER, eligible INTEGER,
                reason_codes_json TEXT, permission_version INTEGER, suppression_version INTEGER,
                policy_checked_at TEXT, created_at TEXT,
                UNIQUE(company_id, campaign_id, customer_phone_id)
            )',
            'CREATE TABLE ai_messaging_campaign_policies (
                campaign_id TEXT PRIMARY KEY, company_id TEXT, channel TEXT, message_class TEXT,
                content_version INTEGER, approved_content_version INTEGER, timezone TEXT,
                quiet_hours_start TEXT, quiet_hours_end TEXT, per_recipient_daily_limit INTEGER,
                policy_version INTEGER, created_at TEXT, updated_at TEXT
            )',
            'CREATE TABLE ai_messaging_dispatch_reservations (
                reservation_id TEXT PRIMARY KEY, preflight_id TEXT, company_id TEXT, campaign_id TEXT,
                snapshot_batch_id TEXT, customer_id TEXT, customer_phone_id TEXT, channel TEXT,
                message_class TEXT, content_version INTEGER, status TEXT, reason_codes_json TEXT,
                permission_version INTEGER, suppression_version INTEGER, evaluated_at TEXT, created_at TEXT,
                UNIQUE(company_id, campaign_id, customer_phone_id, content_version),
                UNIQUE(company_id, preflight_id, customer_phone_id)
            )',
            'CREATE TABLE ai_message_schedules (
                schedule_id TEXT PRIMARY KEY, company_id TEXT, device_id TEXT, customer_id TEXT,
                customer_phone_id TEXT, dispatch_id TEXT UNIQUE, revision INTEGER, channel TEXT,
                message_class TEXT, content_ciphertext TEXT, fallback_ciphertext TEXT, status TEXT,
                device_status TEXT, scheduled_at TEXT, expires_at TEXT, dispatched_at TEXT,
                completed_at TEXT, last_error TEXT, created_at TEXT, updated_at TEXT
            )',
            'CREATE TABLE ai_schedule_dispatch_outbox (
                outbox_id INTEGER PRIMARY KEY AUTOINCREMENT, schedule_id TEXT, company_id TEXT,
                device_id TEXT, dispatch_id TEXT UNIQUE, revision INTEGER, status TEXT,
                attempt_count INTEGER, available_at TEXT, lease_token_hash TEXT,
                lease_expires_at TEXT, fcm_message_id TEXT, last_error TEXT,
                acknowledged_at TEXT, created_at TEXT, updated_at TEXT
            )',
            'CREATE TABLE ai_interactions (
                interaction_id TEXT PRIMARY KEY, company_id TEXT, customer_id TEXT, customer_phone_id TEXT, device_id TEXT,
                channel TEXT, occurred_at TEXT, envelope_json TEXT, envelope_sha256 TEXT,
                status TEXT, created_at TEXT, updated_at TEXT
            )',
            'CREATE TABLE ai_analysis_jobs (
                job_id TEXT PRIMARY KEY, interaction_id TEXT UNIQUE, company_id TEXT, customer_id TEXT,
                status TEXT, attempts INTEGER, available_at TEXT, lease_owner TEXT,
                lease_token_hash TEXT, lease_expires_at TEXT, last_error_code TEXT,
                created_at TEXT, updated_at TEXT
            )',
            'CREATE TABLE ai_analysis_results (
                analysis_id TEXT PRIMARY KEY, job_id TEXT UNIQUE, interaction_id TEXT,
                company_id TEXT, customer_id TEXT, input_cursor TEXT, result_json TEXT, created_at TEXT
            )',
            'CREATE TABLE ai_interaction_v3_index (
                server_sequence INTEGER PRIMARY KEY AUTOINCREMENT, interaction_id TEXT UNIQUE,
                company_id TEXT, customer_id TEXT, customer_phone_id TEXT, device_id TEXT,
                channel TEXT, source_record_id TEXT, content_sha256 TEXT, created_at TEXT,
                UNIQUE(company_id, device_id, channel, source_record_id)
            )',
            'CREATE TABLE ai_analysis_consent_receipts (
                consent_receipt_id TEXT PRIMARY KEY, company_id TEXT, user_id TEXT, device_id TEXT,
                consent_version TEXT, accepted_at TEXT, selected_customer_set_sha256 TEXT,
                customer_ids_json TEXT, created_at TEXT
            )',
            'CREATE TABLE ai_analysis_batches (
                batch_id TEXT PRIMARY KEY, company_id TEXT, requested_by_user_id TEXT, device_id TEXT,
                consent_receipt_id TEXT, purpose TEXT, selected_customer_set_sha256 TEXT,
                status TEXT, total_customers INTEGER, created_at TEXT, updated_at TEXT
            )',
            'CREATE TABLE ai_analysis_runs (
                run_id TEXT PRIMARY KEY, batch_id TEXT, company_id TEXT, customer_id TEXT, mode TEXT,
                base_analysis_id TEXT, from_cursor INTEGER, input_cursor INTEGER, status TEXT, stage TEXT,
                progress_processed INTEGER, progress_total INTEGER, progress_sequence INTEGER,
                retryable INTEGER, reason_code TEXT, analysis_id TEXT, created_at TEXT, updated_at TEXT,
                UNIQUE(batch_id, customer_id)
            )',
            'CREATE TABLE ai_analysis_manifests (
                manifest_id TEXT PRIMARY KEY, run_id TEXT UNIQUE, company_id TEXT, customer_id TEXT,
                manifest_sha256 TEXT, manifest_json TEXT, created_at TEXT
            )',
            'CREATE TABLE ai_analysis_jobs_v2 (
                job_id TEXT PRIMARY KEY, run_id TEXT UNIQUE, company_id TEXT, status TEXT,
                attempts INTEGER, available_at TEXT, lease_owner TEXT, lease_token_hash TEXT,
                lease_expires_at TEXT, last_error_code TEXT, created_at TEXT, updated_at TEXT
            )',
            'CREATE TABLE ai_analysis_results_v2 (
                analysis_id TEXT PRIMARY KEY, job_id TEXT UNIQUE, run_id TEXT UNIQUE, company_id TEXT,
                customer_id TEXT, manifest_sha256 TEXT, terminal_status TEXT, result_json TEXT, created_at TEXT
            )',
            'CREATE TABLE ai_worker_heartbeats (
                worker_id TEXT PRIMARY KEY, status TEXT, capabilities_json TEXT, active_job_count INTEGER,
                version TEXT, observed_at TEXT, received_at TEXT
            )',
        ];
        foreach ($statements as $statement) {
            $pdo->exec($statement);
        }
        $pdo->exec(
            "INSERT INTO ai_subscription_plans
                (plan_code, name, monthly_price_krw, customer_limit, status, created_at, updated_at)
             VALUES ('BASIC', 'Basic', NULL, 30, 'ACTIVE', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );
    }

    /** @return array<string, mixed> */
    protected function principal(string $slug, int $domainId, string $password): array
    {
        $this->provisioning->provisionOwner($domainId, $slug, strtoupper($slug), 'owner', 'Owner', $password);
        $login = $this->auth->login($slug, null, 'owner', $password);
        return $login['principal'];
    }

    /** @param array<string, mixed> $principal @return array<string, mixed> */
    protected function enroll(array $principal, string $installationId): array
    {
        return $this->devices->enroll($principal, [
            'installation_id' => $installationId,
            'name' => 'Galaxy test',
            'platform' => 'ANDROID',
            'public_key' => str_repeat('A', 96),
            'fcm_token' => 'test-fcm-token-' . $installationId,
            'capabilities' => ['CALL_COLLECT', 'SMS_COLLECT', 'SMS_SEND', 'KAKAO_SEND'],
            'app_version' => '0.1.0',
            'os_version' => '14',
        ]);
    }
}
