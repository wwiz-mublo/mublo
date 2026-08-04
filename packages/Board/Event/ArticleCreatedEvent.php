<?php
declare(strict_types=1);
namespace Mublo\Packages\Board\Event;

use Mublo\Core\Event\AbstractEvent;
use Mublo\Packages\Board\Entity\BoardArticle;
use Mublo\Packages\Board\Entity\BoardConfig;
use Mublo\Contract\Member\MemberIdentity;

/**
 * 게시글 작성 완료 이벤트 (readonly)
 *
 * 발행 시점: DB 저장 후
 * 용도: 포인트 지급, 알림 발송, 검색 인덱싱
 *
 * Note: 차단 불가 - 이미 작성이 완료된 후 발행됨
 */
class ArticleCreatedEvent extends AbstractEvent
{
    public const NAME = 'board.article.created';

    public function __construct(
        private readonly BoardArticle $article,
        private readonly ?MemberIdentity $author = null,
        private readonly ?BoardConfig $board = null
    ) {}

    public function getArticle(): BoardArticle
    {
        return $this->article;
    }

    /** @deprecated Use getAuthorIdentity(). */
    public function getAuthor(): ?MemberIdentity
    {
        return $this->author;
    }

    public function getAuthorIdentity(): ?MemberIdentity
    {
        return $this->author;
    }

    public function getBoard(): ?BoardConfig
    {
        return $this->board;
    }

    /** 게시판 이름 — 알림 변수 등 구독자 편의 (board 미전달 시 빈 문자열) */
    public function getBoardName(): string
    {
        return $this->board?->getBoardName() ?? '';
    }

    /** 게시판 슬러그 (URL 식별자) */
    public function getBoardSlug(): string
    {
        return $this->board?->getBoardSlug() ?? '';
    }

    public function getArticleId(): int
    {
        return $this->article->getArticleId();
    }

    public function getBoardId(): int
    {
        return $this->article->getBoardId();
    }

    public function getDomainId(): int
    {
        return $this->article->getDomainId();
    }

    public function getMemberId(): ?int
    {
        return $this->article->getMemberId();
    }
}
