<?php

namespace Tests\Shop\Unit\EventSubscriber;

use Mublo\Packages\Shop\Action\ActionHandlerInterface;
use Mublo\Packages\Shop\Action\ItemActionHandlerInterface;
use Mublo\Packages\Shop\Event\OrderItemStatusChangedEvent;
use Mublo\Packages\Shop\Event\OrderStatusChangedEvent;
use Mublo\Packages\Shop\EventSubscriber\ConfigurableItemActionSubscriber;
use Mublo\Packages\Shop\Service\ActionTypeRegistry;
use Mublo\Packages\Shop\Service\ShopConfigService;
use Tests\Shop\TestCase;

class ConfigurableItemActionSubscriberTest extends TestCase
{
    public function testOnlyExplicitItemHandlerRunsForPartialStateChange(): void
    {
        $calls = (object) ['item' => 0, 'header' => 0];
        $itemHandler = new class($calls) implements ActionHandlerInterface, ItemActionHandlerInterface {
            public function __construct(private object $calls) {}
            public function execute(array $config, OrderStatusChangedEvent $event): void { $this->calls->header++; }
            public function executeForItem(array $config, OrderItemStatusChangedEvent $event): void { $this->calls->item++; }
            public function getType(): string { return 'item_capable'; }
            public function getLabel(): string { return '상품 액션'; }
            public function getDescription(): string { return ''; }
            public function getSchema(): array { return ['required' => [], 'fields' => []]; }
            public function allowDuplicate(): bool { return false; }
        };
        $headerOnly = new class($calls) implements ActionHandlerInterface {
            public function __construct(private object $calls) {}
            public function execute(array $config, OrderStatusChangedEvent $event): void { $this->calls->header++; }
            public function getType(): string { return 'header_only'; }
            public function getLabel(): string { return '주문 액션'; }
            public function getDescription(): string { return ''; }
            public function getSchema(): array { return ['required' => [], 'fields' => []]; }
            public function allowDuplicate(): bool { return false; }
        };

        $registry = new ActionTypeRegistry();
        $registry->register($itemHandler);
        $registry->register($headerOnly);
        $config = $this->createMock(ShopConfigService::class);
        $config->method('getStateActions')->willReturn([
            ['type' => 'item_capable', 'enabled' => true],
            ['type' => 'header_only', 'enabled' => true],
        ]);

        $subscriber = new ConfigurableItemActionSubscriber($config, $registry);
        $subscriber->onStatusChanged(new OrderItemStatusChangedEvent(
            'ORD1', 10, 'delivered', 'returned', '배송완료', '반품완료',
            null, null, ['domain_id' => 1], ['order_detail_id' => 10],
        ));

        $this->assertSame(1, $calls->item);
        $this->assertSame(0, $calls->header, '주문 단위 알림/웹훅 등이 부분 변경에서 중복 실행되면 안 된다.');
    }
}
