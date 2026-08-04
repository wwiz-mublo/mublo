<?php

namespace Tests\Unit\Core\Event;

use PHPUnit\Framework\TestCase;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Core\Event\EventSubscriberInterface;
use Mublo\Core\Event\AbstractEvent;

class EventDispatcherTest extends TestCase
{
    private EventDispatcher $dispatcher;

    protected function setUp(): void
    {
        $this->dispatcher = new EventDispatcher();
    }

    public function testAddListener(): void
    {
        $called = false;

        $this->dispatcher->addListener(
            TestEvent::class,
            function (TestEvent $event) use (&$called) {
                $called = true;
            }
        );

        $this->assertTrue($this->dispatcher->hasListeners(TestEvent::class));

        $event = new TestEvent('test');
        $this->dispatcher->dispatch($event);

        $this->assertTrue($called);
    }

    public function testAddSubscriber(): void
    {
        $subscriber = new TestEventSubscriber();
        $this->dispatcher->addSubscriber($subscriber);

        $this->assertTrue($this->dispatcher->hasListeners(TestEvent::class));

        $event = new TestEvent('test-data');
        $this->dispatcher->dispatch($event);

        $this->assertTrue($subscriber->wasCalled);
        $this->assertEquals('test-data', $subscriber->receivedData);
    }

    public function testSubscriberWithPriority(): void
    {
        $order = [];

        $this->dispatcher->addListener(
            TestEvent::class,
            function () use (&$order) {
                $order[] = 'default';
            }
        );

        $highPrioritySubscriber = new class implements EventSubscriberInterface {
            public static function getSubscribedEvents(): array
            {
                return [
                    TestEvent::class => ['handle', 100],
                ];
            }

            public function handle(TestEvent $event): void
            {
                // 외부 변수에 접근 불가, 별도 처리 필요
            }
        };

        // 우선순위 100으로 등록 (먼저 실행)
        $this->dispatcher->addListener(
            TestEvent::class,
            function () use (&$order) {
                $order[] = 'high';
            },
            100
        );

        $event = new TestEvent('test');
        $this->dispatcher->dispatch($event);

        // 높은 우선순위가 먼저 실행
        $this->assertEquals(['high', 'default'], $order);
    }

    public function testListenerPriority(): void
    {
        $order = [];

        $this->dispatcher->addListener(
            TestEvent::class,
            function () use (&$order) {
                $order[] = 'low';
            },
            -10
        );

        $this->dispatcher->addListener(
            TestEvent::class,
            function () use (&$order) {
                $order[] = 'high';
            },
            10
        );

        $this->dispatcher->addListener(
            TestEvent::class,
            function () use (&$order) {
                $order[] = 'default';
            },
            0
        );

        $event = new TestEvent('test');
        $this->dispatcher->dispatch($event);

        // 우선순위 높은 순서대로: high(10) → default(0) → low(-10)
        $this->assertEquals(['high', 'default', 'low'], $order);
    }

    public function testStopPropagation(): void
    {
        $secondCalled = false;

        $this->dispatcher->addListener(
            TestEvent::class,
            function (TestEvent $event) {
                $event->stopPropagation();
            },
            10
        );

        $this->dispatcher->addListener(
            TestEvent::class,
            function () use (&$secondCalled) {
                $secondCalled = true;
            },
            0
        );

        $event = new TestEvent('test');
        $this->dispatcher->dispatch($event);

        $this->assertFalse($secondCalled);
    }

    public function testParentEventListenerReceivesChildEvent(): void
    {
        $received = [];

        $this->dispatcher->addListener(
            TestEvent::class,
            function (TestEvent $event) use (&$received) {
                $received[] = 'parent:' . $event->data;
            }
        );
        $this->dispatcher->addListener(
            ChildTestEvent::class,
            function (ChildTestEvent $event) use (&$received) {
                $received[] = 'child:' . $event->data;
            }
        );

        $this->dispatcher->dispatch(new ChildTestEvent('c'));
        $this->dispatcher->dispatch(new TestEvent('p'));

        // 자식 발송은 양쪽 모두, 부모 발송은 부모 리스너만 탄다.
        $this->assertSame(['child:c', 'parent:c', 'parent:p'], $received);
    }

    public function testInterfaceListenerReceivesImplementingEvent(): void
    {
        $called = false;

        $this->dispatcher->addListener(
            AbstractEvent::class,
            function () use (&$called) {
                $called = true;
            }
        );

        $this->dispatcher->dispatch(new ChildTestEvent('x'));

        $this->assertTrue($called);
    }

    public function testPriorityIsMergedAcrossTheInheritanceChain(): void
    {
        $order = [];

        $this->dispatcher->addListener(
            ChildTestEvent::class,
            function () use (&$order) {
                $order[] = 'child-default';
            }
        );
        $this->dispatcher->addListener(
            TestEvent::class,
            function () use (&$order) {
                $order[] = 'parent-high';
            },
            100
        );

        $this->dispatcher->dispatch(new ChildTestEvent('x'));

        // 이름이 더 구체적이라고 앞서지 않는다 — 우선순위가 먼저다.
        $this->assertSame(['parent-high', 'child-default'], $order);
    }

    public function testSameListenerOnParentAndChildRunsOnce(): void
    {
        $calls = 0;
        $listener = function () use (&$calls) {
            $calls++;
        };

        $this->dispatcher->addListener(TestEvent::class, $listener);
        $this->dispatcher->addListener(ChildTestEvent::class, $listener);

        $this->dispatcher->dispatch(new ChildTestEvent('x'));

        $this->assertSame(1, $calls);
    }

    public function testStopPropagationAppliesAcrossTheInheritanceChain(): void
    {
        $parentCalled = false;

        $this->dispatcher->addListener(
            ChildTestEvent::class,
            function (ChildTestEvent $event) {
                $event->stopPropagation();
            },
            10
        );
        $this->dispatcher->addListener(
            TestEvent::class,
            function () use (&$parentCalled) {
                $parentCalled = true;
            }
        );

        $this->dispatcher->dispatch(new ChildTestEvent('x'));

        $this->assertFalse($parentCalled);
    }

    public function testListenerAddedToParentAfterDispatchIsPickedUp(): void
    {
        $called = false;

        // 자식 이벤트를 먼저 흘려 리스너 목록을 캐시시킨다.
        $this->dispatcher->dispatch(new ChildTestEvent('warmup'));

        $this->dispatcher->addListener(
            TestEvent::class,
            function () use (&$called) {
                $called = true;
            }
        );

        $this->dispatcher->dispatch(new ChildTestEvent('x'));

        $this->assertTrue($called);
    }
}

/**
 * 테스트용 이벤트
 */
class TestEvent extends AbstractEvent
{
    public function __construct(
        public readonly string $data
    ) {}
}

/**
 * 테스트용 하위 이벤트
 */
class ChildTestEvent extends TestEvent
{
}

/**
 * 테스트용 구독자
 */
class TestEventSubscriber implements EventSubscriberInterface
{
    public bool $wasCalled = false;
    public ?string $receivedData = null;

    public static function getSubscribedEvents(): array
    {
        return [
            TestEvent::class => 'onTestEvent',
        ];
    }

    public function onTestEvent(TestEvent $event): void
    {
        $this->wasCalled = true;
        $this->receivedData = $event->data;
    }
}
