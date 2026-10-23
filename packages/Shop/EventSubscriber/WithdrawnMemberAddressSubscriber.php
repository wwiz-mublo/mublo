<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\EventSubscriber;

use Mublo\Core\Event\EventSubscriberInterface;
use Mublo\Infrastructure\Log\Logger;
use Mublo\Packages\Shop\Repository\MemberAddressRepository;
use Mublo\Service\Member\Event\MemberWithdrawnEvent;

/**
 * 탈퇴한 회원의 배송지 주소록을 지운다.
 *
 * 탈퇴는 회원 행을 남기는 소프트 삭제라, 주소록의 FK CASCADE 는 하드 삭제 때만 동작한다.
 * 그대로 두면 탈퇴 뒤에도 수령인·연락처·주소가 남는다. 주문에 적힌 배송지는 거래 기록이라
 * 여기서 지우지 않는다 — 주소록은 다음 주문을 위한 편의 데이터일 뿐이다.
 *
 * 탈퇴는 이미 커밋된 뒤이므로 실패해도 되돌리지 않고 로그로 후속 조치를 남긴다.
 */
class WithdrawnMemberAddressSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private MemberAddressRepository $addresses,
        private Logger $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            MemberWithdrawnEvent::class => 'onMemberWithdrawn',
        ];
    }

    public function onMemberWithdrawn(MemberWithdrawnEvent $event): void
    {
        try {
            $this->addresses->deleteByMember($event->getMemberId());
        } catch (\Throwable $e) {
            $this->logger->exception($e, context: [
                'member_id' => $event->getMemberId(),
                'action' => 'delete_withdrawn_member_addresses',
            ]);
        }
    }
}
