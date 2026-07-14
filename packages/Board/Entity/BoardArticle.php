<?php
namespace Mublo\Packages\Board\Entity;

use Mublo\Packages\Board\Enum\ArticleStatus;

/**
 * BoardArticle Entity
 *
 * 게시글 엔티티
 *
 * 책임:
 * - board_articles 테이블 데이터 표현
 * - 불변 객체 패턴
 */
final class BoardArticle
{
    private int $articleId;
    private int $domainId;
    private int $boardId;
    private ?int $categoryId;

    // 작성자 (회원/비회원)
    private ?int $memberId;
    private ?string $authorName;
    private ?string $authorPassword;

    // 게시글 정보
    private string $title;
    private ?string $slug;
    private string $content;
    private ?string $thumbnail;

    // 상태
    private bool $isNotice;
    private bool $isSecret;
    private ArticleStatus $status;

    // 권한 (개별 글)
    private ?int $readLevel;
    private ?int $downloadLevel;

    // 통계
    private int $viewCount;
    private int $commentCount;
    private int $reactionCount;

    // 위치 정보
    private ?float $locationLat;
    private ?float $locationLng;

    // IP
    private ?string $ipAddress;

    // 시간
    private \DateTimeImmutable $createdAt;
    private \DateTimeImmutable $updatedAt;
    private ?\DateTimeImmutable $publishedAt;

    private function __construct() {}

    /**
     * 배열에서 Entity 생성
     */
    public static function fromArray(array $data): self
    {
        $entity = new self();

        $entity->articleId = (int) ($data['article_id'] ?? 0);
        $entity->domainId = (int) ($data['domain_id'] ?? 0);
        $entity->boardId = (int) ($data['board_id'] ?? 0);
        $entity->categoryId = isset($data['category_id']) ? (int) $data['category_id'] : null;

        // 작성자
        $entity->memberId = isset($data['member_id']) ? (int) $data['member_id'] : null;
        $entity->authorName = $data['author_name'] ?? null;
        $entity->authorPassword = $data['author_password'] ?? null;

        // 게시글 정보
        $entity->title = $data['title'] ?? '';
        $entity->slug = $data['slug'] ?? null;
        $entity->content = $data['content'] ?? '';
        $entity->thumbnail = $data['thumbnail'] ?? null;

        // 상태
        $entity->isNotice = (bool) ($data['is_notice'] ?? false);
        $entity->isSecret = (bool) ($data['is_secret'] ?? false);
        $entity->status = ArticleStatus::tryFrom($data['status'] ?? 'published') ?? ArticleStatus::PUBLISHED;

        // 권한
        $entity->readLevel = isset($data['read_level']) ? (int) $data['read_level'] : null;
        $entity->downloadLevel = isset($data['download_level']) ? (int) $data['download_level'] : null;

        // 통계
        $entity->viewCount = (int) ($data['view_count'] ?? 0);
        $entity->commentCount = (int) ($data['comment_count'] ?? 0);
        $entity->reactionCount = (int) ($data['reaction_count'] ?? 0);

        // 위치
        $entity->locationLat = isset($data['location_lat']) ? (float) $data['location_lat'] : null;
        $entity->locationLng = isset($data['location_lng']) ? (float) $data['location_lng'] : null;

        // IP
        $entity->ipAddress = $data['ip_address'] ?? null;

        // 시간
        $entity->createdAt = self::parseDateTime($data['created_at'] ?? 'now');
        $entity->updatedAt = self::parseDateTime($data['updated_at'] ?? 'now');
        $entity->publishedAt = isset($data['published_at']) ? self::parseDateTime($data['published_at']) : null;

        return $entity;
    }

    /**
     * Entity를 배열로 변환
     */
    public function toArray(): array
    {
        return [
            'article_id' => $this->articleId,
            'domain_id' => $this->domainId,
            'board_id' => $this->boardId,
            'category_id' => $this->categoryId,
            'member_id' => $this->memberId,
            'author_name' => $this->authorName,
            'author_password' => $this->authorPassword,
            'title' => $this->title,
            'slug' => $this->slug,
            'content' => $this->content,
            'thumbnail' => $this->thumbnail,
            'is_notice' => $this->isNotice,
            'is_secret' => $this->isSecret,
            'status' => $this->status->value,
            'read_level' => $this->readLevel,
            'download_level' => $this->downloadLevel,
            'view_count' => $this->viewCount,
            'comment_count' => $this->commentCount,
            'reaction_count' => $this->reactionCount,
            'location_lat' => $this->locationLat,
            'location_lng' => $this->locationLng,
            'ip_address' => $this->ipAddress,
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
            'updated_at' => $this->updatedAt->format('Y-m-d H:i:s'),
            'published_at' => $this->publishedAt?->format('Y-m-d H:i:s'),
        ];
    }

    // === Getters ===

    public function getArticleId(): int
    {
        return $this->articleId;
    }

    public function getDomainId(): int
    {
        return $this->domainId;
    }

    public function getBoardId(): int
    {
        return $this->boardId;
    }

    public function getCategoryId(): ?int
    {
        return $this->categoryId;
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

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getThumbnail(): ?string
    {
        return $this->thumbnail;
    }

    public function isNotice(): bool
    {
        return $this->isNotice;
    }

    public function isSecret(): bool
    {
        return $this->isSecret;
    }

    public function getStatus(): ArticleStatus
    {
        return $this->status;
    }

    public function getReadLevel(): ?int
    {
        return $this->readLevel;
    }

    public function getDownloadLevel(): ?int
    {
        return $this->downloadLevel;
    }

    public function getViewCount(): int
    {
        return $this->viewCount;
    }

    public function getCommentCount(): int
    {
        return $this->commentCount;
    }

    public function getReactionCount(): int
    {
        return $this->reactionCount;
    }

    public function getLocationLat(): ?float
    {
        return $this->locationLat;
    }

    public function getLocationLng(): ?float
    {
        return $this->locationLng;
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

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    // === 상태 판단 메서드 ===

    /**
     * 회원 작성 글인지 확인
     */
    public function isMemberArticle(): bool
    {
        return $this->memberId !== null;
    }

    /**
     * 비회원 작성 글인지 확인
     */
    public function isGuestArticle(): bool
    {
        return $this->memberId === null;
    }

    /**
     * 발행된 글인지 확인
     */
    public function isPublished(): bool
    {
        return $this->status === ArticleStatus::PUBLISHED;
    }

    /**
     * 임시저장 글인지 확인
     */
    public function isDraft(): bool
    {
        return $this->status === ArticleStatus::DRAFT;
    }

    /**
     * 삭제된 글인지 확인
     */
    public function isDeleted(): bool
    {
        return $this->status === ArticleStatus::DELETED;
    }

    /**
     * 위치 정보가 있는지 확인
     */
    public function hasLocation(): bool
    {
        return $this->locationLat !== null && $this->locationLng !== null;
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
