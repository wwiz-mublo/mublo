<?php

namespace Tests\Shop\Unit\Service;

use Mublo\Packages\Shop\Action\ActionHandlerInterface;
use Mublo\Packages\Shop\Event\OrderStatusChangedEvent;
use Mublo\Packages\Shop\Repository\ActionExecutionRepository;
use Mublo\Packages\Shop\Repository\OrderRepository;
use Mublo\Packages\Shop\Service\ActionExecutionService;
use Mublo\Packages\Shop\Service\ActionTypeRegistry;
use Tests\Shop\TestCase;
use ReflectionMethod;

class ActionExecutionServiceTest extends TestCase
{
    public function testRecordsAndExecutesNonWebhookActionOnce(): void
    {
        $calls = (object) ['count' => 0];
        $handler = new class($calls) implements ActionHandlerInterface {
            public function __construct(private object $calls) {}
            public function execute(array $config, OrderStatusChangedEvent $event): void { $this->calls->count++; }
            public function getType(): string { return 'sample'; }
            public function getLabel(): string { return '샘플'; }
            public function getDescription(): string { return ''; }
            public function getSchema(): array { return ['required' => [], 'fields' => []]; }
            public function allowDuplicate(): bool { return false; }
        };
        $registry = new ActionTypeRegistry();
        $registry->register($handler);
        $repository = $this->createMock(ActionExecutionRepository::class);
        $repository->method('findByKey')->willReturn(null);
        $repository->method('create')->willReturn(9);
        $repository->method('claim')->willReturn(true);
        $repository->method('find')->willReturn([
            'execution_id' => 9, 'action_type' => 'sample', 'attempts' => 1,
            'config_json' => json_encode(['type' => 'sample', 'action_id' => 'a1']),
            'order_no' => 'ORD1',
        ]);
        $repository->expects($this->once())->method('markSuccess')->with(9);
        $service = new ActionExecutionService(
            $repository, $registry, $this->createMock(OrderRepository::class)
        );

        $service->dispatch(
            ['type' => 'sample', 'action_id' => 'a1'],
            new OrderStatusChangedEvent(
                'ORD1', 'paid', 'shipping', '결제완료', '배송중', null, null,
                ['domain_id' => 1], 'order_status_log:55',
            )
        );

        $this->assertSame(1, $calls->count);
    }

    public function testExistingExecutionPreventsDuplicateHandlerCall(): void
    {
        $registry = $this->createMock(ActionTypeRegistry::class);
        $repository = $this->createMock(ActionExecutionRepository::class);
        $repository->method('findByKey')->willReturn(['execution_id' => 1]);
        $repository->expects($this->never())->method('create');
        $registry->expects($this->never())->method('getHandler');
        $service = new ActionExecutionService(
            $repository, $registry, $this->createMock(OrderRepository::class)
        );

        $service->dispatch(
            ['type' => 'sample', 'action_id' => 'a1'],
            new OrderStatusChangedEvent(
                'ORD1', 'paid', 'shipping', '결제완료', '배송중', null, null,
                ['domain_id' => 1], 'order_status_log:55',
            )
        );
    }

    public function testRetryReusesStableWebhookDeliveryId(): void
    {
        $handler = new class implements ActionHandlerInterface {
            public int $calls = 0;
            public array $deliveryIds = [];
            public function execute(array $config, OrderStatusChangedEvent $event): void
            {
                $this->deliveryIds[] = $config['_delivery_id'] ?? null;
                $this->calls++;
                if ($this->calls === 1) {
                    throw new \RuntimeException('response lost');
                }
            }
            public function getType(): string { return 'webhook'; }
            public function getLabel(): string { return '웹훅'; }
            public function getDescription(): string { return ''; }
            public function getSchema(): array { return ['required' => [], 'fields' => []]; }
            public function allowDuplicate(): bool { return true; }
        };
        $registry = new ActionTypeRegistry();
        $registry->register($handler);
        $repository = $this->createMock(ActionExecutionRepository::class);
        $repository->method('claim')->willReturn(true);
        $repository->method('find')->willReturn([
            'execution_id' => 9,
            'delivery_id' => '0123456789abcdef0123456789abcdef',
            'domain_id' => 1,
            'action_type' => 'webhook',
            'attempts' => 1,
            'config_json' => json_encode(['type' => 'webhook', 'action_id' => 'a1']),
            'order_no' => 'ORD1',
        ]);
        $repository->expects($this->once())->method('markFailed')->with(9, 1, 'response lost');
        $repository->expects($this->once())->method('markSuccess')->with(9);
        $service = new ActionExecutionService(
            $repository, $registry, $this->createMock(OrderRepository::class)
        );
        $event = new OrderStatusChangedEvent(
            'ORD1', 'paid', 'shipping', '결제완료', '배송중', null, null,
            ['domain_id' => 1], 'order_status_log:55',
        );
        $run = new ReflectionMethod($service, 'run');

        $run->invoke($service, 9, $event);
        $run->invoke($service, 9, $event);

        $this->assertSame([
            '0123456789abcdef0123456789abcdef',
            '0123456789abcdef0123456789abcdef',
        ], $handler->deliveryIds);
    }

    public function testSweepRecoversStaleRunningBeforeFindingDueWork(): void
    {
        $repository = $this->createMock(ActionExecutionRepository::class);
        $repository->expects($this->once())->method('recoverStaleRunning')->with(7);
        $repository->expects($this->once())->method('findDueIds')->with(7, 3)->willReturn([]);
        $service = new ActionExecutionService(
            $repository,
            $this->createMock(ActionTypeRegistry::class),
            $this->createMock(OrderRepository::class)
        );

        $service->maybeRunDue(7);
    }
}
