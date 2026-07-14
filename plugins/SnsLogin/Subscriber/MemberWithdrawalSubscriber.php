<?php
namespace Mublo\Plugin\SnsLogin\Subscriber;

use Mublo\Core\Event\EventSubscriberInterface;
use Mublo\Infrastructure\Log\Logger;
use Mublo\Plugin\SnsLogin\Service\SnsConnectionManager;
use Mublo\Service\Member\Event\MemberWithdrawingEvent;
use Mublo\Service\Member\Event\MemberWithdrawnEvent;

/**
 * 코어 탈퇴 확장점만 사용해 외부 SNS 연결과 플러그인 소유 데이터를 정리한다.
 */
class MemberWithdrawalSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private SnsConnectionManager $connections,
        private Logger $logger,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            // 정산 등 다른 탈퇴 차단 구독자가 먼저 판단한 뒤 외부 연결을 끊는다.
            MemberWithdrawingEvent::class => ['onMemberWithdrawing', -1000],
            MemberWithdrawnEvent::class => 'onMemberWithdrawn',
        ];
    }

    public function onMemberWithdrawing(MemberWithdrawingEvent $event): void
    {
        if ($event->isBlocked()) {
            return;
        }

        $result = $this->connections->revokeAllForMember($event->getMemberId());
        if ($result->isFailure()) {
            $event->setBlocked(true, $result->getMessage());
        }
    }

    public function onMemberWithdrawn(MemberWithdrawnEvent $event): void
    {
        try {
            $deleted = $this->connections->deleteLocalConnections($event->getMemberId());
            if ($deleted > 0) {
                $this->logger->info('탈퇴 회원 SNS 연결 정보 삭제 완료', [
                    'member_id' => $event->getMemberId(),
                    'deleted' => $deleted,
                ]);
            }
        } catch (\Throwable $e) {
            // 탈퇴는 이미 커밋되었으므로 되돌리지 않고 운영 로그로 후속 조치를 남긴다.
            $this->logger->exception($e, context: [
                'member_id' => $event->getMemberId(),
                'action' => 'delete_local_connections',
            ]);
        }
    }
}
