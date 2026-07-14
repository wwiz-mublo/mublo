<?php
namespace Mublo\Packages\Board\Event;

use Mublo\Core\Event\AbstractEvent;
use Mublo\Packages\Board\Entity\BoardReaction;

/**
 * 반응 추가 이벤트
 *
 * 발행 시점: board_reactions 테이블 INSERT 후
 * 용도: 포인트 지급, 알림 발송
 */
class ReactionAddedEvent extends AbstractEvent
{
    public const NAME = 'board.reaction.added';

    public function __construct(
        private readonly BoardReaction $reaction,
        private readonly ?int $targetAuthorId = null,
        private readonly ?int $targetDomainId = null
    ) {}

    public function getReaction(): BoardReaction
    {
        return $this->reaction;
    }

    public function getReactionId(): int
    {
        return $this->reaction->getReactionId();
    }

    public function getTargetType(): string
    {
        return $this->reaction->getTargetType()->value;
    }

    public function getTargetId(): int
    {
        return $this->reaction->getTargetId();
    }

    public function getReactionType(): string
    {
        return $this->reaction->getReactionType();
    }

    public function getMemberId(): int
    {
        return $this->reaction->getMemberId();
    }

    /**
     * 반응 받은 대상의 작성자 ID
     */
    public function getTargetAuthorId(): ?int
    {
        return $this->targetAuthorId;
    }

    public function getTargetDomainId(): int
    {
        return $this->targetDomainId ?? $this->reaction->getDomainId();
    }
}
