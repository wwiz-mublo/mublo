<?php
declare(strict_types=1);

namespace Mublo\Packages\AiAssistant\Repository;

use Mublo\Infrastructure\Database\Database;
use Mublo\Infrastructure\Database\DatabaseException;
use Mublo\Packages\AiAssistant\Exception\ApiException;
use Mublo\Packages\AiAssistant\Support\Time;
use Mublo\Packages\AiAssistant\Support\Uuid;

final class MessagingDispatchRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function findPolicy(string $companyId, string $campaignId): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM ai_messaging_campaign_policies
              WHERE company_id = ? AND campaign_id = ? LIMIT 1',
            [$companyId, $campaignId]
        );
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function savePolicy(string $companyId, array $input): array
    {
        $existing = $this->findPolicy($companyId, (string) $input['campaign_id']);
        if ($existing !== null && (int) $input['version'] <= (int) $existing['policy_version']) {
            throw new ApiException(
                'CAMPAIGN_POLICY_VERSION_CONFLICT',
                '캠페인 정책 version은 서버 version보다 커야 합니다.',
                409,
                ['server_version' => (int) $existing['policy_version']]
            );
        }
        $now = Time::database();
        if ($existing === null) {
            try {
                $this->db->insert(
                    'INSERT INTO ai_messaging_campaign_policies
                        (campaign_id, company_id, channel, message_class, content_version,
                         approved_content_version, timezone, quiet_hours_start, quiet_hours_end,
                         per_recipient_daily_limit, policy_version, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $input['campaign_id'], $companyId, $input['channel'], $input['message_class'],
                        $input['content_version'], $input['approved_content_version'], $input['timezone'],
                        $input['quiet_hours_start'], $input['quiet_hours_end'],
                        $input['per_recipient_daily_limit'], $input['version'], $now, $now,
                    ]
                );
            } catch (DatabaseException $exception) {
                $raced = $this->findPolicy($companyId, (string) $input['campaign_id']);
                if ($raced !== null) {
                    throw new ApiException(
                        'CAMPAIGN_POLICY_VERSION_CONFLICT',
                        '캠페인 정책이 동시에 생성되었습니다.',
                        409,
                        ['server_version' => (int) $raced['policy_version']]
                    );
                }
                throw $exception;
            }
        } else {
            $updated = $this->db->execute(
                'UPDATE ai_messaging_campaign_policies
                    SET channel = ?, message_class = ?, content_version = ?, approved_content_version = ?,
                        timezone = ?, quiet_hours_start = ?, quiet_hours_end = ?,
                        per_recipient_daily_limit = ?, policy_version = ?, updated_at = ?
                  WHERE company_id = ? AND campaign_id = ? AND policy_version < ?',
                [
                    $input['channel'], $input['message_class'], $input['content_version'],
                    $input['approved_content_version'], $input['timezone'], $input['quiet_hours_start'],
                    $input['quiet_hours_end'], $input['per_recipient_daily_limit'], $input['version'],
                    $now, $companyId, $input['campaign_id'], $input['version'],
                ]
            );
            if ($updated !== 1) {
                $raced = $this->findPolicy($companyId, (string) $input['campaign_id']);
                throw new ApiException(
                    'CAMPAIGN_POLICY_VERSION_CONFLICT',
                    '캠페인 정책 version이 동시에 변경되었습니다.',
                    409,
                    ['server_version' => (int) ($raced['policy_version'] ?? 0)]
                );
            }
        }
        return [
            'campaign_id' => $input['campaign_id'],
            'channel' => $input['channel'],
            'message_class' => $input['message_class'],
            'content_version' => $input['content_version'],
            'approved_content_version' => $input['approved_content_version'],
            'timezone' => $input['timezone'],
            'quiet_hours_start' => $input['quiet_hours_start'],
            'quiet_hours_end' => $input['quiet_hours_end'],
            'per_recipient_daily_limit' => $input['per_recipient_daily_limit'],
            'version' => $input['version'],
            'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function snapshotRows(string $companyId, string $campaignId, string $snapshotBatchId): array
    {
        return $this->db->select(
            'SELECT * FROM ai_campaign_recipient_snapshots
              WHERE company_id = ? AND campaign_id = ? AND snapshot_batch_id = ?
              ORDER BY created_at ASC, snapshot_id ASC',
            [$companyId, $campaignId, $snapshotBatchId]
        );
    }

    public function hasReservations(string $companyId, string $campaignId, int $contentVersion): bool
    {
        return $this->db->selectOne(
            'SELECT reservation_id FROM ai_messaging_dispatch_reservations
              WHERE company_id = ? AND campaign_id = ? AND content_version = ? LIMIT 1',
            [$companyId, $campaignId, $contentVersion]
        ) !== null;
    }

    public function readyCount(
        string $companyId,
        string $phoneId,
        string $channel,
        string $fromUtc,
        string $toUtc
    ): int {
        $row = $this->db->selectOne(
            "SELECT COUNT(*) AS ready_count FROM ai_messaging_dispatch_reservations
              WHERE company_id = ? AND customer_phone_id = ? AND channel = ? AND status = 'READY'
                AND evaluated_at >= ? AND evaluated_at < ?",
            [$companyId, $phoneId, $channel, $fromUtc, $toUtc]
        );
        return (int) ($row['ready_count'] ?? 0);
    }

    public function latestInteractionAt(string $companyId, string $customerId): ?string
    {
        $row = $this->db->selectOne(
            'SELECT MAX(occurred_at) AS latest_at FROM ai_interactions
              WHERE company_id = ? AND customer_id = ?',
            [$companyId, $customerId]
        );
        $value = $row['latest_at'] ?? null;
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @param list<array<string, mixed>> $decisions @return list<array<string, mixed>> */
    public function createReservations(
        string $companyId,
        string $preflightId,
        string $campaignId,
        string $snapshotBatchId,
        int $contentVersion,
        array $decisions,
        int $dailyLimit,
        string $dayStartUtc,
        string $dayEndUtc
    ): array {
        $now = Time::database();
        try {
            $decisions = $this->db->transaction(function () use (
                $companyId,
                $preflightId,
                $campaignId,
                $snapshotBatchId,
                $contentVersion,
                $decisions,
                $dailyLimit,
                $dayStartUtc,
                $dayEndUtc,
                $now
            ): array {
                foreach ($decisions as $index => $decision) {
                    $this->db->execute(
                        'UPDATE ai_customer_phones SET updated_at = updated_at
                          WHERE company_id = ? AND customer_phone_id = ?',
                        [$companyId, $decision['customer_phone_id']]
                    );
                    if ($decision['status'] === 'READY'
                        && $this->readyCount(
                            $companyId,
                            (string) $decision['customer_phone_id'],
                            (string) $decision['channel'],
                            $dayStartUtc,
                            $dayEndUtc
                        ) >= $dailyLimit
                    ) {
                        $decision['status'] = 'BLOCKED';
                        $decision['reasons'][] = 'DAILY_FREQUENCY_LIMIT';
                        $decision['reasons'] = array_values(array_unique($decision['reasons']));
                        $decisions[$index] = $decision;
                    }
                    $this->db->insert(
                        'INSERT INTO ai_messaging_dispatch_reservations
                            (reservation_id, preflight_id, company_id, campaign_id, snapshot_batch_id,
                             customer_id, customer_phone_id, channel, message_class, content_version,
                             status, reason_codes_json, permission_version, suppression_version,
                             evaluated_at, created_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                        [
                            Uuid::v4(), $preflightId, $companyId, $campaignId, $snapshotBatchId,
                            $decision['customer_id'], $decision['customer_phone_id'], $decision['channel'],
                            $decision['message_class'], $contentVersion, $decision['status'],
                            json_encode($decision['reasons'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                            $decision['permission_version'], $decision['suppression_version'], $now, $now,
                        ]
                    );
                }
                return $decisions;
            });
        } catch (DatabaseException $exception) {
            if ($this->hasReservations($companyId, $campaignId, $contentVersion)) {
                throw new ApiException(
                    'DISPATCH_PREFLIGHT_ALREADY_EXISTS',
                    '같은 캠페인과 본문 version의 dispatch preflight가 이미 존재합니다.',
                    409
                );
            }
            throw $exception;
        }
        return $decisions;
    }
}
