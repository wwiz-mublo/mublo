<?php

namespace Mublo\Plugin\Faq\Subscriber;

use Mublo\Core\Container\DependencyContainer;
use Mublo\Core\Event\EventSubscriberInterface;
use Mublo\Plugin\Faq\Event\FaqContentChangedEvent;
use Mublo\Repository\Block\BlockColumnRepository;
use Mublo\Service\Block\BlockRenderService;

/** FAQ 변경 뒤 같은 도메인의 FAQ 블록 행 캐시를 무효화한다. */
final class FaqBlockCacheSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly DependencyContainer $container)
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

        $columns = $this->container->get(BlockColumnRepository::class);
        $render = $this->container->get(BlockRenderService::class);
        $rowIds = [];
        foreach ($columns->findByContentType($domainId, 'faq') as $column) {
            $rowIds[$column->getRowId()] = true;
        }
        foreach (array_keys($rowIds) as $rowId) {
            $render->invalidateRowCache((int) $rowId);
        }
    }
}
