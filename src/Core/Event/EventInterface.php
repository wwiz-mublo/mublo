<?php

namespace Mublo\Core\Event;

/**
 * Event Interface
 *
 * 모든 이벤트가 구현해야 하는 인터페이스
 */
interface EventInterface
{
    /**
     * 이벤트명 반환
     */
    public function getName(): string;

    /**
     * 이벤트 전파 중단 여부
     */
    public function isPropagationStopped(): bool;

    /**
     * 이벤트 전파 중단
     */
    public function stopPropagation(): void;
}
