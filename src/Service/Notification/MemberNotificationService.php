<?php
declare(strict_types=1);

namespace Mublo\Service\Notification;

use Mublo\Contract\Notification\MemberNotification;
use Mublo\Contract\Notification\MemberNotificationPublishedEvent;
use Mublo\Contract\Notification\MemberNotificationPublisherInterface;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Entity\Member\Member;
use Mublo\Repository\Member\MemberRepository;
use Mublo\Repository\Notification\MemberNotificationRepository;

class MemberNotificationService implements MemberNotificationPublisherInterface
{
    public function __construct(
        private MemberNotificationRepository $notifications,
        private MemberRepository $members,
        private EventDispatcher $events,
    ) {}

    public function publish(MemberNotification $notification): int
    {
        $recipient = $this->members->find($notification->memberId);
        if (!$recipient instanceof Member || $recipient->getDomainId() !== $notification->domainId) {
            throw new \InvalidArgumentException('현재 도메인에 속한 알림 수신 회원을 찾을 수 없습니다.');
        }

        if ($notification->actorMemberId !== null) {
            $actor = $this->members->find($notification->actorMemberId);
            if (!$actor instanceof Member) {
                throw new \InvalidArgumentException('알림 행위자 회원을 찾을 수 없습니다.');
            }
        }

        if ($notification->deduplicationKey !== null) {
            $existing = $this->notifications->findByDeduplicationKey(
                $notification->domainId,
                $notification->memberId,
                $notification->deduplicationKey
            );
            if ($existing !== null) {
                return (int) $existing['notification_id'];
            }
        }

        $created = $this->notifications->create($notification);
        if ($created->created) {
            $this->events->dispatch(new MemberNotificationPublishedEvent($created->notificationId, $notification));
        }

        return $created->notificationId;
    }

    public function paginate(int $domainId, int $memberId, int $page = 1, int $perPage = 20): array
    {
        return $this->notifications->paginate($domainId, $memberId, $page, $perPage);
    }

    public function unreadCount(int $domainId, int $memberId): int
    {
        return $this->notifications->unreadCount($domainId, $memberId);
    }

    public function markRead(int $domainId, int $memberId, int $notificationId): bool
    {
        return $this->notifications->markRead($domainId, $memberId, $notificationId);
    }

    public function open(int $domainId, int $memberId, int $notificationId): ?string
    {
        $notification = $this->notifications->findForMember($domainId, $memberId, $notificationId);
        if ($notification === null) {
            return null;
        }

        $this->notifications->markRead($domainId, $memberId, $notificationId);
        $target = $notification['target_url'] ?? null;
        return is_string($target)
            && str_starts_with($target, '/')
            && !str_starts_with($target, '//')
            && !str_contains($target, '\\')
                ? $target
                : null;
    }

    public function markAllRead(int $domainId, int $memberId): int
    {
        return $this->notifications->markAllRead($domainId, $memberId);
    }
}
