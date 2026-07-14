<?php

namespace Mublo\Packages\Shop\Event;

use Mublo\Core\Event\AbstractEvent;

/**
 * 카테고리 삭제 이벤트
 */
class CategoryDeletedEvent extends AbstractEvent
{
    public function __construct(
        private readonly int $domainId,
        private readonly string $categoryCode,
    ) {}

    public function getDomainId(): int { return $this->domainId; }
    public function getCategoryCode(): string { return $this->categoryCode; }
}
