<?php

namespace Mublo\Packages\Shop\EventSubscriber;

use Mublo\Core\Event\EventSubscriberInterface;
use Mublo\Infrastructure\Log\Logger;
use Mublo\Packages\Shop\Action\ItemActionHandlerInterface;
use Mublo\Packages\Shop\Event\OrderItemStatusChangedEvent;
use Mublo\Packages\Shop\Service\ActionTypeRegistry;
use Mublo\Packages\Shop\Service\ShopConfigService;

/**
 * 부분 취소·부분 반품처럼 주문 헤더 상태가 바뀌지 않는 상품 상태 변경에서
 * 상품 단위 실행을 명시적으로 지원하는 Action만 수행한다.
 */
class ConfigurableItemActionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private ShopConfigService $configService,
        private ActionTypeRegistry $actionRegistry,
        private ?Logger $logger = null,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [OrderItemStatusChangedEvent::class => ['onStatusChanged', -10]];
    }

    public function onStatusChanged(OrderItemStatusChangedEvent $event): void
    {
        $domainId = (int) ($event->getOrder()['domain_id'] ?? 0);
        if ($domainId <= 0) {
            return;
        }

        foreach ($this->configService->getStateActions($domainId, $event->getNewStateId()) as $config) {
            if (!($config['enabled'] ?? true)) {
                continue;
            }

            $type = (string) ($config['type'] ?? '');
            if (!$this->actionRegistry->hasHandler($type)) {
                continue;
            }

            $handler = $this->actionRegistry->getHandler($type);
            if (!$handler instanceof ItemActionHandlerInterface) {
                continue;
            }

            try {
                $handler->executeForItem($config, $event);
            } catch (\Throwable $e) {
                $this->logger?->warning('OrderItemStateAction 실행 실패', [
                    'order_no' => $event->getOrderNo(),
                    'order_detail_id' => $event->getOrderDetailId(),
                    'state_id' => $event->getNewStateId(),
                    'action_type' => $type,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
