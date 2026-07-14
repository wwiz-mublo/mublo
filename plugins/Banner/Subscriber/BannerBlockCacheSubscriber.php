<?php

namespace Mublo\Plugin\Banner\Subscriber;

use Mublo\Core\Container\DependencyContainer;
use Mublo\Core\Event\EventSubscriberInterface;
use Mublo\Plugin\Banner\Event\BannerContentChangedEvent;
use Mublo\Repository\Block\BlockColumnRepository;
use Mublo\Service\Block\BlockRenderService;

/** 배너 변경 뒤 같은 도메인의 배너 블록 행 캐시를 무효화한다. */
final class BannerBlockCacheSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly DependencyContainer $container)
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

        $columns = $this->container->get(BlockColumnRepository::class);
        $render = $this->container->get(BlockRenderService::class);
        $rowIds = [];
        foreach ($columns->findByContentType($domainId, 'banner') as $column) {
            $rowIds[$column->getRowId()] = true;
        }
        foreach (array_keys($rowIds) as $rowId) {
            $render->invalidateRowCache((int) $rowId);
        }
    }
}
