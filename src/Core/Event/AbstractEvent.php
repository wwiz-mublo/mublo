<?php

namespace Mublo\Core\Event;

/**
 * Abstract Event
 *
 * 기본 이벤트 구현
 */
abstract class AbstractEvent implements EventInterface
{
    private bool $propagationStopped = false;

    /**
     * 이벤트명 반환 (클래스명 기반)
     */
    public function getName(): string
    {
        return static::class;
    }

    /**
     * 이벤트 전파 중단 여부
     */
    public function isPropagationStopped(): bool
    {
        return $this->propagationStopped;
    }

    /**
     * 이벤트 전파 중단
     */
    public function stopPropagation(): void
    {
        $this->propagationStopped = true;
    }
}
