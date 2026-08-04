<?php
declare(strict_types=1);

namespace Mublo\Plugin\Faq\Subscriber;

use Mublo\Contract\Block\BlockContentCacheInvalidatorInterface;
use Mublo\Core\Event\EventSubscriberInterface;
use Mublo\Plugin\Faq\Event\FaqContentChangedEvent;

/** FAQ 변경 뒤 같은 도메인의 FAQ 블록 행 캐시를 무효화한다. */
final class FaqBlockCacheSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly BlockContentCacheInvalidatorInterface $cacheInvalidator)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [FaqContentChangedEvent::class => 'onChanged'];
    }

    public function onChanged(FaqContentChangedEvent $event): void
    {
        $domainId = $event->getDomainId();
        if ($domainId <= 0) {
            return;
        }

        $this->cacheInvalidator->invalidateByContentType($domainId, 'faq');
    }
}
