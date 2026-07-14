<?php
namespace Mublo\Packages\Board\Event;

use Mublo\Core\Event\AbstractEvent;
use Mublo\Core\Event\FailFastEventInterface;
use Mublo\Packages\Board\Entity\BoardArticle;

/**
 * 게시글 삭제 전 이벤트 (차단 가능)
 *
 * 발행 시점: 삭제 검증 후, DB 삭제/상태변경 전
 * 용도: 삭제 권한 검증, 백업 생성
 */
class ArticleDeletingEvent extends AbstractEvent implements FailFastEventInterface
{
    public const NAME = 'board.article.deleting';

    private bool $blocked = false;
    private ?string $blockReason = null;

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

    public function getBoardId(): int
    {
        return $this->article->getBoardId();
    }

    public function getMemberId(): ?int
    {
        return $this->memberId;
    }

    public function setBlocked(bool $blocked): void
    {
        $this->blocked = $blocked;
    }

    public function isBlocked(): bool
    {
        return $this->blocked;
    }

    public function setBlockReason(?string $reason): void
    {
        $this->blockReason = $reason;
    }

    public function getBlockReason(): ?string
    {
        return $this->blockReason;
    }
}
