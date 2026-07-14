<?php

namespace Mublo\Packages\Shop\EventSubscriber;

use Mublo\Core\Event\EventSubscriberInterface;
use Mublo\Contract\Notification\CollectNotificationVariablesEvent;

/**
 * Shop 패키지 알림 변수 등록
 *
 * AligoMessage 관리자 UI에서 사용 가능한 치환 변수를 광고한다.
 * NotificationActionHandler.prepareFieldValues()와 동일한 필드 목록.
 */
class NotificationVariableSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            CollectNotificationVariablesEvent::class => 'onCollect',
        ];
    }

    public function onCollect(CollectNotificationVariablesEvent $event): void
    {
        $event->addVariables('shop', '쇼핑몰', [
            'orderer_name'  => '주문인명',
            'orderer_phone' => '주문인 전화번호',
            'order_no'      => '주문번호',
            'order_date'    => '주문일시',
            'total_amount'  => '총 결제금액',
            'status_label'  => '현재 상태',
            'prev_status'   => '이전 상태',
            'device_name'   => '상품명',
            // 배송 정보 (송장 등록된 주문에서만 채워짐 — 배송 시작 알림용)
            'courier'       => '택배사',
            'invoice_no'    => '송장번호',
            'tracking_url'  => '배송 추적 URL',
        ]);
    }
}
