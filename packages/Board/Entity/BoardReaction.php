<?php
namespace Mublo\Packages\Board\Entity;

use Mublo\Packages\Board\Enum\ReactionTargetType;

/**
 * BoardReaction Entity
 *
 * 반응 엔티티 (게시글/댓글 통합)
 *
 * 책임:
 * - board_reactions 테이블 데이터 표현
 * - 불변 객체 패턴
 */
final class BoardReaction
{
    private int $reactionId;
    private int $domainId;
    private int $boardId;

    // 대상 (게시글 또는 댓글)
    private ReactionTargetType $targetType;
    private int $targetId;

    // 반응자
    private int $memberId;

    // 반응 타입
    private string $reactionType; // like, love, wow, sad, angry 등

    // 시간
    private \DateTimeImmutable $createdAt;

    private function __construct() {}

    /**
     * 배열에서 Entity 생성
     */
    public static function fromArray(array $data): self
    {
        $entity = new self();

        $entity->reactionId = (int) ($data['reaction_id'] ?? 0);
        $entity->domainId = (int) ($data['domain_id'] ?? 0);
        $entity->boardId = (int) ($data['board_id'] ?? 0);

        // 대상
        $entity->targetType = ReactionTargetType::tryFrom($data['target_type'] ?? 'article') ?? ReactionTargetType::ARTICLE;
        $entity->targetId = (int) ($data['target_id'] ?? 0);

        // 반응자
        $entity->memberId = (int) ($data['member_id'] ?? 0);

        // 반응 타입
        $entity->reactionType = $data['reaction_type'] ?? 'like';

        // 시간
        $entity->createdAt = self::parseDateTime($data['created_at'] ?? 'now');

        return $entity;
    }

    /**
     * Entity를 배열로 변환
     */
    public function toArray(): array
    {
        return [
            'reaction_id' => $this->reactionId,
            'domain_id' => $this->domainId,
            'board_id' => $this->boardId,
            'target_type' => $this->targetType->value,
            'target_id' => $this->targetId,
            'member_id' => $this->memberId,
            'reaction_type' => $this->reactionType,
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
        ];
    }

    // === Getters ===

    public function getReactionId(): int
    {
        return $this->reactionId;
    }

    public function getDomainId(): int
    {
        return $this->domainId;
    }

    public function getBoardId(): int
    {
        return $this->boardId;
    }

    public function getTargetType(): ReactionTargetType
    {
        return $this->targetType;
    }

    public function getTargetId(): int
    {
        return $this->targetId;
    }

    public function getMemberId(): int
    {
        return $this->memberId;
    }

    public function getReactionType(): string
    {
        return $this->reactionType;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    // === 상태 판단 메서드 ===

    /**
     * 게시글 반응인지 확인
     */
    public function isArticleReaction(): bool
    {
        return $this->targetType === ReactionTargetType::ARTICLE;
    }

    /**
     * 댓글 반응인지 확인
     */
    public function isCommentReaction(): bool
    {
        return $this->targetType === ReactionTargetType::COMMENT;
    }

    /**
     * 좋아요인지 확인
     */
    public function isLike(): bool
    {
        return $this->reactionType === 'like';
    }

    /**
     * DateTime 파싱 헬퍼
     */
    private static function parseDateTime(string $datetime): \DateTimeImmutable
    {
        return new \DateTimeImmutable($datetime);
    }
}
