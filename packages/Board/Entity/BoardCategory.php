<?php
namespace Mublo\Packages\Board\Entity;

use DateTimeImmutable;

/**
 * Class BoardCategory
 *
 * 게시판 카테고리 엔티티
 *
 * 책임:
 * - board_categories 테이블의 데이터를 객체로 표현
 * - 독립적인 카테고리 (여러 게시판에서 재사용 가능)
 *
 * 금지:
 * - DB 직접 접근
 * - 비즈니스 로직 (Service 담당)
 */
class BoardCategory
{
    // ========================================
    // 기본 필드
    // ========================================
    protected int $categoryId;
    protected int $domainId;
    protected string $categoryName;
    protected string $categorySlug;
    protected ?string $categoryDescription;

    // ========================================
    // 관리
    // ========================================
    protected int $sortOrder;
    protected bool $isActive;
    protected DateTimeImmutable $createdAt;
    protected DateTimeImmutable $updatedAt;

    /**
     * DB 로우 데이터로부터 BoardCategory 객체 생성
     */
    public static function fromArray(array $data): self
    {
        $category = new self();

        // 기본 필드
        $category->categoryId = (int) ($data['category_id'] ?? 0);
        $category->domainId = (int) ($data['domain_id'] ?? 0);
        $category->categoryName = $data['category_name'] ?? '';
        $category->categorySlug = $data['category_slug'] ?? '';
        $category->categoryDescription = $data['category_description'] ?? null;

        // 관리
        $category->sortOrder = (int) ($data['sort_order'] ?? 0);
        $category->isActive = (bool) ($data['is_active'] ?? true);

        // 날짜
        $category->createdAt = self::parseDateTime($data['created_at'] ?? null);
        $category->updatedAt = self::parseDateTime($data['updated_at'] ?? null);

        return $category;
    }

    /**
     * 날짜 문자열을 DateTimeImmutable로 변환
     */
    private static function parseDateTime(?string $datetime): DateTimeImmutable
    {
        if (empty($datetime)) {
            return new DateTimeImmutable();
        }

        try {
            return new DateTimeImmutable($datetime);
        } catch (\Exception $e) {
            return new DateTimeImmutable();
        }
    }

    /**
     * 배열로 변환
     */
    public function toArray(): array
    {
        return [
            'category_id' => $this->categoryId,
            'domain_id' => $this->domainId,
            'category_name' => $this->categoryName,
            'category_slug' => $this->categorySlug,
            'category_description' => $this->categoryDescription,
            'sort_order' => $this->sortOrder,
            'is_active' => $this->isActive,
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
            'updated_at' => $this->updatedAt->format('Y-m-d H:i:s'),
        ];
    }

    // ========================================
    // Getters - 기본 필드
    // ========================================

    public function getCategoryId(): int
    {
        return $this->categoryId;
    }

    public function getDomainId(): int
    {
        return $this->domainId;
    }

    public function getCategoryName(): string
    {
        return $this->categoryName;
    }

    public function getCategorySlug(): string
    {
        return $this->categorySlug;
    }

    public function getCategoryDescription(): ?string
    {
        return $this->categoryDescription;
    }

    // ========================================
    // Getters - 관리
    // ========================================

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
