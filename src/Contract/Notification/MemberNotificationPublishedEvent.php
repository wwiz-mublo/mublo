<?php
declare(strict_types=1);

namespace Mublo\Contract\Notification;

use Mublo\Core\Event\AbstractEvent;

/**
 * 회원 알림이 저장된 뒤 발생한다.
 *
 * Push/메일 확장은 이 이벤트를 구독해 선택적으로 외부 채널로 전달할 수 있다.
 * Core는 Firebase나 특정 모바일 앱을 전제하지 않는다.
 */
final class MemberNotificationPublishedEvent extends AbstractEvent
{
    public function __construct(
        public readonly int $notificationId,
        public readonly MemberNotification $notification,
    ) {}
}
