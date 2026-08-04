<?php
declare(strict_types=1);

namespace Mublo\Plugin\Banner\Event;

use Mublo\Core\Event\AbstractEvent;

/** 배너 데이터 변경 완료 이벤트. */
final class BannerContentChangedEvent extends AbstractEvent
{
    public function __construct(private readonly int $domainId)
    {
    }

    public function getDomainId(): int
    {
        return $this->domainId;
    }
}
