<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\EventSubscriber;

use Mublo\Core\Event\EventSubscriberInterface;
use Mublo\Packages\Shop\Enum\OrderAction;
use Mublo\Packages\Shop\Event\OrderStatusChangedEvent;
use Mublo\Packages\Shop\Service\OrderPointService;

/**
 * 포인트 사용(결제) 차감/복원 구독자
 *
 * 주문 취소/반품(CANCELLED/RETURNED) 시 사용 포인트를 복원한다.
 * 주문 생성 시 선점하며, 결제 완료는 PaymentCompletionService가 선점 이력을 확인한다.
 */
class PointPaymentSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private OrderPointService $orderPointService,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            OrderStatusChangedEvent::class => 'onOrderStatusChanged',
        ];
    }

    /**
     * 주문 취소/반품 완료 → 사용 포인트 복원
     */
    public function onOrderStatusChanged(OrderStatusChangedEvent $event): void
    {
        $newAction = $event->getNewAction();
        if ($newAction !== OrderAction::CANCELLED && $newAction !== OrderAction::RETURNED) {
            return;
        }

        $this->orderPointService->release($event->getOrder());
    }
}
