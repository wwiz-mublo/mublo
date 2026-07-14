<?php

namespace Mublo\Packages\Board\Subscriber;

use Mublo\Core\Event\EventSubscriberInterface;
use Mublo\Core\Event\Block\BlockContentItemsCollectEvent;
use Mublo\Packages\Board\Repository\BoardConfigRepository;
use Mublo\Packages\Board\Repository\BoardGroupRepository;

/**
 * 블록 콘텐츠 아이템 제공 구독자
 *
 * Core BlockRowController의 콘텐츠 아이템 요청에 Board 패키지가 응답
 */
class BlockContentItemsSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private BoardConfigRepository $boardConfigRepo,
        private BoardGroupRepository $boardGroupRepo,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            BlockContentItemsCollectEvent::class => 'onCollect',
        ];
    }

    public function onCollect(BlockContentItemsCollectEvent $event): void
    {
        match ($event->getContentType()) {
            'board' => $this->collectBoards($event),
            'boardcomment' => $this->collectBoards($event),
            'boardgroup' => $this->collectGroups($event),
            default => null,
        };
    }

    private function collectBoards(BlockContentItemsCollectEvent $event): void
    {
        $boards = $this->boardConfigRepo->findActiveByDomain($event->getDomainId());
        $event->setItems(array_map(fn($b) => [
            'id' => $b->getBoardSlug(),
            'label' => $b->getBoardName(),
        ], $boards));
    }

    private function collectGroups(BlockContentItemsCollectEvent $event): void
    {
        $groups = $this->boardGroupRepo->findActiveByDomain($event->getDomainId());
        $event->setItems(array_map(fn($g) => [
            'id' => (string) $g->getGroupId(),
            'label' => $g->getGroupName(),
        ], $groups));
    }
}
