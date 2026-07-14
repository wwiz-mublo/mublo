<?php
namespace Mublo\Packages\Board\Event;

use Mublo\Core\Event\AbstractEvent;
use Mublo\Packages\Board\Entity\BoardArticle;

/**
 * 게시글 상세 액션 수집 이벤트
 *
 * 발행 시점: 게시글 상세 화면 렌더 직전
 * 용도: 플러그인이 게시글 버튼 영역에 자기 액션(신고 등)을 스킨 수정 없이
 *       끼워 넣는다. 스킨은 전달받은 액션 배열을 그리는 것까지만 책임진다
 *       — 플러그인이 스킨 수정 없이 액션을 추가할 수 있도록 하는 계약.
 *
 * 액션 형식:
 *   [
 *     'label' => '신고',                      // 필수
 *     'url'   => '/boardreport/form?...',     // 필수 (링크 방식 — JS 불요)
 *     'class' => 'board-view__btn--report',   // 선택, 스킨 공통 .board-view__btn 에 덧붙는다
 *     'icon'  => 'bi-flag',                   // 선택
 *   ]
 */
class ArticleActionsCollectEvent extends AbstractEvent
{
    public const NAME = 'board.article.actions.collect';

    /** @var array<int, array{label: string, url: string, class?: string, icon?: string}> */
    private array $actions = [];

    public function __construct(
        private readonly BoardArticle $article,
        private readonly ?int $memberId
    ) {}

    public function getArticle(): BoardArticle
    {
        return $this->article;
    }

    public function getArticleId(): int
    {
        return $this->article->getArticleId();
    }

    public function getMemberId(): ?int
    {
        return $this->memberId;
    }

    /**
     * 액션 추가
     *
     * @param array{label: string, url: string, class?: string, icon?: string} $action
     */
    public function addAction(array $action): self
    {
        if (!empty($action['label']) && !empty($action['url'])) {
            $this->actions[] = $action;
        }
        return $this;
    }

    /** @return array<int, array{label: string, url: string, class?: string, icon?: string}> */
    public function getActions(): array
    {
        return $this->actions;
    }
}
