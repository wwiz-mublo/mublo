<?php

namespace Mublo\Packages\Shop\Action;

use Mublo\Packages\Shop\Event\OrderItemStatusChangedEvent;

/**
 * 주문상품 상태 변경에도 적용할 수 있는 설정형 Action의 선택 인터페이스.
 */
interface ItemActionHandlerInterface
{
    public function executeForItem(array $config, OrderItemStatusChangedEvent $event): void;
}
