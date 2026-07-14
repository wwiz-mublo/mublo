<?php

namespace Mublo\Packages\Board\Subscriber;

use Mublo\Core\Event\EventSubscriberInterface;
use Mublo\Core\Container\DependencyContainer;
use Mublo\Packages\Board\Event\ArticleCreatedEvent;
use Mublo\Packages\Board\Event\ArticleUpdatedEvent;
use Mublo\Packages\Board\Event\ArticleDeletedEvent;
use Mublo\Packages\Board\Event\CommentCreatedEvent;
use Mublo\Packages\Board\Event\CommentUpdatedEvent;
use Mublo\Packages\Board\Event\CommentDeletedEvent;
use Mublo\Packages\Board\Entity\BoardArticle;
use Mublo\Packages\Board\Repository\BoardConfigRepository;
use Mublo\Repository\Block\BlockColumnRepository;
use Mublo\Service\Block\BlockRenderService;

/**
 * 게시글 변경 → 게시판 진열 블록 캐시 무효화
 *
 * 게시판 블록은 렌더된 글 목록 HTML(제목·작성일 등)을 행 단위로 통째 캐싱한다.
 * 글이 추가/수정/삭제되면 "최신 N" 목록 구성·내용이 바뀌므로 해당 블록 캐시를
 * 깨지 않으면 stale 목록이 노출된다.
 *
 * - board 블록(content_items=슬러그): 글이 속한 게시판 슬러그로 역참조
 * - boardgroup 블록(content_items=그룹ID): 게시판이 속한 그룹 ID로 역참조
 *
 * 글 작성/수정/삭제 모두 목록에 영향을 주므로 세 이벤트 전부 처리한다.
 */
class BoardBlockCacheSubscriber implements EventSubscriberInterface
{
    private DependencyContainer $container;

    public function __construct(DependencyContainer $container)
    {
        $this->container = $container;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ArticleCreatedEvent::class => 'onCreated',
            ArticleUpdatedEvent::class => 'onUpdated',
            ArticleDeletedEvent::class => 'onDeleted',
            CommentCreatedEvent::class => 'onCommentChanged',
            CommentUpdatedEvent::class => 'onCommentChanged',
            CommentDeletedEvent::class => 'onCommentChanged',
        ];
    }

    public function onCreated(ArticleCreatedEvent $event): void
    {
        $this->invalidate($event->getArticle());
    }

    public function onUpdated(ArticleUpdatedEvent $event): void
    {
        $this->invalidate($event->getArticle());
    }

    public function onDeleted(ArticleDeletedEvent $event): void
    {
        $this->invalidate($event->getArticle());
    }

    /**
     * 댓글 작성/수정/삭제 → 게시판 최신댓글(boardcomment) 블록 캐시 무효화
     *
     * 어떤 게시판의 댓글이 바뀌었는지와 무관하게, 해당 도메인의 boardcomment 블록을
     * 모두 무효화한다(안전한 과무효화 — 지정 게시판별로 좁히지 않음).
     */
    public function onCommentChanged(CommentCreatedEvent|CommentUpdatedEvent|CommentDeletedEvent $event): void
    {
        $domainId = $event->getComment()->getDomainId();
        if ($domainId <= 0) {
            return;
        }

        /** @var BlockColumnRepository $columns */
        $columns = $this->container->get(BlockColumnRepository::class);
        /** @var BlockRenderService $render */
        $render = $this->container->get(BlockRenderService::class);

        $rowIds = [];
        foreach ($columns->findByContentType($domainId, 'boardcomment') as $col) {
            $rowIds[$col->getRowId()] = true;
        }

        foreach (array_keys($rowIds) as $rowId) {
            $render->invalidateRowCache((int) $rowId);
        }
    }

    private function invalidate(BoardArticle $article): void
    {
        $domainId = $article->getDomainId();
        $boardId = $article->getBoardId();
        if ($domainId <= 0 || $boardId <= 0) {
            return;
        }

        /** @var BoardConfigRepository $boardRepo */
        $boardRepo = $this->container->get(BoardConfigRepository::class);
        $board = $boardRepo->find($boardId);
        if (!$board) {
            return;
        }

        /** @var BlockColumnRepository $columns */
        $columns = $this->container->get(BlockColumnRepository::class);
        /** @var BlockRenderService $render */
        $render = $this->container->get(BlockRenderService::class);

        $rowIds = [];

        // board 블록 — 슬러그(현 저장형) + 레거시 숫자 ID 모두
        foreach ($columns->findByContentItem($domainId, 'board', $board->getBoardSlug()) as $col) {
            $rowIds[$col->getRowId()] = true;
        }
        foreach ($columns->findByContentItem($domainId, 'board', $boardId) as $col) {
            $rowIds[$col->getRowId()] = true;
        }

        // boardgroup 블록 — 게시판이 속한 그룹 ID
        $groupId = $board->getGroupId();
        if ($groupId > 0) {
            foreach ($columns->findByContentItem($domainId, 'boardgroup', $groupId) as $col) {
                $rowIds[$col->getRowId()] = true;
            }
        }

        foreach (array_keys($rowIds) as $rowId) {
            $render->invalidateRowCache((int) $rowId);
        }
    }
}
