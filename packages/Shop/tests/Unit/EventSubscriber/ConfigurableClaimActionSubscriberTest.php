<?php

namespace Tests\Shop\Unit\EventSubscriber;

use Mublo\Packages\Shop\Entity\Order;
use Mublo\Packages\Shop\Event\ClaimStatusChangedEvent;
use Mublo\Packages\Shop\Event\OrderStatusChangedEvent;
use Mublo\Packages\Shop\EventSubscriber\ConfigurableClaimActionSubscriber;
use Mublo\Packages\Shop\Repository\OrderRepository;
use Mublo\Packages\Shop\Service\ActionExecutionService;
use Mublo\Packages\Shop\Service\ActionTypeRegistry;
use Mublo\Packages\Shop\Service\ShopConfigService;
use Mublo\Contract\Security\SensitiveValueCodecInterface;
use Tests\Shop\TestCase;

final class ConfigurableClaimActionSubscriberTest extends TestCase
{
    public function testOnlyNotificationAndWebhookAreAllowedForClaimActions(): void
    {
        $config = $this->createMock(ShopConfigService::class);
        $registry = $this->createMock(ActionTypeRegistry::class);
        $executions = $this->createMock(ActionExecutionService::class);
        $orders = $this->createMock(OrderRepository::class);
        $encryption = $this->createMock(SensitiveValueCodecInterface::class);

        $config->method('getClaimStateActions')->with(1, 'ACCEPTED')->willReturn([
            ['type' => 'notification', 'action_id' => 'claim-accepted-notification', 'enabled' => true],
            ['type' => 'stock_restore', 'action_id' => 'must-not-run', 'enabled' => true],
        ]);
        $registry->method('hasHandler')->with('notification')->willReturn(true);
        $orders->method('find')->with('ORD1')->willReturn(Order::fromArray($this->makeOrderData([
            'order_no' => 'ORD1', 'domain_id' => 1,
        ])));
        $executions->expects($this->once())->method('dispatch')->with(
            $this->callback(static fn(array $config): bool => $config['type'] === 'notification'),
            $this->callback(static function (OrderStatusChangedEvent $event): bool {
                return $event->getEventId() === 'claim:77:ACCEPTED'
                    && $event->getNewStateId() === 'claim:ACCEPTED'
                    && ($event->getOrder()['_claim']['return_id'] ?? 0) === 77;
            })
        );

        $subscriber = new ConfigurableClaimActionSubscriber(
            $config, $registry, $executions, $orders, $encryption
        );
        $subscriber->onClaimStatusChanged(new ClaimStatusChangedEvent(
            77, 1, 'ORD1', 3, 'REQUESTED', 'ACCEPTED',
            ['return_id' => 77, 'return_status' => 'ACCEPTED']
        ));
    }
}
