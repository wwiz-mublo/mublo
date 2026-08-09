<?php
declare(strict_types=1);

namespace Mublo\Packages\AiAssistant\Service;

use Mublo\Packages\AiAssistant\Exception\ApiException;
use Mublo\Packages\AiAssistant\Repository\DeviceRepository;
use Mublo\Packages\AiAssistant\Repository\CustomerDirectoryRepository;
use Mublo\Packages\AiAssistant\Repository\SyncRecordRepository;
use Mublo\Packages\AiAssistant\Support\CanonicalJson;
use Mublo\Packages\AiAssistant\Support\CursorCodec;
use Mublo\Packages\AiAssistant\Support\Time;
use Mublo\Packages\AiAssistant\Support\Uuid;

final class CustomerSyncService
{
    private const OBJECT_TYPES = ['customer', 'customer_phone', 'customer_alias', 'schedule'];

    public function __construct(
        private SyncRecordRepository $records,
        private DeviceRepository $devices,
        private CustomerDirectoryRepository $directory
    ) {
    }

    /** @param array<string, mixed> $principal @return array<string, mixed> */
    public function bootstrap(array $principal, ?string $cursor, int $limit): array
    {
        $after = $cursor === null || $cursor === '' ? 0 : CursorCodec::decode($cursor);
        if ($after === null) {
            throw new ApiException('SYNC_CURSOR_INVALID', '동기화 cursor가 올바르지 않습니다.', 400);
        }
        $limit = max(1, min(500, $limit));
        $rows = $this->records->bootstrap((string) $principal['company_id'], $after, $limit);
        return $this->page($rows, $limit, $after, 'change_sequence');
    }

    /** @param array<string, mixed> $principal @return array<string, mixed> */
    public function delta(array $principal, string $cursor, int $limit): array
    {
        $after = CursorCodec::decode($cursor);
        if ($after === null) {
            throw new ApiException('SYNC_CURSOR_INVALID', '동기화 cursor가 올바르지 않습니다.', 400);
        }
        $limit = max(1, min(500, $limit));
        $rows = $this->records->changesAfter((string) $principal['company_id'], $after, $limit);
        return $this->page($rows, $limit, $after, 'sequence_id');
    }

    /**
     * @param array<string, mixed> $principal
     * @param list<mixed> $inputRecords
     * @return array<string, mixed>
     */
    public function push(array $principal, string $deviceId, array $inputRecords): array
    {
        $companyId = (string) $principal['company_id'];
        if (!Uuid::isValid($deviceId) || $this->devices->findActive($companyId, $deviceId) === null) {
            throw new ApiException('DEVICE_NOT_FOUND', '활성 기기를 찾을 수 없습니다.', 404);
        }
        if ($inputRecords === [] || count($inputRecords) > 500) {
            throw new ApiException('SYNC_BATCH_INVALID', '동기화 레코드는 1~500개여야 합니다.', 422);
        }

        $applied = 0;
        $unchanged = 0;
        $conflicts = [];
        foreach ($inputRecords as $index => $value) {
            if (!is_array($value)) {
                throw new ApiException('SYNC_RECORD_INVALID', '동기화 레코드 형식이 올바르지 않습니다.', 422, ['index' => $index]);
            }
            $record = $this->validateRecord($companyId, $value, $index);
            $current = $this->records->findCurrent(
                $companyId,
                (string) $record['object_type'],
                (string) $record['object_id']
            );
            $decision = $this->decide($record, $current);
            if ($decision === 'UNCHANGED') {
                $unchanged++;
                continue;
            }
            if ($decision !== 'APPLY') {
                $conflicts[] = [
                    'index' => $index,
                    'object_type' => $record['object_type'],
                    'object_id' => $record['object_id'],
                    'code' => $decision,
                    'server_version' => $current === null ? null : (int) $current['object_version'],
                ];
                continue;
            }
            try {
                $this->directory->assertProjectionAllowed($companyId, $record);
                $this->records->apply(
                    $companyId,
                    $deviceId,
                    $record,
                    $current,
                    function () use ($companyId, $record): void {
                        $this->directory->project($companyId, $record);
                    }
                );
                $applied++;
            } catch (ApiException $exception) {
                if ($exception->errorCode !== 'SYNC_CONCURRENT_UPDATE') {
                    throw $exception;
                }
                $conflicts[] = [
                    'index' => $index,
                    'object_type' => $record['object_type'],
                    'object_id' => $record['object_id'],
                    'code' => $exception->errorCode,
                    'server_version' => null,
                ];
            }
        }

        return [
            'applied' => $applied,
            'unchanged' => $unchanged,
            'conflicts' => $conflicts,
            'cursor' => CursorCodec::encode($this->records->maxSequence($companyId)),
        ];
    }

    /** @param array<string, mixed> $record @param array<string, mixed>|null $current */
    private function decide(array $record, ?array $current): string
    {
        if ($current === null) {
            return 'APPLY';
        }
        $incomingVersion = (int) $record['version'];
        $serverVersion = (int) $current['object_version'];
        if ($incomingVersion === $serverVersion
            && (string) $record['operation'] === (string) $current['operation']
            && CanonicalJson::encode($record['payload']) === CanonicalJson::encode($current['payload'])
        ) {
            return 'UNCHANGED';
        }
        if ((string) $current['operation'] === 'DELETE' && (string) $record['operation'] === 'UPSERT') {
            return 'SYNC_TOMBSTONE_WINS';
        }
        if ($incomingVersion <= $serverVersion) {
            return 'SYNC_SERVER_NEWER_OR_DIVERGED';
        }
        return 'APPLY';
    }

    /** @param array<string, mixed> $record @return array<string, mixed> */
    private function validateRecord(string $companyId, array $record, int $index): array
    {
        $required = ['schema_version', 'company_id', 'object_type', 'object_id', 'operation', 'version', 'updated_at', 'payload'];
        foreach ($required as $field) {
            if (!array_key_exists($field, $record)) {
                throw new ApiException('SYNC_RECORD_INVALID', '동기화 필수 필드가 없습니다.', 422, ['index' => $index, 'field' => $field]);
            }
        }
        if ($record['schema_version'] !== 'sync-record-v1') {
            throw new ApiException('SCHEMA_VERSION_UNSUPPORTED', '지원하지 않는 동기화 스키마입니다.', 422, ['index' => $index]);
        }
        if (!hash_equals($companyId, (string) $record['company_id'])) {
            throw new ApiException('COMPANY_SCOPE_MISMATCH', '다른 회사의 데이터는 동기화할 수 없습니다.', 403, ['index' => $index]);
        }
        if (!in_array($record['object_type'], self::OBJECT_TYPES, true) || !Uuid::isValid((string) $record['object_id'])) {
            throw new ApiException('SYNC_OBJECT_INVALID', '동기화 객체 형식이 올바르지 않습니다.', 422, ['index' => $index]);
        }
        if (!in_array($record['operation'], ['UPSERT', 'DELETE'], true)
            || !is_int($record['version'])
            || $record['version'] < 1
            || strtotime((string) $record['updated_at']) === false
            || !is_array($record['payload'])
        ) {
            throw new ApiException('SYNC_RECORD_INVALID', '동기화 레코드 값이 올바르지 않습니다.', 422, ['index' => $index]);
        }
        if ($record['operation'] === 'DELETE') {
            if ($record['payload'] !== [] || empty($record['deleted_at']) || strtotime((string) $record['deleted_at']) === false) {
                throw new ApiException('SYNC_TOMBSTONE_INVALID', '삭제 레코드에는 빈 payload와 deleted_at이 필요합니다.', 422, ['index' => $index]);
            }
        } elseif (!empty($record['deleted_at'])) {
            throw new ApiException('SYNC_RECORD_INVALID', '활성 레코드에는 deleted_at을 사용할 수 없습니다.', 422, ['index' => $index]);
        }
        $this->validatePayload((string) $record['object_type'], (string) $record['operation'], $record['payload'], $index);
        return $record;
    }

    /** @param array<string, mixed> $payload */
    private function validatePayload(string $type, string $operation, array $payload, int $index): void
    {
        if ($operation === 'DELETE') {
            return;
        }
        $forbidden = ['transcript', 'message_body', 'raw_text'];
        foreach ($forbidden as $key) {
            if (array_key_exists($key, $payload)) {
                throw new ApiException('PLAINTEXT_FIELD_FORBIDDEN', '평문 민감정보 필드는 동기화할 수 없습니다.', 422, ['index' => $index, 'field' => $key]);
            }
        }
        if ($type === 'customer' && (!isset($payload['display_name'], $payload['management_status']))) {
            throw new ApiException('SYNC_CUSTOMER_PAYLOAD_INVALID', '고객명과 관리 상태가 필요합니다.', 422, ['index' => $index]);
        }
        if ($type === 'customer'
            && (!is_string($payload['display_name'])
                || trim($payload['display_name']) === ''
                || mb_strlen($payload['display_name']) > 190
                || !in_array($payload['management_status'], ['MANAGED', 'EXCLUDED', 'BLOCKED'], true))
        ) {
            throw new ApiException('SYNC_CUSTOMER_PAYLOAD_INVALID', '고객명 또는 관리 상태가 올바르지 않습니다.', 422, ['index' => $index]);
        }
        if ($type === 'customer_phone') {
            $phone = (string) ($payload['normalized_phone'] ?? '');
            if (!isset($payload['customer_id']) || !Uuid::isValid((string) $payload['customer_id'])
                || preg_match('/^\+?[0-9]{7,15}$/', $phone) !== 1
                || !in_array(($payload['management_status'] ?? 'MANAGED'), ['MANAGED', 'EXCLUDED', 'BLOCKED'], true)
            ) {
                throw new ApiException('SYNC_PHONE_PAYLOAD_INVALID', '고객 ID와 정규화된 전화번호가 필요합니다.', 422, ['index' => $index]);
            }
        }
    }

    /** @param list<array<string, mixed>> $rows @return array<string, mixed> */
    private function page(array $rows, int $limit, int $after, string $sequenceField): array
    {
        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }
        $records = array_map(fn(array $row): array => $this->serialize($row), $rows);
        $lastSequence = $rows === [] ? $after : (int) $rows[array_key_last($rows)][$sequenceField];
        return [
            'records' => $records,
            'next_cursor' => CursorCodec::encode($lastSequence),
            'has_more' => $hasMore,
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function serialize(array $row): array
    {
        $payload = $row['payload'];
        return [
            'schema_version' => 'sync-record-v1',
            'company_id' => (string) $row['company_id'],
            'object_type' => (string) $row['object_type'],
            'object_id' => (string) $row['object_id'],
            'operation' => (string) $row['operation'],
            'version' => (int) $row['object_version'],
            'updated_at' => Time::api((string) $row['object_updated_at']),
            'deleted_at' => Time::api(isset($row['deleted_at']) ? (string) $row['deleted_at'] : null),
            'payload' => is_array($payload) ? $payload : [],
        ];
    }
}
