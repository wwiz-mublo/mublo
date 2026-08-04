<?php
declare(strict_types=1);

namespace Mublo\Core\Event;

use Mublo\Core\Extension\ExtensionRegistrationScope;

/**
 * Event Dispatcher
 *
 * 이벤트 발송 및 리스너 관리
 */
class EventDispatcher
{
    /**
     * Listener exception logger.
     *
     * @var callable(string,\Throwable,EventInterface,array<string,mixed>):void|null
     */
    private $exceptionLogger;

    /**
     * @param bool $rethrowListenerFailures 리스너 예외를 삼키지 않고 다시 던진다.
     *        운영에서는 false 여야 한다 — 구독자 하나가 페이지 전체를 죽이면 안 된다.
     *        개발에서는 true 를 권한다. 삼켜진 예외는 기능이 조용히 사라지게 만들고,
     *        로그를 들여다보기 전까지 아무도 모른다(실제로 로그인 폼의 버튼 하나가
     *        9일 동안 사라져 있었다).
     */
    public function __construct(
        ?callable $exceptionLogger = null,
        private bool $rethrowListenerFailures = false
    ) {
        $this->exceptionLogger = $exceptionLogger;
    }

    /**
     * 이벤트 리스너
     * [eventName => [priority => [callable, ...]]]
     */
    private array $listeners = [];

    /**
     * 정렬된 리스너 캐시
     * [eventName => [callable, ...]]
     */
    private array $sortedListeners = [];

    /**
     * 상속 체인까지 펼쳐 병합한 리스너 캐시
     * [cacheKey => [callable, ...]]
     *
     * 한 이벤트의 결과가 조상 이름에 걸린 리스너에도 의존하므로,
     * 리스너가 하나라도 바뀌면 통째로 버린다.
     */
    private array $resolvedListeners = [];

    /**
     * 리스너 등록
     *
     * @param string $eventName 이벤트명 (클래스명)
     * @param callable $listener 리스너 콜백
     * @param int $priority 우선순위 (높을수록 먼저 실행)
     */
    public function addListener(string $eventName, callable $listener, int $priority = 0): void
    {
        $this->listeners[$eventName][$priority][] = $listener;
        unset($this->sortedListeners[$eventName]);
        $this->resolvedListeners = [];

        if (ExtensionRegistrationScope::currentOwner() !== null) {
            ExtensionRegistrationScope::recordUndo(
                fn () => $this->removeListener($eventName, $listener)
            );
        }
    }

    /**
     * Subscriber 등록
     *
     * EventSubscriberInterface를 구현한 클래스의 모든 이벤트 리스너를 등록
     *
     * @param EventSubscriberInterface $subscriber
     */
    public function addSubscriber(EventSubscriberInterface $subscriber): void
    {
        foreach ($subscriber::getSubscribedEvents() as $eventName => $params) {
            // 'methodName' 형식
            if (is_string($params)) {
                $this->addListener($eventName, [$subscriber, $params]);
                continue;
            }

            // ['methodName', $priority] 또는 [['method1', $priority1], ['method2', $priority2]] 형식
            if (is_array($params)) {
                // ['methodName', $priority] 형식
                if (is_string($params[0])) {
                    $this->addListener(
                        $eventName,
                        [$subscriber, $params[0]],
                        $params[1] ?? 0
                    );
                    continue;
                }

                // [['method1', $priority1], ['method2', $priority2]] 형식
                foreach ($params as $listener) {
                    $this->addListener(
                        $eventName,
                        [$subscriber, $listener[0]],
                        $listener[1] ?? 0
                    );
                }
            }
        }
    }

    /**
     * 리스너 제거
     */
    public function removeListener(string $eventName, callable $listener): void
    {
        if (!isset($this->listeners[$eventName])) {
            return;
        }

        foreach ($this->listeners[$eventName] as $priority => &$listeners) {
            foreach ($listeners as $key => $existingListener) {
                if ($existingListener === $listener) {
                    unset($listeners[$key]);
                }
            }
        }

        // 모든 우선순위 배열이 비었으면 이벤트 키 자체를 제거
        $hasListeners = false;
        foreach ($this->listeners[$eventName] as $listeners) {
            if (!empty($listeners)) {
                $hasListeners = true;
                break;
            }
        }
        if (!$hasListeners) {
            unset($this->listeners[$eventName]);
        }

        unset($this->sortedListeners[$eventName]);
        $this->resolvedListeners = [];
    }

    /**
     * 이벤트 발송
     *
     * 넣은 이벤트를 그대로 돌려준다. 반환 타입이 EventInterface 면 리스너가 채운
     * 값을 읽는 호출부에서 구체 타입이 사라지므로, 제네릭으로 살린다.
     *
     * 이벤트명뿐 아니라 부모 클래스명·인터페이스명에 걸린 리스너도 함께 실행한다.
     * 발행부는 세분화된 서브클래스를 던지고(MemberRegisteredByUserEvent),
     * 구독부는 원하는 만큼만 넓게 잡는다(MemberRegisteredEvent = 모든 가입 경로).
     *
     * @template T of EventInterface
     * @param T $event 이벤트 객체
     * @return T 처리된 이벤트 객체 (인자와 동일 인스턴스)
     */
    public function dispatch(EventInterface $event): EventInterface
    {
        $eventName = $event->getName();
        $listeners = $this->resolveListenersFor($event);

        foreach ($listeners as $listener) {
            if ($event->isPropagationStopped()) {
                break;
            }

            try {
                $listener($event);
            } catch (\Error $e) {
                // PHP Error (TypeError, ParseError 등)는 재throw — 잡으면 안 되는 치명 오류
                throw $e;
            } catch (\Throwable $e) {
                if ($event instanceof FailFastEventInterface) {
                    throw $e;
                }

                $this->reportListenerException($eventName, $listener, $e, $event);

                if ($this->rethrowListenerFailures) {
                    throw $e;
                }
            }
        }

        return $event;
    }

    /**
     * 이벤트 인스턴스가 실제로 실행할 리스너 목록
     *
     * 이벤트명 → 부모 클래스들 → 인터페이스들 순으로 이름을 펼친 뒤,
     * 각 이름에 걸린 리스너를 우선순위 하나로 병합한다.
     * 우선순위가 같으면 더 구체적인 이름(구상 클래스)에 걸린 쪽이 먼저다.
     *
     * @return list<callable>
     */
    private function resolveListenersFor(EventInterface $event): array
    {
        $eventName = $event->getName();
        // getName() 을 재정의해 클래스명과 다른 이름을 쓰는 이벤트가 있으므로,
        // 클래스만으로도 이름만으로도 캐시 키가 될 수 없다.
        $cacheKey = $eventName . "\0" . $event::class;

        if (isset($this->resolvedListeners[$cacheKey])) {
            return $this->resolvedListeners[$cacheKey];
        }

        $names = $this->resolveEventNames($event);

        /** @var array<int, list<array{0: callable, 1: string}>> $byPriority */
        $byPriority = [];
        foreach ($names as $name) {
            foreach ($this->listeners[$name] ?? [] as $priority => $listeners) {
                foreach ($listeners as $listener) {
                    $byPriority[$priority][] = [$listener, $name];
                }
            }
        }

        if ($byPriority === []) {
            return $this->resolvedListeners[$cacheKey] = [];
        }

        krsort($byPriority);

        $resolved = [];
        $sources = [];
        foreach (array_merge(...$byPriority) as [$listener, $name]) {
            // 부모와 자식에 같은 리스너를 동시에 건 경우 한 번만 실행한다.
            // (가입 포인트가 두 번 지급되는 식의 사고를 막는다.)
            // 같은 이름에 중복 등록한 경우는 기존 동작대로 등록 횟수만큼 실행한다.
            $duplicate = false;
            foreach ($resolved as $index => $existing) {
                if ($existing === $listener && $sources[$index] !== $name) {
                    $duplicate = true;
                    break;
                }
            }

            if ($duplicate) {
                continue;
            }

            $resolved[] = $listener;
            $sources[] = $name;
        }

        return $this->resolvedListeners[$cacheKey] = $resolved;
    }

    /**
     * 이벤트가 응답할 이름 목록 (구체적인 것부터)
     *
     * @return list<string>
     */
    private function resolveEventNames(EventInterface $event): array
    {
        $class = $event::class;

        $names = [$event->getName(), $class];
        foreach (class_parents($class) ?: [] as $parent) {
            $names[] = $parent;
        }
        foreach (class_implements($class) ?: [] as $interface) {
            $names[] = $interface;
        }

        return array_values(array_unique($names));
    }

    /**
     * 특정 이벤트명에 직접 등록된 리스너 목록 반환
     *
     * 이름 하나만 본다 — 상속 체인은 펼치지 않는다.
     * 실제 발송에 쓰이는 목록은 resolveListenersFor() 가 만든다.
     */
    public function getListeners(string $eventName): array
    {
        if (!isset($this->listeners[$eventName])) {
            return [];
        }

        if (!isset($this->sortedListeners[$eventName])) {
            $this->sortedListeners[$eventName] = $this->sortListeners($eventName);
        }

        return $this->sortedListeners[$eventName];
    }

    /**
     * 해당 이름에 직접 등록된 리스너가 있는지 확인
     *
     * getListeners() 와 마찬가지로 상속 체인은 보지 않는다.
     */
    public function hasListeners(string $eventName): bool
    {
        return !empty($this->listeners[$eventName]);
    }

    /**
     * 리스너 우선순위별 정렬
     */
    private function sortListeners(string $eventName): array
    {
        $listeners = $this->listeners[$eventName];
        krsort($listeners);

        return array_merge(...$listeners);
    }

    private function reportListenerException(
        string $eventName,
        callable $listener,
        \Throwable $exception,
        EventInterface $event
    ): void {
        $diagnostic = [
            'event_name' => $eventName,
            'event_class' => $event::class,
            'listener' => $this->describeListener($listener),
            'exception_class' => $exception::class,
            'exception_message' => $exception->getMessage(),
        ];
        $encoded = json_encode($diagnostic, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $message = '[EventDispatcher] listener_failed '
            . ($encoded !== false ? $encoded : $eventName);

        if ($this->exceptionLogger === null) {
            error_log($message);
            return;
        }

        try {
            ($this->exceptionLogger)($message, $exception, $event, $diagnostic);
        } catch (\Throwable $loggerException) {
            // 관측 도구의 실패가 best-effort 이벤트 처리 자체를 중단시키지 않는다.
            error_log($message . ' logger_failed=' . $loggerException::class);
        }
    }

    private function describeListener(callable $listener): string
    {
        if (is_array($listener)) {
            $target = is_object($listener[0]) ? $listener[0]::class : (string) $listener[0];
            return $target . '::' . (string) $listener[1];
        }

        if (is_string($listener)) {
            return $listener;
        }

        if ($listener instanceof \Closure) {
            try {
                $reflection = new \ReflectionFunction($listener);
                return sprintf(
                    'Closure@%s:%d',
                    $reflection->getFileName() ?: 'internal',
                    $reflection->getStartLine()
                );
            } catch (\Throwable) {
                return 'Closure';
            }
        }

        // 앞의 분기가 array·string·Closure 를 모두 걷어냈으므로 여기는 __invoke 객체다.
        // 그래도 타입상으로는 callable 이라, 객체임을 확인한 뒤에 클래스명을 읽는다.
        return is_object($listener) ? $listener::class . '::__invoke' : 'callable';
    }
}
