<?php

namespace Tests\Unit\Core\Event;

use PHPUnit\Framework\TestCase;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Core\Event\EventSubscriberInterface;
use Mublo\Core\Event\AbstractEvent;
use Mublo\Core\Event\FailFastEventInterface;

/**
 * EventDispatcherExtendedTest
 *
 * EventDispatcher 확장 테스트
 * - removeListener
 * - 리스너 예외 처리 (다른 리스너는 계속 실행)
 * - 다중 구독자
 * - 구독자 형식 변형 (배열/배열의 배열)
 * - 다중 이벤트 구독
 * - hasListeners / getListeners
 */
class EventDispatcherExtendedTest extends TestCase
{
    private EventDispatcher $dispatcher;

    protected function setUp(): void
    {
        $this->dispatcher = new EventDispatcher();
    }

    // =========================================================================
    // removeListener
    // =========================================================================

    public function testRemoveListener(): void
    {
        // Given
        $called = false;
        $listener = function (ExtTestEvent $event) use (&$called) {
            $called = true;
        };

        $this->dispatcher->addListener(ExtTestEvent::class, $listener);
        $this->assertTrue($this->dispatcher->hasListeners(ExtTestEvent::class));

        // When
        $this->dispatcher->removeListener(ExtTestEvent::class, $listener);

        // Then
        $this->dispatcher->dispatch(new ExtTestEvent('test'));
        $this->assertFalse($called);
        $this->assertFalse($this->dispatcher->hasListeners(ExtTestEvent::class));
    }

    public function testRemoveListenerKeepsOtherListeners(): void
    {
        // Given
        $firstCalled = false;
        $secondCalled = false;

        $firstListener = function () use (&$firstCalled) { $firstCalled = true; };
        $secondListener = function () use (&$secondCalled) { $secondCalled = true; };

        $this->dispatcher->addListener(ExtTestEvent::class, $firstListener);
        $this->dispatcher->addListener(ExtTestEvent::class, $secondListener);

        // When: 첫 번째 리스너만 제거
        $this->dispatcher->removeListener(ExtTestEvent::class, $firstListener);
        $this->dispatcher->dispatch(new ExtTestEvent('test'));

        // Then: 두 번째 리스너는 여전히 실행
        $this->assertFalse($firstCalled);
        $this->assertTrue($secondCalled);
        $this->assertTrue($this->dispatcher->hasListeners(ExtTestEvent::class));
    }

    public function testRemoveListenerFromNonExistentEventIsSafe(): void
    {
        // 등록되지 않은 이벤트에서 removeListener를 호출해도 에러 없음
        $listener = fn() => null;
        $this->dispatcher->removeListener('NonExistentEvent', $listener);

        $this->assertFalse($this->dispatcher->hasListeners('NonExistentEvent'));
    }

    // =========================================================================
    // 예외 처리
    // =========================================================================

    public function testListenerExceptionDoesNotStopOtherListeners(): void
    {
        // Given: 첫 번째 리스너가 예외 발생, 두 번째 리스너는 계속 실행
        $secondCalled = false;

        $this->dispatcher->addListener(ExtTestEvent::class, function () {
            throw new \RuntimeException('Listener threw an exception');
        }, 10);

        $this->dispatcher->addListener(ExtTestEvent::class, function () use (&$secondCalled) {
            $secondCalled = true;
        }, 0);

        // When: 예외가 발생해도 다음 리스너는 실행됨
        $this->dispatcher->dispatch(new ExtTestEvent('test'));

        // Then
        $this->assertTrue($secondCalled, '예외 발생 후에도 다음 리스너가 실행되어야 합니다');
    }

    public function testListenerExceptionCanBeLoggedWithoutStoppingOtherListeners(): void
    {
        $messages = [];
        $diagnostics = [];
        $dispatcher = new EventDispatcher(function (
            string $message,
            \Throwable $exception,
            \Mublo\Core\Event\EventInterface $event,
            array $diagnostic
        ) use (&$messages, &$diagnostics) {
            $messages[] = $message;
            $diagnostics[] = $diagnostic;
        });

        $dispatcher->addListener(ExtTestEvent::class, function () {
            throw new \RuntimeException('Listener threw an exception');
        });

        $dispatcher->dispatch(new ExtTestEvent('test'));

        $this->assertCount(1, $messages);
        $this->assertStringContainsString('Listener threw an exception', $messages[0]);
        $this->assertSame(ExtTestEvent::class, $diagnostics[0]['event_name']);
        $this->assertSame(\RuntimeException::class, $diagnostics[0]['exception_class']);
        $this->assertStringStartsWith('Closure@', $diagnostics[0]['listener']);
    }

    public function testDirectDispatcherFallsBackToErrorLog(): void
    {
        $logPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'mublo-event-dispatcher-' . bin2hex(random_bytes(8)) . '.log';
        $previousLog = ini_get('error_log');
        ini_set('error_log', $logPath);

        try {
            $dispatcher = new EventDispatcher();
            $dispatcher->addListener(ExtTestEvent::class, static function (): void {
                throw new \RuntimeException('direct dispatcher failure');
            });

            $dispatcher->dispatch(new ExtTestEvent('test'));

            $content = file_get_contents($logPath);
            $this->assertIsString($content);
            $this->assertStringContainsString('[EventDispatcher] listener_failed', $content);
            $this->assertStringContainsString('ExtTestEvent', $content);
            $this->assertStringContainsString('direct dispatcher failure', $content);
        } finally {
            ini_set('error_log', is_string($previousLog) ? $previousLog : '');
            if (is_file($logPath)) {
                unlink($logPath);
            }
        }
    }

    public function testLoggerFailureFallsBackWithoutStoppingNextListener(): void
    {
        $secondCalled = false;
        $dispatcher = new EventDispatcher(static function (): void {
            throw new \RuntimeException('logger unavailable');
        });
        $dispatcher->addListener(ExtTestEvent::class, static function (): void {
            throw new \RuntimeException('listener unavailable');
        }, 10);
        $dispatcher->addListener(ExtTestEvent::class, function () use (&$secondCalled): void {
            $secondCalled = true;
        });

        $dispatcher->dispatch(new ExtTestEvent('test'));

        $this->assertTrue($secondCalled);
    }

    public function testFailFastEventRethrowsListenerException(): void
    {
        $this->dispatcher->addListener(ExtFailFastEvent::class, function () {
            throw new \RuntimeException('critical listener failed');
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('critical listener failed');

        $this->dispatcher->dispatch(new ExtFailFastEvent());
    }

    public function testPhpErrorInListenerIsRethrown(): void
    {
        // PHP TypeError (Error 계열)는 재throw됨
        $this->dispatcher->addListener(ExtTestEvent::class, function (ExtTestEvent $event) {
            throw new \TypeError('Type error in listener');
        });

        $this->expectException(\TypeError::class);
        $this->dispatcher->dispatch(new ExtTestEvent('test'));
    }

    // =========================================================================
    // 다중 구독자
    // =========================================================================

    public function testMultipleSubscribersForSameEvent(): void
    {
        // Given
        $subscriber1 = new ExtOrderTracker('first');
        $subscriber2 = new ExtOrderTracker('second');

        $this->dispatcher->addSubscriber($subscriber1);
        $this->dispatcher->addSubscriber($subscriber2);

        // When
        $this->dispatcher->dispatch(new ExtTestEvent('data'));

        // Then: 두 구독자 모두 실행
        $this->assertTrue($subscriber1->handled);
        $this->assertTrue($subscriber2->handled);
    }

    // =========================================================================
    // 구독자 형식 변형
    // =========================================================================

    public function testSubscriberWithMethodAndPriorityFormat(): void
    {
        // ['methodName', $priority] 형식
        $subscriber = new ExtPrioritySubscriber();
        $this->dispatcher->addSubscriber($subscriber);

        $this->assertTrue($this->dispatcher->hasListeners(ExtTestEvent::class));
        $this->dispatcher->dispatch(new ExtTestEvent('test'));

        $this->assertTrue($subscriber->handled);
    }

    public function testSubscriberWithArrayOfArraysFormat(): void
    {
        // [['method1', $priority1], ['method2', $priority2]] 형식
        $subscriber = new ExtMultiHandlerSubscriber();
        $this->dispatcher->addSubscriber($subscriber);

        $this->dispatcher->dispatch(new ExtTestEvent('test'));

        // highPriority(100)가 먼저 실행, lowPriority(-10)가 나중에 실행
        $this->assertEquals(['highPriority', 'lowPriority'], $subscriber->order);
    }

    // =========================================================================
    // 다중 이벤트 구독
    // =========================================================================

    public function testSubscriberCanListenToMultipleEvents(): void
    {
        $subscriber = new ExtMultiEventSubscriber();
        $this->dispatcher->addSubscriber($subscriber);

        $this->dispatcher->dispatch(new ExtTestEvent('event1-data'));
        $this->dispatcher->dispatch(new ExtAnotherEvent('event2-data'));

        $this->assertEquals('event1-data', $subscriber->testEventData);
        $this->assertEquals('event2-data', $subscriber->anotherEventData);
    }

    // =========================================================================
    // getListeners
    // =========================================================================

    public function testGetListenersReturnsEmptyForUnregisteredEvent(): void
    {
        $listeners = $this->dispatcher->getListeners('UnregisteredEvent');
        $this->assertEmpty($listeners);
    }

    public function testGetListenersReturnsSortedByPriority(): void
    {
        $order = [];

        $low = function () use (&$order) { $order[] = 'low'; };
        $high = function () use (&$order) { $order[] = 'high'; };
        $mid = function () use (&$order) { $order[] = 'mid'; };

        $this->dispatcher->addListener(ExtTestEvent::class, $low, -5);
        $this->dispatcher->addListener(ExtTestEvent::class, $high, 100);
        $this->dispatcher->addListener(ExtTestEvent::class, $mid, 50);

        // 실제 실행으로 순서 확인
        $this->dispatcher->dispatch(new ExtTestEvent('test'));

        $this->assertEquals(['high', 'mid', 'low'], $order);
    }

    // =========================================================================
    // 전파 중단 보강
    // =========================================================================

    public function testStopPropagationAfterFirstListener(): void
    {
        $executionOrder = [];

        $this->dispatcher->addListener(ExtTestEvent::class, function (ExtTestEvent $event) use (&$executionOrder) {
            $executionOrder[] = 1;
            $event->stopPropagation();
        }, 10);

        $this->dispatcher->addListener(ExtTestEvent::class, function () use (&$executionOrder) {
            $executionOrder[] = 2;
        }, 5);

        $this->dispatcher->addListener(ExtTestEvent::class, function () use (&$executionOrder) {
            $executionOrder[] = 3;
        }, 0);

        $event = new ExtTestEvent('test');
        $this->dispatcher->dispatch($event);

        $this->assertEquals([1], $executionOrder);
        $this->assertTrue($event->isPropagationStopped());
    }

    public function testDispatchReturnsEventObject(): void
    {
        $event = new ExtTestEvent('return-check');
        $returned = $this->dispatcher->dispatch($event);

        $this->assertSame($event, $returned);
    }

    public function testDispatchWithNoListenersReturnsEvent(): void
    {
        $event = new ExtTestEvent('no-listeners');
        $returned = $this->dispatcher->dispatch($event);

        $this->assertSame($event, $returned);
    }

    public function testEventNameIsFullyQualifiedClassName(): void
    {
        $event = new ExtTestEvent('name-check');
        $this->assertEquals(ExtTestEvent::class, $event->getName());
    }

    public function testPropagationNotStoppedByDefault(): void
    {
        $event = new ExtTestEvent('fresh');
        $this->assertFalse($event->isPropagationStopped());
    }
}

// =========================================================================
// Test fixtures
// =========================================================================

class ExtTestEvent extends AbstractEvent
{
    public function __construct(public readonly string $data) {}
}

class ExtAnotherEvent extends AbstractEvent
{
    public function __construct(public readonly string $payload) {}
}

class ExtFailFastEvent extends AbstractEvent implements FailFastEventInterface
{
}

class ExtOrderTracker implements EventSubscriberInterface
{
    public bool $handled = false;

    public function __construct(private readonly string $name) {}

    public static function getSubscribedEvents(): array
    {
        return [ExtTestEvent::class => 'onEvent'];
    }

    public function onEvent(ExtTestEvent $event): void
    {
        $this->handled = true;
    }
}

class ExtPrioritySubscriber implements EventSubscriberInterface
{
    public bool $handled = false;

    public static function getSubscribedEvents(): array
    {
        return [ExtTestEvent::class => ['handle', 50]];
    }

    public function handle(ExtTestEvent $event): void
    {
        $this->handled = true;
    }
}

class ExtMultiHandlerSubscriber implements EventSubscriberInterface
{
    public array $order = [];

    public static function getSubscribedEvents(): array
    {
        return [
            ExtTestEvent::class => [
                ['highPriority', 100],
                ['lowPriority', -10],
            ],
        ];
    }

    public function highPriority(ExtTestEvent $event): void
    {
        $this->order[] = 'highPriority';
    }

    public function lowPriority(ExtTestEvent $event): void
    {
        $this->order[] = 'lowPriority';
    }
}

class ExtMultiEventSubscriber implements EventSubscriberInterface
{
    public ?string $testEventData = null;
    public ?string $anotherEventData = null;

    public static function getSubscribedEvents(): array
    {
        return [
            ExtTestEvent::class => 'onTestEvent',
            ExtAnotherEvent::class => 'onAnotherEvent',
        ];
    }

    public function onTestEvent(ExtTestEvent $event): void
    {
        $this->testEventData = $event->data;
    }

    public function onAnotherEvent(ExtAnotherEvent $event): void
    {
        $this->anotherEventData = $event->payload;
    }
}
