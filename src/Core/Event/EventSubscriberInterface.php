<?php

namespace Mublo\Core\Event;

/**
 * Event Subscriber Interface
 *
 * 여러 이벤트를 한 클래스에서 구독할 때 사용
 */
interface EventSubscriberInterface
{
    /**
     * 구독할 이벤트 목록 반환
     *
     * 반환 형식:
     * [
     *     EventClass::class => 'methodName',
     *     EventClass::class => ['methodName', $priority],
     *     EventClass::class => [['method1', $priority1], ['method2', $priority2]],
     * ]
     */
    public static function getSubscribedEvents(): array;
}
