<?php
declare(strict_types=1);

namespace Mublo\Plugin\Manual\Subscriber;

use Mublo\Contract\Block\BlockContentCacheInvalidatorInterface;
use Mublo\Core\Event\EventSubscriberInterface;
use Mublo\Plugin\Manual\Event\ManualContentChangedEvent;

/** 매뉴얼 변경 뒤 같은 도메인의 Manual 블록 행 캐시를 무효화한다. */
final class ManualBlockCacheSubscriber implements EventSubscriberInterface
{
    private const CONTENT_TYPES = [
        'manual_books',
        'manual_toc',
        'manual_page',
        'manual_recent',
    ];

    public function __construct(private readonly BlockContentCacheInvalidatorInterface $cacheInvalidator)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [ManualContentChangedEvent::class => 'onContentChanged'];
    }

    public function onContentChanged(ManualContentChangedEvent $event): void
    {
        $domainId = $event->getDomainId();
        if ($domainId <= 0) {
            return;
        }

        foreach (self::CONTENT_TYPES as $contentType) {
            $this->cacheInvalidator->invalidateByContentType($domainId, $contentType);
        }
    }
}
