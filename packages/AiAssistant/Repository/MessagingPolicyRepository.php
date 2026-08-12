<?php
declare(strict_types=1);

namespace Mublo\Packages\AiAssistant\Repository;

use Mublo\Infrastructure\Database\Database;
use Mublo\Infrastructure\Database\DatabaseException;
use Mublo\Packages\AiAssistant\Exception\ApiException;
use Mublo\Packages\AiAssistant\Support\Time;
use Mublo\Packages\AiAssistant\Support\Uuid;

final class MessagingPolicyRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function findPermission(string $companyId, string $phoneId, string $channel, string $purpose): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM ai_contact_permissions
              WHERE company_id = ? AND customer_phone_id = ? AND channel = ? AND purpose = ? LIMIT 1',
            [$companyId, $phoneId, $channel, $purpose]
        );
    }

    /** @param array<string, mixed> $permission @return array<string, mixed> */
    public function save(string $companyId, array $permission): array
    {
        $existing = $this->findPermission(
            $companyId,
            (string) $permission['customer_phone_id'],
            (string) $permission['channel'],
            (string) $permission['purpose']
        );
        if ($existing !== null && (int) $permission['version'] <= (int) $existing['permission_version']) {
            throw new ApiException(
                'PERMISSION_VERSION_CONFLICT',
                '동의 상태 버전이 서버 버전보다 커야 합니다.',
                409,
                ['server_version' => (int) $existing['permission_version']]
            );
        }
        if ($existing !== null && isset($permission['permission_id'])
            && !hash_equals((string) $existing['permission_id'], (string) $permission['permission_id'])
        ) {
            throw new ApiException(
                'PERMISSION_SCOPE_MISMATCH',
                '기존 동의 상태와 permission ID가 일치하지 않습니다.',
                409
            );
        }
        $now = Time::database();
        $capturedAt = self::databaseTime((string) $permission['captured_at']);
        $expiresAt = $permission['expires_at'] === null
            ? null
            : self::databaseTime((string) $permission['expires_at']);
        if ($existing === null) {
            $permissionId = isset($permission['permission_id'])
                ? (string) $permission['permission_id']
                : Uuid::v4();
            $this->db->insert(
                'INSERT INTO ai_contact_permissions
                    (permission_id, company_id, customer_phone_id, channel, purpose, status,
                     legal_basis, captured_at, source, expires_at, permission_version, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $permissionId, $companyId, $permission['customer_phone_id'], $permission['channel'],
                    $permission['purpose'], $permission['status'], $permission['legal_basis'], $capturedAt,
                    $permission['source'], $expiresAt, $permission['version'], $now, $now,
                ]
            );
        } else {
            $permissionId = (string) $existing['permission_id'];
            $updated = $this->db->execute(
                'UPDATE ai_contact_permissions
                    SET status = ?, legal_basis = ?, captured_at = ?, source = ?, expires_at = ?,
                        permission_version = ?, updated_at = ?
                  WHERE company_id = ? AND permission_id = ? AND permission_version < ?',
                [
                    $permission['status'], $permission['legal_basis'], $capturedAt, $permission['source'],
                    $expiresAt, $permission['version'], $now, $companyId, $permissionId, $permission['version'],
                ]
            );
            if ($updated !== 1) {
                $raced = $this->findPermission(
                    $companyId,
                    (string) $permission['customer_phone_id'],
                    (string) $permission['channel'],
                    (string) $permission['purpose']
                );
                throw new ApiException(
                    'PERMISSION_VERSION_CONFLICT',
                    '동의 상태 version이 동시에 변경되었습니다.',
                    409,
                    ['server_version' => (int) ($raced['permission_version'] ?? 0)]
                );
            }
        }
        return [
            'permission_id' => $permissionId,
            'customer_phone_id' => $permission['customer_phone_id'],
            'channel' => $permission['channel'],
            'purpose' => $permission['purpose'],
            'status' => $permission['status'],
            'version' => $permission['version'],
            'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
    }

    public function isSuppressed(string $companyId, string $lookupToken, string $channel): bool
    {
        $row = $this->findSuppression($companyId, $lookupToken, $channel);
        return $row !== null && $row['lifted_at'] === null;
    }

    /** @return array<string, mixed>|null */
    public function findSuppression(string $companyId, string $lookupToken, string $channel): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM ai_suppression_entries
              WHERE company_id = ? AND phone_lookup_token = ? AND channel = ? LIMIT 1',
            [$companyId, $lookupToken, $channel]
        );
    }

    /** @param array<string, mixed> $phone @param array<string, mixed> $event @return array<string, mixed> */
    public function appendSuppressionEvent(string $companyId, array $phone, array $event): array
    {
        $lookupToken = (string) $phone['phone_lookup_token'];
        $occurredAt = self::databaseTime((string) $event['occurred_at']);
        $now = Time::database();
        try {
            $this->db->transaction(function () use ($companyId, $event, $lookupToken, $occurredAt, $now): void {
                $this->db->insert(
                    'INSERT INTO ai_suppression_events
                        (event_id, company_id, customer_phone_id, phone_lookup_token, channel, action,
                         reason, source, occurred_at, suppression_version, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $event['event_id'], $companyId, $event['customer_phone_id'], $lookupToken,
                        $event['channel'], $event['action'], $event['reason'], $event['source'],
                        $occurredAt, $event['version'], $now,
                    ]
                );
                $existing = $this->findSuppression($companyId, $lookupToken, (string) $event['channel']);
                $liftedAt = $event['action'] === 'LIFT' ? $occurredAt : null;
                if ($existing === null) {
                    $this->db->insert(
                        'INSERT INTO ai_suppression_entries
                            (suppression_id, company_id, customer_phone_id, phone_lookup_token, channel,
                             reason, source, suppression_version, created_at, lifted_at, updated_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                        [
                            Uuid::v4(), $companyId, $event['customer_phone_id'], $lookupToken,
                            $event['channel'], $event['reason'], $event['source'], $event['version'],
                            $occurredAt, $liftedAt, $now,
                        ]
                    );
                    return;
                }
                $updated = $this->db->execute(
                    'UPDATE ai_suppression_entries
                        SET customer_phone_id = ?, reason = ?, source = ?, suppression_version = ?,
                            lifted_at = ?, updated_at = ?
                      WHERE company_id = ? AND phone_lookup_token = ? AND channel = ?
                        AND suppression_version < ?',
                    [
                        $event['customer_phone_id'], $event['reason'], $event['source'], $event['version'],
                        $liftedAt, $now, $companyId, $lookupToken, $event['channel'], $event['version'],
                    ]
                );
                if ($updated !== 1) {
                    throw new \RuntimeException('Suppression projection version changed concurrently');
                }
            });
        } catch (DatabaseException $exception) {
            $current = $this->findSuppression($companyId, $lookupToken, (string) $event['channel']);
            if ($current !== null && (int) $current['suppression_version'] >= (int) $event['version']) {
                throw new ApiException(
                    'SUPPRESSION_VERSION_CONFLICT',
                    '수신거부 이벤트 version이 동시에 변경되었습니다.',
                    409,
                    ['server_version' => (int) $current['suppression_version']]
                );
            }
            throw $exception;
        }

        return [
            'event_id' => $event['event_id'],
            'customer_phone_id' => $event['customer_phone_id'],
            'channel' => $event['channel'],
            'action' => $event['action'],
            'reason' => $event['reason'],
            'version' => $event['version'],
            'suppressed' => $event['action'] === 'SUPPRESS',
            'occurred_at' => gmdate('Y-m-d\TH:i:s\Z', (int) strtotime((string) $event['occurred_at'])),
        ];
    }

    public function hasCampaignSnapshot(string $companyId, string $campaignId): bool
    {
        return $this->db->selectOne(
            'SELECT snapshot_id FROM ai_campaign_recipient_snapshots
              WHERE company_id = ? AND campaign_id = ? LIMIT 1',
            [$companyId, $campaignId]
        ) !== null;
    }

    /**
     * @param array<string, mixed> $input
     * @param list<array<string, mixed>> $decisions
     */
    public function createCampaignSnapshot(
        string $companyId,
        string $snapshotBatchId,
        array $input,
        array $decisions
    ): void {
        $now = Time::database();
        try {
            $this->db->transaction(function () use ($companyId, $snapshotBatchId, $input, $decisions, $now): void {
                foreach ($decisions as $decision) {
                    $this->db->insert(
                        'INSERT INTO ai_campaign_recipient_snapshots
                            (snapshot_id, snapshot_batch_id, campaign_id, company_id, customer_id,
                             customer_phone_id, channel, message_class, content_version, eligible,
                             reason_codes_json, permission_version, suppression_version, policy_checked_at, created_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                        [
                            Uuid::v4(), $snapshotBatchId, $input['campaign_id'], $companyId,
                            $decision['customer_id'], $decision['customer_phone_id'], $input['channel'],
                            $input['message_class'], $input['content_version'], $decision['eligible'] ? 1 : 0,
                            json_encode($decision['reasons'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                            $decision['permission_version'], $decision['suppression_version'], $now, $now,
                        ]
                    );
                }
            });
        } catch (DatabaseException $exception) {
            if ($this->hasCampaignSnapshot($companyId, (string) $input['campaign_id'])) {
                throw new ApiException('CAMPAIGN_SNAPSHOT_IMMUTABLE', '이미 생성된 캠페인 snapshot은 변경할 수 없습니다.', 409);
            }
            throw $exception;
        }
    }

    private static function databaseTime(string $value): string
    {
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            throw new ApiException('PERMISSION_TIME_INVALID', '동의 시각 형식이 올바르지 않습니다.', 422);
        }
        return Time::database($timestamp);
    }
}
