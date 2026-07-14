<?php
namespace Mublo\Packages\Board\Event;

use Mublo\Core\Event\AbstractEvent;
use Mublo\Core\Event\FailFastEventInterface;

/**
 * 댓글 작성 전 이벤트 (차단 가능)
 *
 * 발행 시점: 작성 요청 검증 후, DB 저장 전
 * 용도: 스팸 필터링, 내용 검증
 */
class CommentCreatingEvent extends AbstractEvent implements FailFastEventInterface
{
    public const NAME = 'board.comment.creating';

    private bool $blocked = false;
    private ?string $blockReason = null;

    public function __construct(
        private readonly int $domainId,
        private readonly int $boardId,
        private readonly int $articleId,
        private array $data,
        private readonly ?int $memberId
    ) {}

    public function getDomainId(): int
    {
        return $this->domainId;
    }

    public function getBoardId(): int
    {
        return $this->boardId;
    }

    public function getArticleId(): int
    {
        return $this->articleId;
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function setData(array $data): void
    {
        $this->data = $data;
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
