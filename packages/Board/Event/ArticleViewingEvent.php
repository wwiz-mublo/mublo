<?php
declare(strict_types=1);
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
 *
 * 접근 판정과 과금은 이 이벤트 하나에 함께 걸린다. 관리 목적 조회(관리자 화면,
 * 수정 폼)는 접근 판정은 받아야 하고 과금은 받으면 안 되므로, 발행부가
 * billable=false 로 그 차이를 알린다 (isBillable 참고).
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
        private readonly ?int $accessDomainId = null,
        private readonly bool $billable = true
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
     * 열람 대가를 물려도 되는 조회인가
     *
     * false 면 관리 목적 조회다 — 관리자 화면이나 수정 폼처럼 콘텐츠를 소비하러
     * 온 것이 아닌 경로. 차단 게이트(블라인드 등)는 그대로 판정해야 하고,
     * 과금 구독자만 물러난다.
     */
    public function isBillable(): bool
    {
        return $this->billable;
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
