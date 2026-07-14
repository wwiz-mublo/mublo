<?php

namespace Mublo\Packages\Shop\EventSubscriber;

use Mublo\Core\Event\EventSubscriberInterface;
use Mublo\Packages\Shop\Event\OrderStatusChangedEvent;
use Mublo\Packages\Shop\Service\ShopConfigService;
use Mublo\Packages\Shop\Service\ActionTypeRegistry;
use Mublo\Infrastructure\Log\Logger;
use Mublo\Packages\Shop\Service\ActionExecutionService;

/**
 * ConfigurableActionSubscriber
 *
 * Config 기반 상태별 액션 실행 Subscriber
 *
 * OrderStatusChangedEvent를 구독하여 관리자가 설정한 액션(알림, 포인트, 웹훅 등)을 실행.
 * 코드 레벨 Subscriber(재고, PG, 환불)보다 후순위로 실행됨 (priority: -10).
 *
 * 핵심 원칙:
 * - 실패해도 주문 상태 변경을 롤백하지 않음
 * - 실패 시 로그만 기록
 */
class ConfigurableActionSubscriber implements EventSubscriberInterface
{
    private ShopConfigService $configService;
    private ActionTypeRegistry $actionRegistry;

    public function __construct(
        ShopConfigService $configService,
        ActionTypeRegistry $actionRegistry,
        private ?Logger $logger = null,
        private ?ActionExecutionService $executionService = null,
    )
    {
        $this->configService = $configService;
        $this->actionRegistry = $actionRegistry;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            OrderStatusChangedEvent::class => ['onStatusChanged', -10], // 코드 레벨보다 후순위
        ];
    }

    public function onStatusChanged(OrderStatusChangedEvent $event): void
    {
        $order = $event->getOrder();
        $domainId = (int) ($order['domain_id'] ?? 1);
        $stateId = $event->getNewStateId();

        $actions = $this->configService->getStateActions($domainId, $stateId);

        if (empty($actions)) {
            return;
        }

        foreach ($actions as $actionConfig) {
            // enabled 체크 (기본 true)
            if (!($actionConfig['enabled'] ?? true)) {
                continue;
            }

            $type = $actionConfig['type'] ?? '';

            if (!$this->actionRegistry->hasHandler($type)) {
                $this->logger?->warning("OrderStateAction: 미등록 액션 타입 '{$type}'", [
                    'order_no' => $event->getOrderNo(),
                    'state_id' => $stateId,
                ]);
                continue;
            }

            try {
                if ($this->executionService !== null) {
                    $this->executionService->dispatch($actionConfig, $event);
                } else {
                    $handler = $this->actionRegistry->getHandler($type);
                    $handler->execute($actionConfig, $event);
                }
            } catch (\Throwable $e) {
                // 실패해도 주문 흐름에 영향 없음 — 로그만 기록
                $this->logger?->warning('OrderStateAction 실행 실패', [
                    'order_no' => $event->getOrderNo(),
                    'state_id' => $stateId,
                    'action_type' => $type,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
