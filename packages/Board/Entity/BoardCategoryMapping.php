<?php
namespace Mublo\Packages\Board\Entity;

/**
 * BoardCategoryMapping Entity
 *
 * 게시판-카테고리 매핑 엔티티
 *
 * 책임:
 * - board_category_mapping 테이블 데이터 표현
 * - 불변 객체 패턴
 */
final class BoardCategoryMapping
{
    private int $mappingId;
    private int $boardId;
    private int $categoryId;

    // 권한 오버라이드 (NULL: 게시판 설정 따름)
    private ?int $listLevel;
    private ?int $readLevel;
    private ?int $writeLevel;
    private ?int $commentLevel;
    private ?int $downloadLevel;

    // 관리
    private int $sortOrder;
    private bool $isActive;
    private \DateTimeImmutable $createdAt;

    private function __construct() {}

    /**
     * 배열에서 Entity 생성
     */
    public static function fromArray(array $data): self
    {
        $entity = new self();

        $entity->mappingId = (int) ($data['mapping_id'] ?? 0);
        $entity->boardId = (int) ($data['board_id'] ?? 0);
        $entity->categoryId = (int) ($data['category_id'] ?? 0);

        // 권한 오버라이드
        $entity->listLevel = isset($data['list_level']) ? (int) $data['list_level'] : null;
        $entity->readLevel = isset($data['read_level']) ? (int) $data['read_level'] : null;
        $entity->writeLevel = isset($data['write_level']) ? (int) $data['write_level'] : null;
        $entity->commentLevel = isset($data['comment_level']) ? (int) $data['comment_level'] : null;
        $entity->downloadLevel = isset($data['download_level']) ? (int) $data['download_level'] : null;

        // 관리
        $entity->sortOrder = (int) ($data['sort_order'] ?? 0);
        $entity->isActive = (bool) ($data['is_active'] ?? true);
        $entity->createdAt = self::parseDateTime($data['created_at'] ?? 'now');

        return $entity;
    }

    /**
     * Entity를 배열로 변환
     */
    public function toArray(): array
    {
        return [
            'mapping_id' => $this->mappingId,
            'board_id' => $this->boardId,
            'category_id' => $this->categoryId,
            'list_level' => $this->listLevel,
            'read_level' => $this->readLevel,
            'write_level' => $this->writeLevel,
            'comment_level' => $this->commentLevel,
            'download_level' => $this->downloadLevel,
            'sort_order' => $this->sortOrder,
            'is_active' => $this->isActive,
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
        ];
    }

    // === Getters ===

    public function getMappingId(): int
    {
        return $this->mappingId;
    }

    public function getBoardId(): int
    {
        return $this->boardId;
    }

    public function getCategoryId(): int
    {
        return $this->categoryId;
    }

    public function getListLevel(): ?int
    {
        return $this->listLevel;
    }

    public function getReadLevel(): ?int
    {
        return $this->readLevel;
    }

    public function getWriteLevel(): ?int
    {
        return $this->writeLevel;
    }

    public function getCommentLevel(): ?int
    {
        return $this->commentLevel;
    }

    public function getDownloadLevel(): ?int
    {
        return $this->downloadLevel;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    // === 상태 판단 메서드 ===

    /**
     * 권한 오버라이드가 있는지 확인
     */
    public function hasPermissionOverride(): bool
    {
        return $this->listLevel !== null
            || $this->readLevel !== null
            || $this->writeLevel !== null
            || $this->commentLevel !== null
            || $this->downloadLevel !== null;
    }

    /**
     * 특정 권한 레벨 반환 (없으면 null)
     */
    public function getLevel(string $type): ?int
    {
        return match ($type) {
            'list' => $this->listLevel,
            'read' => $this->readLevel,
            'write' => $this->writeLevel,
            'comment' => $this->commentLevel,
            'download' => $this->downloadLevel,
            default => null,
        };
    }

    /**
     * DateTime 파싱 헬퍼
     */
    private static function parseDateTime(string $datetime): \DateTimeImmutable
    {
        return new \DateTimeImmutable($datetime);
    }
}
