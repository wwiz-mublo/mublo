<?php
namespace Mublo\Packages\Board\Event;

use Mublo\Core\Event\AbstractEvent;

/**
 * 반응 제거 이벤트
 *
 * 발행 시점: board_reactions 테이블 DELETE 후
 * 용도: 포인트 회수
 */
class ReactionRemovedEvent extends AbstractEvent
{
    public const NAME = 'board.reaction.removed';

    public function __construct(
        private readonly int $reactionId,
        private readonly string $targetType,
        private readonly int $targetId,
        private readonly string $reactionType,
        private readonly int $memberId,
        private readonly ?int $targetAuthorId = null,
        private readonly ?int $domainId = null,
        private readonly ?int $boardId = null
    ) {}

    public function getReactionId(): int
    {
        return $this->reactionId;
    }

    public function getTargetType(): string
    {
        return $this->targetType;
    }

    public function getTargetId(): int
    {
        return $this->targetId;
    }

    public function getReactionType(): string
    {
        return $this->reactionType;
    }

    public function getMemberId(): int
    {
        return $this->memberId;
    }

    public function getTargetAuthorId(): ?int
    {
        return $this->targetAuthorId;
    }

    public function getDomainId(): ?int
    {
        return $this->domainId;
    }

    public function getBoardId(): ?int
    {
        return $this->boardId;
    }
}
