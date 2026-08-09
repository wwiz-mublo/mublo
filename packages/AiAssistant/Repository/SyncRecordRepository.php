<?php
declare(strict_types=1);

namespace Mublo\Packages\AiAssistant\Repository;

use Mublo\Contract\Security\SensitiveValueCodecInterface;
use Mublo\Infrastructure\Database\Database;
use Mublo\Packages\AiAssistant\Exception\ApiException;
use Mublo\Packages\AiAssistant\Support\CanonicalJson;
use Mublo\Packages\AiAssistant\Support\Time;

final class SyncRecordRepository
{
    public function __construct(
        private Database $db,
        private SensitiveValueCodecInterface $codec
    )
    {
    }

    /** @return array<string, mixed>|null */
    public function findCurrent(string $companyId, string $objectType, string $objectId): ?array
    {
        $row = $this->db->selectOne(
            'SELECT * FROM ai_sync_records
              WHERE company_id = ? AND object_type = ? AND object_id = ?
              LIMIT 1',
            [$companyId, $objectType, $objectId]
        );
        return $row === null ? null : $this->withDecodedPayload($row);
    }

    /**
     * @param array<string, mixed> $record
     * @return int new change sequence
     */
    public function apply(
        string $companyId,
        ?string $deviceId,
        array $record,
        ?array $expected,
        ?callable $afterApply = null
    ): int
    {
        return $this->db->transaction(function () use ($companyId, $deviceId, $record, $expected, $afterApply): int {
            $now = Time::database();
            $payloadJson = $this->codec->encrypt(CanonicalJson::encode($record['payload']));
            $searchToken = $record['object_type'] === 'customer_phone' && $record['operation'] === 'UPSERT'
                ? $this->codec->createSearchIndex((string) ($record['payload']['normalized_phone'] ?? ''))
                : null;
            $objectUpdatedAt = self::databaseTime((string) $record['updated_at']);
            $deletedAt = isset($record['deleted_at']) && $record['deleted_at'] !== null
                ? self::databaseTime((string) $record['deleted_at'])
                : null;

            $sequence = $this->db->insert(
                'INSERT INTO ai_sync_changes
                    (company_id, object_type, object_id, operation, object_version, payload_json,
                     object_updated_at, deleted_at, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $companyId,
                    $record['object_type'],
                    $record['object_id'],
                    $record['operation'],
                    $record['version'],
                    $payloadJson,
                    $objectUpdatedAt,
                    $deletedAt,
                    $now,
                ]
            );

            if ($expected === null) {
                $this->db->insert(
                    'INSERT INTO ai_sync_records
                        (company_id, object_type, object_id, operation, object_version, payload_json, search_token,
                         source_device_id, object_updated_at, deleted_at, change_sequence, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $companyId,
                        $record['object_type'],
                        $record['object_id'],
                        $record['operation'],
                        $record['version'],
                        $payloadJson,
                        $searchToken,
                        $deviceId,
                        $objectUpdatedAt,
                        $deletedAt,
                        $sequence,
                        $now,
                        $now,
                    ]
                );
                if ($afterApply !== null) {
                    $afterApply();
                }
                return $sequence;
            }

            $updated = $this->db->execute(
                'UPDATE ai_sync_records
                    SET operation = ?, object_version = ?, payload_json = ?, search_token = ?, source_device_id = ?,
                        object_updated_at = ?, deleted_at = ?, change_sequence = ?, updated_at = ?
                  WHERE company_id = ? AND object_type = ? AND object_id = ?
                    AND object_version = ? AND operation = ?',
                [
                    $record['operation'],
                    $record['version'],
                    $payloadJson,
                    $searchToken,
                    $deviceId,
                    $objectUpdatedAt,
                    $deletedAt,
                    $sequence,
                    $now,
                    $companyId,
                    $record['object_type'],
                    $record['object_id'],
                    $expected['object_version'],
                    $expected['operation'],
                ]
            );
            if ($updated !== 1) {
                throw new ApiException('SYNC_CONCURRENT_UPDATE', '동시에 변경된 데이터가 있어 다시 동기화해야 합니다.', 409);
            }
            if ($afterApply !== null) {
                $afterApply();
            }
            return $sequence;
        });
    }

    /** @return list<array<string, mixed>> */
    public function bootstrap(string $companyId, int $afterSequence, int $limit): array
    {
        return array_map($this->withDecodedPayload(...), $this->db->select(
            'SELECT * FROM ai_sync_records
              WHERE company_id = ? AND change_sequence > ?
              ORDER BY change_sequence ASC
              LIMIT ' . ($limit + 1),
            [$companyId, $afterSequence]
        ));
    }

    /** @return list<array<string, mixed>> */
    public function changesAfter(string $companyId, int $sequence, int $limit): array
    {
        return array_map($this->withDecodedPayload(...), $this->db->select(
            'SELECT * FROM ai_sync_changes
              WHERE company_id = ? AND sequence_id > ?
              ORDER BY sequence_id ASC
              LIMIT ' . ($limit + 1),
            [$companyId, $sequence]
        ));
    }

    public function maxSequence(string $companyId): int
    {
        $row = $this->db->selectOne(
            'SELECT MAX(sequence_id) AS max_sequence FROM ai_sync_changes WHERE company_id = ?',
            [$companyId]
        );
        return (int) ($row['max_sequence'] ?? 0);
    }

    private static function databaseTime(string $value): string
    {
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            throw new ApiException('SYNC_TIME_INVALID', '동기화 시각 형식이 올바르지 않습니다.', 422);
        }
        return Time::database($timestamp);
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function withDecodedPayload(array $row): array
    {
        $plain = $this->codec->decrypt((string) $row['payload_json']);
        if ($plain === null) {
            throw new \RuntimeException('Unable to decrypt sync payload');
        }
        $payload = json_decode($plain, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new \RuntimeException('Sync payload must be an object');
        }
        $row['payload'] = $payload;
        return $row;
    }
}
