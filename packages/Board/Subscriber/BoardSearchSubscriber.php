<?php

namespace Mublo\Packages\Board\Subscriber;

use Mublo\Core\Event\EventSubscriberInterface;
use Mublo\Core\Event\Search\SearchEvent;
use Mublo\Core\Event\Search\SearchSourceCollectEvent;
use Mublo\Packages\Board\Repository\BoardArticleRepository;

/**
 * 게시판 검색 구독자 (Board Package)
 *
 * SearchEvent 발행 시 게시판 게시글을 검색하여 결과를 추가한다.
 * BoardProvider::boot()에서 등록.
 */
class BoardSearchSubscriber implements EventSubscriberInterface
{
    private BoardArticleRepository $articleRepository;

    public function __construct(BoardArticleRepository $articleRepository)
    {
        $this->articleRepository = $articleRepository;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            SearchSourceCollectEvent::class => 'onCollect',
            SearchEvent::class              => 'onSearch',
        ];
    }

    public function onCollect(SearchSourceCollectEvent $event): void
    {
        $event->addSource('board', '게시판', false);
    }

    public function onSearch(SearchEvent $event): void
    {
        if (!$event->isSourceEnabled('board')) {
            return;
        }

        $keyword  = $event->getKeyword();
        $domainId = $event->getDomainId();

        $total = $this->articleRepository->countByKeyword($domainId, $keyword);
        if ($total === 0) {
            return;
        }

        $items = $this->articleRepository->searchByKeyword(
            $domainId,
            $keyword,
            $event->getPerSource()
        );

        $event->addResults('board', '게시판', $items, $total, [
            'more_url' => '/community?keyword=' . rawurlencode($keyword),
        ]);
    }
}
