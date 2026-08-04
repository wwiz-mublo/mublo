<?php
declare(strict_types=1);

namespace Mublo\Contract\Notification;

/**
 * Package/Plugin이 Core 회원 알림 센터에 알림을 발행하는 공개 계약.
 *
 * 이메일·SMS·알림톡 같은 외부 전송은 NotificationGatewayInterface의 책임이며,
 * 이 계약은 사이트 안의 종 아이콘·읽음 상태·알림 목록만 책임진다.
 */
interface MemberNotificationPublisherInterface
{
    /**
     * 알림을 저장하고 알림 ID를 반환한다.
     * 같은 도메인·회원·deduplicationKey 조합은 기존 알림 ID를 반환한다.
     */
    public function publish(MemberNotification $notification): int;
}
