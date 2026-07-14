<?php

namespace Mublo\Plugin\Manual\Subscriber;

use Mublo\Core\Container\DependencyContainer;
use Mublo\Core\Event\EventSubscriberInterface;
use Mublo\Plugin\Manual\Event\ManualContentChangedEvent;
use Mublo\Repository\Block\BlockColumnRepository;
use Mublo\Service\Block\BlockRenderService;

/** 매뉴얼 변경 뒤 같은 도메인의 Manual 블록 행 캐시를 무효화한다. */
final class ManualBlockCacheSubscriber implements EventSubscriberInterface
{
    private const CONTENT_TYPES = [
        'manual_books',
        'manual_toc',
        'manual_page',
        'manual_recent',
    ];

    public function __construct(private readonly DependencyContainer $container)
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

        /** @var BlockColumnRepository $columns */
        $columns = $this->container->get(BlockColumnRepository::class);
        /** @var BlockRenderService $render */
        $render = $this->container->get(BlockRenderService::class);

        $rowIds = [];
        foreach (self::CONTENT_TYPES as $contentType) {
            foreach ($columns->findByContentType($domainId, $contentType) as $column) {
                $rowIds[$column->getRowId()] = true;
            }
        }

        foreach (array_keys($rowIds) as $rowId) {
            $render->invalidateRowCache((int) $rowId);
        }
    }
}
