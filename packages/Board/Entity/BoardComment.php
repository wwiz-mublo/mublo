<?php
namespace Mublo\Packages\Board\Entity;

use Mublo\Packages\Board\Enum\ArticleStatus;

/**
 * BoardComment Entity
 *
 * 댓글 엔티티
 *
 * 책임:
 * - board_comments 테이블 데이터 표현
 * - 불변 객체 패턴
 */
final class BoardComment
{
    private int $commentId;
    private int $domainId;
    private int $boardId;
    private int $articleId;
    private ?int $parentId;

    // 작성자 (회원/비회원)
    private ?int $memberId;
    private ?string $authorName;
    private ?string $authorPassword;

    // 댓글 정보
    private string $content;
    private bool $isSecret;
    private ArticleStatus $status;

    // 통계
    private int $reactionCount;

    // 계층 구조
    private int $depth;
    private string $path;

    // IP
    private ?string $ipAddress;

    // 시간
    private \DateTimeImmutable $createdAt;
    private \DateTimeImmutable $updatedAt;

    private function __construct() {}

    /**
     * 배열에서 Entity 생성
     */
    public static function fromArray(array $data): self
    {
        $entity = new self();

        $entity->commentId = (int) ($data['comment_id'] ?? 0);
        $entity->domainId = (int) ($data['domain_id'] ?? 0);
        $entity->boardId = (int) ($data['board_id'] ?? 0);
        $entity->articleId = (int) ($data['article_id'] ?? 0);
        $entity->parentId = isset($data['parent_id']) ? (int) $data['parent_id'] : null;

        // 작성자
        $entity->memberId = isset($data['member_id']) ? (int) $data['member_id'] : null;
        $entity->authorName = $data['author_name'] ?? null;
        $entity->authorPassword = $data['author_password'] ?? null;

        // 댓글 정보
        $entity->content = $data['content'] ?? '';
        $entity->isSecret = (bool) ($data['is_secret'] ?? false);
        $entity->status = ArticleStatus::tryFrom($data['status'] ?? 'published') ?? ArticleStatus::PUBLISHED;

        // 통계
        $entity->reactionCount = (int) ($data['reaction_count'] ?? 0);

        // 계층 구조
        $entity->depth = (int) ($data['depth'] ?? 0);
        $entity->path = $data['path'] ?? '';

        // IP
        $entity->ipAddress = $data['ip_address'] ?? null;

        // 시간
        $entity->createdAt = self::parseDateTime($data['created_at'] ?? 'now');
        $entity->updatedAt = self::parseDateTime($data['updated_at'] ?? 'now');

        return $entity;
    }

    /**
     * Entity를 배열로 변환
     */
    public function toArray(): array
    {
        return [
            'comment_id' => $this->commentId,
            'domain_id' => $this->domainId,
            'board_id' => $this->boardId,
            'article_id' => $this->articleId,
            'parent_id' => $this->parentId,
            'member_id' => $this->memberId,
            'author_name' => $this->authorName,
            'author_password' => $this->authorPassword,
            'content' => $this->content,
            'is_secret' => $this->isSecret,
            'status' => $this->status->value,
            'reaction_count' => $this->reactionCount,
            'depth' => $this->depth,
            'path' => $this->path,
            'ip_address' => $this->ipAddress,
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
            'updated_at' => $this->updatedAt->format('Y-m-d H:i:s'),
        ];
    }

    // === Getters ===

    public function getCommentId(): int
    {
        return $this->commentId;
    }

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

    public function getParentId(): ?int
    {
        return $this->parentId;
    }

    public function getMemberId(): ?int
    {
        return $this->memberId;
    }

    public function getAuthorName(): ?string
    {
        return $this->authorName;
    }

    public function getAuthorPassword(): ?string
    {
        return $this->authorPassword;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function isSecret(): bool
    {
        return $this->isSecret;
    }

    public function getStatus(): ArticleStatus
    {
        return $this->status;
    }

    public function getReactionCount(): int
    {
        return $this->reactionCount;
    }

    public function getDepth(): int
    {
        return $this->depth;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    // === 상태 판단 메서드 ===

    /**
     * 회원 작성 댓글인지 확인
     */
    public function isMemberComment(): bool
    {
        return $this->memberId !== null;
    }

    /**
     * 비회원 작성 댓글인지 확인
     */
    public function isGuestComment(): bool
    {
        return $this->memberId === null;
    }

    /**
     * 대댓글인지 확인
     */
    public function isReply(): bool
    {
        return $this->parentId !== null;
    }

    /**
     * 루트 댓글인지 확인
     */
    public function isRootComment(): bool
    {
        return $this->parentId === null;
    }

    /**
     * 발행된 댓글인지 확인
     */
    public function isPublished(): bool
    {
        return $this->status === ArticleStatus::PUBLISHED;
    }

    /**
     * 삭제된 댓글인지 확인
     */
    public function isDeleted(): bool
    {
        return $this->status === ArticleStatus::DELETED;
    }

    /**
     * 작성자 표시명 반환
     */
    public function getAuthorDisplayName(): string
    {
        return $this->authorName ?? '익명';
    }

    /**
     * 특정 회원이 작성자인지 확인
     */
    public function isAuthor(?int $memberId): bool
    {
        if ($memberId === null) {
            return false;
        }
        return $this->memberId === $memberId;
    }

    /**
     * DateTime 파싱 헬퍼
     */
    private static function parseDateTime(string $datetime): \DateTimeImmutable
    {
        return new \DateTimeImmutable($datetime);
    }
}
