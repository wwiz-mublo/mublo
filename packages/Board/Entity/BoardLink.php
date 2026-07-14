<?php
namespace Mublo\Packages\Board\Entity;

/**
 * BoardLink Entity
 *
 * 링크 엔티티
 *
 * 책임:
 * - board_links 테이블 데이터 표현
 * - 불변 객체 패턴
 */
final class BoardLink
{
    private int $linkId;
    private int $domainId;
    private int $boardId;
    private int $articleId;

    // 링크 정보
    private string $linkUrl;
    private ?string $linkTitle;
    private ?string $linkDescription;
    private ?string $linkImage;

    // 통계
    private int $clickCount;

    // 시간
    private \DateTimeImmutable $createdAt;

    private function __construct() {}

    /**
     * 배열에서 Entity 생성
     */
    public static function fromArray(array $data): self
    {
        $entity = new self();

        $entity->linkId = (int) ($data['link_id'] ?? 0);
        $entity->domainId = (int) ($data['domain_id'] ?? 0);
        $entity->boardId = (int) ($data['board_id'] ?? 0);
        $entity->articleId = (int) ($data['article_id'] ?? 0);

        // 링크 정보
        $entity->linkUrl = $data['link_url'] ?? '';
        $entity->linkTitle = $data['link_title'] ?? null;
        $entity->linkDescription = $data['link_description'] ?? null;
        $entity->linkImage = $data['link_image'] ?? null;

        // 통계
        $entity->clickCount = (int) ($data['click_count'] ?? 0);

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
            'link_id' => $this->linkId,
            'domain_id' => $this->domainId,
            'board_id' => $this->boardId,
            'article_id' => $this->articleId,
            'link_url' => $this->linkUrl,
            'link_title' => $this->linkTitle,
            'link_description' => $this->linkDescription,
            'link_image' => $this->linkImage,
            'click_count' => $this->clickCount,
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
        ];
    }

    // === Getters ===

    public function getLinkId(): int
    {
        return $this->linkId;
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

    public function getLinkUrl(): string
    {
        return $this->linkUrl;
    }

    public function getLinkTitle(): ?string
    {
        return $this->linkTitle;
    }

    public function getLinkDescription(): ?string
    {
        return $this->linkDescription;
    }

    public function getLinkImage(): ?string
    {
        return $this->linkImage;
    }

    public function getClickCount(): int
    {
        return $this->clickCount;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    // === 헬퍼 메서드 ===

    /**
     * 도메인 추출
     */
    public function getDomain(): string
    {
        $parsed = parse_url($this->linkUrl);
        return $parsed['host'] ?? '';
    }

    /**
     * OG 정보가 있는지 확인
     */
    public function hasOgInfo(): bool
    {
        return $this->linkTitle !== null || $this->linkDescription !== null || $this->linkImage !== null;
    }

    /**
     * 이미지가 있는지 확인
     */
    public function hasImage(): bool
    {
        return $this->linkImage !== null;
    }

    /**
     * 표시용 제목 (없으면 URL 반환)
     */
    public function getDisplayTitle(): string
    {
        return $this->linkTitle ?? $this->linkUrl;
    }

    /**
     * DateTime 파싱 헬퍼
     */
    private static function parseDateTime(string $datetime): \DateTimeImmutable
    {
        return new \DateTimeImmutable($datetime);
    }
}
