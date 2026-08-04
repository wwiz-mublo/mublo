<?php
declare(strict_types=1);

namespace Mublo\Repository\Notification;

use Mublo\Contract\Notification\MemberNotification;
use Mublo\Infrastructure\Database\Database;
use Mublo\Infrastructure\Database\DatabaseException;

class MemberNotificationRepository
{
    public function __construct(private Database $db) {}

    public function create(MemberNotification $notification): MemberNotificationCreateResult
    {
        try {
            $notificationId = $this->db->insert(
                'INSERT INTO member_notifications
                    (domain_id, member_id, actor_member_id, type, title, body, target_url,
                     source_type, source_id, deduplication_key, metadata_json, expires_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $notification->domainId,
                    $notification->memberId,
                    $notification->actorMemberId,
                    $notification->type,
                    $notification->title,
                    $notification->body,
                    $notification->targetUrl,
                    $notification->source,
                    $notification->sourceId,
                    $notification->deduplicationKey,
                    json_encode($notification->metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    $notification->expiresAt?->format('Y-m-d H:i:s'),
                ]
            );

            return new MemberNotificationCreateResult($notificationId, true);
        } catch (DatabaseException $e) {
            if ($notification->deduplicationKey === null || !$this->isDuplicateKey($e)) {
                throw $e;
            }

            // 두 요청이 사전 조회를 동시에 통과한 경합 경로. UNIQUE 제약이
            // 승자를 결정했으므로 기존 행을 반환하고 부수효과는 재발행하지 않는다.
            $existing = $this->findByDeduplicationKeyForUpdate(
                $notification->domainId,
                $notification->memberId,
                $notification->deduplicationKey
            );
            $notificationId = (int) ($existing['notification_id'] ?? 0);
            if ($notificationId < 1) {
                throw $e;
            }

            return new MemberNotificationCreateResult($notificationId, false);
        }
    }

    private function isDuplicateKey(DatabaseException $exception): bool
    {
        $previous = $exception->getPrevious();
        if ($previous instanceof \PDOException) {
            $driverCode = $previous->errorInfo[1] ?? null;
            if ((int) $driverCode === 1062) {
                return true;
            }
        }

        return str_contains($exception->getMessage(), 'SQLSTATE[23000]')
            && str_contains($exception->getMessage(), '1062');
    }

    public function findByDeduplicationKey(int $domainId, int $memberId, string $key): ?array
    {
        return $this->db->selectOne(
            'SELECT notification_id FROM member_notifications
             WHERE domain_id = ? AND member_id = ? AND deduplication_key = ? LIMIT 1',
            [$domainId, $memberId, $key]
        );
    }

    /**
     * duplicate-key 경합 직후에는 현재 트랜잭션의 이전 consistent-read
     * snapshot이 승자 행을 가릴 수 있다. locking read로 최신 커밋 행을 읽는다.
     */
    private function findByDeduplicationKeyForUpdate(int $domainId, int $memberId, string $key): ?array
    {
        return $this->db->selectOne(
            'SELECT notification_id FROM member_notifications
             WHERE domain_id = ? AND member_id = ? AND deduplication_key = ? LIMIT 1
             FOR UPDATE',
            [$domainId, $memberId, $key]
        );
    }

    public function paginate(int $domainId, int $memberId, int $page = 1, int $perPage = 20): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;
        $params = [$domainId, $memberId];
        $where = 'domain_id = ? AND member_id = ? AND (expires_at IS NULL OR expires_at > NOW())';
        $count = $this->db->selectOne("SELECT COUNT(*) AS total FROM member_notifications WHERE {$where}", $params);
        $rows = $this->db->select(
            "SELECT * FROM member_notifications WHERE {$where}
             ORDER BY created_at DESC, notification_id DESC LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        foreach ($rows as &$row) {
            $metadata = json_decode((string) ($row['metadata_json'] ?? ''), true);
            $row['metadata'] = is_array($metadata) ? $metadata : [];
            unset($row['metadata_json']);
        }
        unset($row);

        $total = (int) ($count['total'] ?? 0);
        return [
            'items' => $rows,
            'pagination' => [
                'currentPage' => $page,
                'perPage' => $perPage,
                'totalItems' => $total,
                'totalPages' => (int) ceil($total / $perPage),
            ],
        ];
    }

    public function unreadCount(int $domainId, int $memberId): int
    {
        $row = $this->db->selectOne(
            'SELECT COUNT(*) AS total FROM member_notifications
             WHERE domain_id = ? AND member_id = ? AND read_at IS NULL
               AND (expires_at IS NULL OR expires_at > NOW())',
            [$domainId, $memberId]
        );
        return (int) ($row['total'] ?? 0);
    }

    public function findForMember(int $domainId, int $memberId, int $notificationId): ?array
    {
        return $this->db->selectOne(
            'SELECT notification_id, target_url, read_at FROM member_notifications
             WHERE notification_id = ? AND domain_id = ? AND member_id = ?
               AND (expires_at IS NULL OR expires_at > NOW()) LIMIT 1',
            [$notificationId, $domainId, $memberId]
        );
    }

    public function markRead(int $domainId, int $memberId, int $notificationId): bool
    {
        return $this->db->execute(
            'UPDATE member_notifications SET read_at = COALESCE(read_at, NOW())
             WHERE notification_id = ? AND domain_id = ? AND member_id = ?',
            [$notificationId, $domainId, $memberId]
        ) > 0;
    }

    public function markAllRead(int $domainId, int $memberId): int
    {
        return $this->db->execute(
            'UPDATE member_notifications SET read_at = NOW()
             WHERE domain_id = ? AND member_id = ? AND read_at IS NULL
               AND (expires_at IS NULL OR expires_at > NOW())',
            [$domainId, $memberId]
        );
    }
}
