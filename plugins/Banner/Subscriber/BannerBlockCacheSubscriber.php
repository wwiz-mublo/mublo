<?php
declare(strict_types=1);

namespace Mublo\Plugin\Banner\Subscriber;

use Mublo\Contract\Block\BlockContentCacheInvalidatorInterface;
use Mublo\Core\Event\EventSubscriberInterface;
use Mublo\Plugin\Banner\Event\BannerContentChangedEvent;

/** 배너 변경 뒤 같은 도메인의 배너 블록 행 캐시를 무효화한다. */
final class BannerBlockCacheSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly BlockContentCacheInvalidatorInterface $cacheInvalidator)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [BannerContentChangedEvent::class => 'onChanged'];
    }

    public function onChanged(BannerContentChangedEvent $event): void
    {
        $domainId = $event->getDomainId();
        if ($domainId <= 0) {
            return;
        }

        $this->cacheInvalidator->invalidateByContentType($domainId, 'banner');
    }
}
