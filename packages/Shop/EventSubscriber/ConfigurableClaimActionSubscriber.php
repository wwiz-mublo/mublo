<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\EventSubscriber;

use Mublo\Core\Event\EventSubscriberInterface;
use Mublo\Infrastructure\Log\Logger;
use Mublo\Packages\Shop\Enum\ClaimStatus;
use Mublo\Packages\Shop\Event\ClaimStatusChangedEvent;
use Mublo\Packages\Shop\Event\OrderStatusChangedEvent;
use Mublo\Packages\Shop\Repository\OrderRepository;
use Mublo\Packages\Shop\Service\ActionExecutionService;
use Mublo\Packages\Shop\Service\ActionTypeRegistry;
use Mublo\Packages\Shop\Service\ShopConfigService;
use Mublo\Contract\Security\SensitiveValueCodecInterface;

/** 교환 전용 Action. 주문 후처리와 겹치지 않게 알림·웹훅만 허용한다. */
final class ConfigurableClaimActionSubscriber implements EventSubscriberInterface
{
    private const ALLOWED_TYPES = ['notification', 'webhook'];
    private const ENCRYPTED_FIELDS = [
        'orderer_name', 'orderer_phone', 'orderer_email', 'recipient_name',
        'recipient_phone', 'shipping_zip', 'shipping_address1', 'shipping_address2',
    ];

    public function __construct(
        private ShopConfigService $config,
        private ActionTypeRegistry $registry,
        private ActionExecutionService $executions,
        private OrderRepository $orders,
        private SensitiveValueCodecInterface $encryption,
        private ?Logger $logger = null,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [ClaimStatusChangedEvent::class => ['onClaimStatusChanged', -10]];
    }

    public function onClaimStatusChanged(ClaimStatusChangedEvent $event): void
    {
        $actions = $this->config->getClaimStateActions($event->getDomainId(), $event->getNewStatus());
        if ($actions === []) {
            return;
        }
        $order = $this->orders->find($event->getOrderNo());
        if ($order === null) {
            return;
        }
        $orderData = $order->toArray();
        if ((int) ($orderData['domain_id'] ?? 0) !== $event->getDomainId()) {
            return;
        }
        foreach (self::ENCRYPTED_FIELDS as $field) {
            if (!empty($orderData[$field])) {
                try {
                    $orderData[$field] = $this->encryption->decrypt((string) $orderData[$field]) ?? '';
                } catch (\Throwable) {
                    $orderData[$field] = '';
                }
            }
        }
        $orderData['_claim'] = $event->getClaim();
        $previous = ClaimStatus::tryFrom($event->getPreviousStatus());
        $current = ClaimStatus::tryFrom($event->getNewStatus());
        $actionEvent = new OrderStatusChangedEvent(
            $event->getOrderNo(),
            'claim:' . $event->getPreviousStatus(),
            'claim:' . $event->getNewStatus(),
            $previous?->label() ?? '교환 신규',
            $current?->label() ?? $event->getNewStatus(),
            null,
            null,
            $orderData,
            'claim:' . $event->getClaimId() . ':' . $event->getNewStatus(),
        );

        foreach ($actions as $action) {
            $type = (string) ($action['type'] ?? '');
            if (!($action['enabled'] ?? true) || !in_array($type, self::ALLOWED_TYPES, true)
                || !$this->registry->hasHandler($type)) {
                continue;
            }
            try {
                $this->executions->dispatch($action, $actionEvent);
            } catch (\Throwable $exception) {
                $this->logger?->warning('Claim Action 등록 실패', [
                    'claim_id' => $event->getClaimId(),
                    'status' => $event->getNewStatus(),
                    'type' => $type,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }
}
