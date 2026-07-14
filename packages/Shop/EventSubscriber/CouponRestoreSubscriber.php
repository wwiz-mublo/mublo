<?php

namespace Mublo\Packages\Shop\EventSubscriber;

use Mublo\Core\Event\EventSubscriberInterface;
use Mublo\Packages\Shop\Enum\OrderAction;
use Mublo\Packages\Shop\Event\OrderStatusChangedEvent;
use Mublo\Packages\Shop\Service\CouponService;

/**
 * 주문 취소/환불 시 사용된 쿠폰 자동 복원
 */
class CouponRestoreSubscriber implements EventSubscriberInterface
{
    private CouponService $couponService;

    public function __construct(CouponService $couponService)
    {
        $this->couponService = $couponService;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            OrderStatusChangedEvent::class => 'onOrderStatusChanged',
        ];
    }

    public function onOrderStatusChanged(OrderStatusChangedEvent $event): void
    {
        $newAction = $event->getNewAction();

        // 취소 또는 환불 완료 시에만 쿠폰 복원
        if ($newAction !== OrderAction::CANCELLED && $newAction !== OrderAction::RETURNED) {
            return;
        }

        $this->couponService->restoreOrderCoupons($event->getOrderNo());
    }
}
