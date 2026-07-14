<?php

namespace Mublo\Plugin\Faq\Event;

use Mublo\Core\Event\AbstractEvent;

/** FAQ 카테고리 또는 항목 변경 완료 이벤트. */
final class FaqContentChangedEvent extends AbstractEvent
{
    public function __construct(private readonly int $domainId)
    {
    }

    public function getDomainId(): int
    {
        return $this->domainId;
    }
}
