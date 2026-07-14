<?php
namespace Mublo\Packages\Board\Event;

use Mublo\Core\Event\AbstractEvent;
use Mublo\Core\Event\FailFastEventInterface;
use Mublo\Packages\Board\Entity\BoardArticle;

/**
 * 게시글 조회 전 이벤트 (차단 가능)
 *
 * 발행 시점: 게시글 조회 요청 시, 조회수 증가 전
 * 용도: 포인트 소비, 접근 제한
 *
 * 차단 가능: setBlocked(true) 호출 시 게시글 조회 중단
 */
class ArticleViewingEvent extends AbstractEvent implements FailFastEventInterface
{
    public const NAME = 'board.article.viewing';

    private bool $blocked = false;
    private ?string $blockReason = null;

    public function __construct(
        private readonly BoardArticle $article,
        private readonly ?int $memberId,
        private readonly ?string $ipAddress = null,
        private readonly ?int $accessDomainId = null
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

    public function getDomainId(): int
    {
        return $this->accessDomainId ?? $this->article->getDomainId();
    }

    public function getMemberId(): ?int
    {
        return $this->memberId;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    /**
     * 차단 설정
     */
    public function setBlocked(bool $blocked): void
    {
        $this->blocked = $blocked;
    }

    /**
     * 차단 여부 확인
     */
    public function isBlocked(): bool
    {
        return $this->blocked;
    }

    /**
     * 차단 사유 설정
     */
    public function setBlockReason(?string $reason): void
    {
        $this->blockReason = $reason;
    }

    /**
     * 차단 사유 조회
     */
    public function getBlockReason(): ?string
    {
        return $this->blockReason;
    }
}
