<?php
declare(strict_types=1);

namespace Tests\AiAssistant\Integration;

use Mublo\Packages\AiAssistant\Exception\ApiException;
use Mublo\Packages\AiAssistant\Support\CursorCodec;
use Mublo\Packages\AiAssistant\Support\Uuid;
use Tests\AiAssistant\DatabaseTestCase;

final class CustomerSyncTest extends DatabaseTestCase
{
    public function testPushDeltaTombstoneAndIdempotency(): void
    {
        $principal = $this->principal('sync-company', 201, 'sync-company-secret');
        $device = $this->enroll($principal, 'installation-sync-company-01');
        $customerId = Uuid::v4();
        $record = $this->customerRecord((string) $principal['company_id'], $customerId, 1, 'UPSERT');
        $request = ['device_id' => $device['device_id'], 'records' => [$record]];

        $first = $this->idempotency->execute(
            (string) $principal['company_id'],
            'sync.customers.push',
            'sync-key-0001',
            $request,
            200,
            fn(): array => $this->sync->push($principal, (string) $device['device_id'], [$record])
        );
        self::assertFalse($first['replayed']);
        self::assertSame(1, $first['data']['applied']);
        $stored = $this->db->selectOne(
            'SELECT payload_json, search_token FROM ai_sync_records WHERE company_id = ?',
            [$principal['company_id']]
        );
        self::assertNotNull($stored);
        self::assertStringNotContainsString('테스트 고객', (string) $stored['payload_json']);
        $directory = $this->db->selectOne(
            'SELECT display_name_ciphertext, management_status FROM ai_customer_directory WHERE customer_id = ?',
            [$customerId]
        );
        self::assertNotNull($directory);
        self::assertStringNotContainsString('테스트 고객', (string) $directory['display_name_ciphertext']);
        self::assertSame('MANAGED', $directory['management_status']);

        $replayed = $this->idempotency->execute(
            (string) $principal['company_id'],
            'sync.customers.push',
            'sync-key-0001',
            $request,
            200,
            fn(): array => self::fail('Replayed operation must not execute')
        );
        self::assertTrue($replayed['replayed']);

        $delta = $this->sync->delta($principal, CursorCodec::encode(0), 100);
        self::assertCount(1, $delta['records']);
        self::assertSame($customerId, $delta['records'][0]['object_id']);

        $delete = $this->customerRecord((string) $principal['company_id'], $customerId, 2, 'DELETE');
        $deleted = $this->sync->push($principal, (string) $device['device_id'], [$delete]);
        self::assertSame(1, $deleted['applied']);

        $restore = $this->customerRecord((string) $principal['company_id'], $customerId, 3, 'UPSERT');
        $blocked = $this->sync->push($principal, (string) $device['device_id'], [$restore]);
        self::assertSame('SYNC_TOMBSTONE_WINS', $blocked['conflicts'][0]['code']);

        $snapshot = $this->sync->bootstrap($principal, null, 100);
        self::assertCount(1, $snapshot['records']);
        self::assertSame('DELETE', $snapshot['records'][0]['operation']);
    }

    public function testPhoneProjectionIsEncryptedAndRequiresAnExistingCustomer(): void
    {
        $principal = $this->principal('phone-company', 302, 'phone-company-secret');
        $device = $this->enroll($principal, 'installation-phone-company-1');
        $customerId = Uuid::v4();
        $phoneId = Uuid::v4();
        $phone = $this->phoneRecord((string) $principal['company_id'], $customerId, $phoneId);

        try {
            $this->sync->push($principal, (string) $device['device_id'], [$phone]);
            self::fail('Phone projection must require its customer');
        } catch (ApiException $exception) {
            self::assertSame('SYNC_PHONE_CUSTOMER_NOT_FOUND', $exception->errorCode);
        }

        $customer = $this->customerRecord((string) $principal['company_id'], $customerId, 1, 'UPSERT');
        $result = $this->sync->push($principal, (string) $device['device_id'], [$customer, $phone]);
        self::assertSame(2, $result['applied']);
        $stored = $this->db->selectOne(
            'SELECT phone_ciphertext, phone_lookup_token FROM ai_customer_phones WHERE customer_phone_id = ?',
            [$phoneId]
        );
        self::assertNotNull($stored);
        self::assertStringNotContainsString('821012345678', (string) $stored['phone_ciphertext']);
        self::assertSame(64, strlen((string) $stored['phone_lookup_token']));
    }

    public function testCompanyScopeAndIdempotencyPayloadMismatchAreRejected(): void
    {
        $principal = $this->principal('scope-company', 301, 'scope-company-secret');
        $device = $this->enroll($principal, 'installation-scope-company-1');
        $record = $this->customerRecord(Uuid::v4(), Uuid::v4(), 1, 'UPSERT');

        try {
            $this->sync->push($principal, (string) $device['device_id'], [$record]);
            self::fail('Cross-company record must fail');
        } catch (ApiException $exception) {
            self::assertSame('COMPANY_SCOPE_MISMATCH', $exception->errorCode);
            self::assertSame(403, $exception->statusCode);
        }

        $companyId = (string) $principal['company_id'];
        $this->idempotency->execute($companyId, 'test', 'same-key-001', ['a' => 1], 200, fn(): array => ['ok' => true]);
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('같은 Idempotency-Key가 다른 요청에 사용되었습니다.');
        $this->idempotency->execute($companyId, 'test', 'same-key-001', ['a' => 2], 200, fn(): array => ['ok' => true]);
    }

    public function testBasicPlanSummaryAndThirtyCustomerLimit(): void
    {
        $principal = $this->principal('basic-plan-company', 401, 'basic-plan-company-secret');
        $device = $this->enroll($principal, 'installation-basic-plan-company-1');
        $companyId = (string) $principal['company_id'];

        $initial = $this->subscription->summary($principal);
        self::assertSame('subscription-v1', $initial['schema_version']);
        self::assertSame('BASIC', $initial['plan']['code']);
        self::assertSame('Basic', $initial['plan']['name']);
        self::assertNull($initial['plan']['monthly_price_krw']);
        self::assertSame(30, $initial['plan']['customer_limit']);
        self::assertSame(0, $initial['usage']['registered_customers']);
        self::assertSame(30, $initial['usage']['remaining_customers']);

        for ($index = 1; $index <= 30; $index++) {
            $record = $this->customerRecord($companyId, Uuid::v4(), 1, 'UPSERT');
            $result = $this->sync->push($principal, (string) $device['device_id'], [$record]);
            self::assertSame(1, $result['applied']);
        }

        $full = $this->subscription->summary($principal);
        self::assertSame(30, $full['usage']['registered_customers']);
        self::assertSame(0, $full['usage']['remaining_customers']);

        try {
            $record = $this->customerRecord($companyId, Uuid::v4(), 1, 'UPSERT');
            $this->sync->push($principal, (string) $device['device_id'], [$record]);
            self::fail('The thirty-first managed customer must be rejected');
        } catch (ApiException $exception) {
            self::assertSame('CUSTOMER_PLAN_LIMIT_EXCEEDED', $exception->errorCode);
            self::assertSame(409, $exception->statusCode);
            self::assertSame(30, $exception->details['customer_limit']);
            self::assertSame(30, $exception->details['registered_customers']);
        }
    }

    /** @return array<string, mixed> */
    private function customerRecord(string $companyId, string $customerId, int $version, string $operation): array
    {
        $deleted = $operation === 'DELETE';
        return [
            'schema_version' => 'sync-record-v1',
            'company_id' => $companyId,
            'object_type' => 'customer',
            'object_id' => $customerId,
            'operation' => $operation,
            'version' => $version,
            'updated_at' => '2026-08-08T00:00:0' . min(9, $version) . 'Z',
            'deleted_at' => $deleted ? '2026-08-08T00:01:00Z' : null,
            'payload' => $deleted ? [] : [
                'display_name' => '테스트 고객',
                'management_status' => 'MANAGED',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function phoneRecord(string $companyId, string $customerId, string $phoneId): array
    {
        return [
            'schema_version' => 'sync-record-v1', 'company_id' => $companyId,
            'object_type' => 'customer_phone', 'object_id' => $phoneId, 'operation' => 'UPSERT',
            'version' => 1, 'updated_at' => '2026-08-08T00:00:02Z', 'deleted_at' => null,
            'payload' => [
                'customer_id' => $customerId, 'normalized_phone' => '+821012345678',
                'management_status' => 'MANAGED', 'is_primary' => true,
            ],
        ];
    }
}
