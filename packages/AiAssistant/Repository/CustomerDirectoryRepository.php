<?php
declare(strict_types=1);

namespace Mublo\Packages\AiAssistant\Repository;

use Mublo\Contract\Security\SensitiveValueCodecInterface;
use Mublo\Infrastructure\Database\Database;
use Mublo\Packages\AiAssistant\Exception\ApiException;
use Mublo\Packages\AiAssistant\Support\Time;

final class CustomerDirectoryRepository
{
    public function __construct(
        private Database $db,
        private SensitiveValueCodecInterface $codec
    ) {
    }

    /** @param array<string, mixed> $record */
    public function assertProjectionAllowed(string $companyId, array $record): void
    {
        if ($record['object_type'] === 'customer') {
            $existing = $this->db->selectOne(
                'SELECT company_id FROM ai_customer_directory WHERE customer_id = ? LIMIT 1',
                [$record['object_id']]
            );
            $this->assertCompanyScope($companyId, $existing);
            return;
        }
        if ($record['object_type'] === 'customer_phone') {
            $existing = $this->db->selectOne(
                'SELECT company_id FROM ai_customer_phones WHERE customer_phone_id = ? LIMIT 1',
                [$record['object_id']]
            );
            $this->assertCompanyScope($companyId, $existing);
        }
        if ($record['object_type'] !== 'customer_phone' || $record['operation'] !== 'UPSERT') {
            return;
        }
        $customerId = (string) $record['payload']['customer_id'];
        $customer = $this->db->selectOne(
            'SELECT management_status, deleted_at FROM ai_customer_directory
              WHERE company_id = ? AND customer_id = ? LIMIT 1',
            [$companyId, $customerId]
        );
        if ($customer === null || $customer['deleted_at'] !== null) {
            throw new ApiException(
                'SYNC_PHONE_CUSTOMER_NOT_FOUND',
                '전화번호가 참조하는 활성 고객을 먼저 동기화해야 합니다.',
                422
            );
        }
    }

    /** @param array<string, mixed>|null $existing */
    private function assertCompanyScope(string $companyId, ?array $existing): void
    {
        if ($existing !== null && !hash_equals($companyId, (string) $existing['company_id'])) {
            throw new ApiException(
                'COMPANY_SCOPE_MISMATCH',
                '다른 회사가 사용 중인 객체 ID는 동기화할 수 없습니다.',
                403
            );
        }
    }

    /** @param array<string, mixed> $record */
    public function project(string $companyId, array $record): void
    {
        if ($record['object_type'] === 'customer') {
            $this->projectCustomer($companyId, $record);
        } elseif ($record['object_type'] === 'customer_phone') {
            $this->projectPhone($companyId, $record);
        }
    }

    /** @return array<string, mixed> */
    public function requireManagedPhone(string $companyId, string $customerId, string $customerPhoneId): array
    {
        $phone = $this->findPhone($companyId, $customerPhoneId);
        if ($phone === null || $phone['deleted_at'] !== null) {
            throw new ApiException(
                'CUSTOMER_PHONE_NOT_REGISTERED',
                'API에 등록된 고객 전화번호만 처리할 수 있습니다.',
                422
            );
        }
        if (!hash_equals($customerId, (string) $phone['customer_id'])) {
            throw new ApiException(
                'CUSTOMER_PHONE_SCOPE_MISMATCH',
                '전화번호가 요청 고객에게 속하지 않습니다.',
                422
            );
        }
        if ($phone['customer_deleted_at'] !== null
            || (string) $phone['customer_management_status'] !== 'MANAGED'
            || (string) $phone['management_status'] !== 'MANAGED'
        ) {
            throw new ApiException(
                'CUSTOMER_PHONE_NOT_ELIGIBLE',
                '관리 대상인 활성 고객 전화번호만 처리할 수 있습니다.',
                422
            );
        }
        return $phone;
    }

    /** @return array<string, mixed>|null */
    public function findPhone(string $companyId, string $customerPhoneId): ?array
    {
        return $this->db->selectOne(
            'SELECT p.*, c.management_status AS customer_management_status,
                    c.deleted_at AS customer_deleted_at
               FROM ai_customer_phones p
               JOIN ai_customer_directory c ON c.customer_id = p.customer_id AND c.company_id = p.company_id
              WHERE p.company_id = ? AND p.customer_phone_id = ? LIMIT 1',
            [$companyId, $customerPhoneId]
        );
    }

    /** @param array<string, mixed> $record */
    private function projectCustomer(string $companyId, array $record): void
    {
        $customerId = (string) $record['object_id'];
        $now = Time::database();
        if ($record['operation'] === 'DELETE') {
            $deletedAt = self::databaseTime((string) $record['deleted_at']);
            $this->db->execute(
                'UPDATE ai_customer_directory SET object_version = ?, deleted_at = ?, updated_at = ?
                  WHERE company_id = ? AND customer_id = ?',
                [$record['version'], $deletedAt, $now, $companyId, $customerId]
            );
            $this->db->execute(
                'UPDATE ai_customer_phones SET deleted_at = ?, updated_at = ?
                  WHERE company_id = ? AND customer_id = ? AND deleted_at IS NULL',
                [$deletedAt, $now, $companyId, $customerId]
            );
            return;
        }

        $payload = $record['payload'];
        $values = [
            $this->codec->encrypt((string) $payload['display_name']),
            (string) $payload['management_status'],
            $record['version'],
            $now,
        ];
        $existing = $this->db->selectOne(
            'SELECT customer_id FROM ai_customer_directory WHERE customer_id = ? LIMIT 1',
            [$customerId]
        );
        if ($existing === null) {
            $this->db->insert(
                'INSERT INTO ai_customer_directory
                    (customer_id, company_id, display_name_ciphertext, management_status,
                     object_version, deleted_at, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, NULL, ?, ?)',
                [$customerId, $companyId, $values[0], $values[1], $values[2], $now, $now]
            );
            return;
        }
        $this->db->execute(
            'UPDATE ai_customer_directory
                SET display_name_ciphertext = ?, management_status = ?, object_version = ?,
                    deleted_at = NULL, updated_at = ?
              WHERE company_id = ? AND customer_id = ?',
            [...$values, $companyId, $customerId]
        );
    }

    /** @param array<string, mixed> $record */
    private function projectPhone(string $companyId, array $record): void
    {
        $phoneId = (string) $record['object_id'];
        $now = Time::database();
        if ($record['operation'] === 'DELETE') {
            $this->db->execute(
                'UPDATE ai_customer_phones SET object_version = ?, deleted_at = ?, updated_at = ?
                  WHERE company_id = ? AND customer_phone_id = ?',
                [$record['version'], self::databaseTime((string) $record['deleted_at']), $now, $companyId, $phoneId]
            );
            return;
        }

        $payload = $record['payload'];
        $phone = (string) $payload['normalized_phone'];
        $values = [
            (string) $payload['customer_id'],
            $this->codec->encrypt($phone),
            $this->codec->createSearchIndex($phone),
            (string) ($payload['management_status'] ?? 'MANAGED'),
            !empty($payload['is_primary']) ? 1 : 0,
            $record['version'],
            $now,
        ];
        $existing = $this->db->selectOne(
            'SELECT customer_phone_id FROM ai_customer_phones WHERE customer_phone_id = ? LIMIT 1',
            [$phoneId]
        );
        if ($existing === null) {
            $this->db->insert(
                'INSERT INTO ai_customer_phones
                    (customer_phone_id, company_id, customer_id, phone_ciphertext, phone_lookup_token,
                     management_status, is_primary, object_version, deleted_at, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?)',
                [$phoneId, $companyId, $values[0], $values[1], $values[2], $values[3], $values[4], $values[5], $now, $now]
            );
            return;
        }
        $this->db->execute(
            'UPDATE ai_customer_phones
                SET customer_id = ?, phone_ciphertext = ?, phone_lookup_token = ?, management_status = ?,
                    is_primary = ?, object_version = ?, deleted_at = NULL, updated_at = ?
              WHERE company_id = ? AND customer_phone_id = ?',
            [...$values, $companyId, $phoneId]
        );
    }

    private static function databaseTime(string $value): string
    {
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            throw new ApiException('SYNC_TIME_INVALID', '동기화 시각 형식이 올바르지 않습니다.', 422);
        }
        return Time::database($timestamp);
    }
}
