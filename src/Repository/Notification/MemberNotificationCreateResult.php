<?php
declare(strict_types=1);

namespace Mublo\Repository\Notification;

final readonly class MemberNotificationCreateResult
{
    public function __construct(
        public int $notificationId,
        public bool $created,
    ) {
        if ($notificationId < 1) {
            throw new \InvalidArgumentException('notificationId는 1 이상이어야 합니다.');
        }
    }
}
