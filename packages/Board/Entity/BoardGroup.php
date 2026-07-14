<?php
namespace Mublo\Packages\Board\Entity;

use DateTimeImmutable;

/**
 * Class BoardGroup
 *
 * 게시판 그룹 엔티티
 *
 * 책임:
 * - board_groups 테이블의 데이터를 객체로 표현
 * - 그룹 레벨 기반 접근 권한 판단 메서드 제공
 *
 * 금지:
 * - DB 직접 접근
 * - 비즈니스 로직 (Service 담당)
 *
 * Note: 그룹 관리자는 별도 매핑 테이블(board_group_admins)로 관리
 *       → BoardGroupAdminRepository 참조
 */
class BoardGroup
{
    // ========================================
    // 기본 필드
    // ========================================
    protected int $groupId;
    protected int $domainId;
    protected string $groupSlug;
    protected string $groupName;
    protected ?string $groupDescription;

    // ========================================
    // 권한 레벨
    // ========================================
    protected int $listLevel;
    protected int $readLevel;
    protected int $writeLevel;
    protected int $commentLevel;
    protected int $downloadLevel;

    // ========================================
    // 관리
    // ========================================
    protected int $sortOrder;
    protected bool $isActive;
    protected DateTimeImmutable $createdAt;
    protected DateTimeImmutable $updatedAt;

    /**
     * DB 로우 데이터로부터 BoardGroup 객체 생성
     */
    public static function fromArray(array $data): self
    {
        $group = new self();

        // 기본 필드
        $group->groupId = (int) ($data['group_id'] ?? 0);
        $group->domainId = (int) ($data['domain_id'] ?? 0);
        $group->groupSlug = $data['group_slug'] ?? '';
        $group->groupName = $data['group_name'] ?? '';
        $group->groupDescription = $data['group_description'] ?? null;

        // Note: group_admin_ids는 별도 매핑 테이블로 이동 (board_group_admins)

        // 권한 레벨 (기본값 설정)
        $group->listLevel = (int) ($data['list_level'] ?? 0);
        $group->readLevel = (int) ($data['read_level'] ?? 0);
        $group->writeLevel = (int) ($data['write_level'] ?? 1);
        $group->commentLevel = (int) ($data['comment_level'] ?? 1);
        $group->downloadLevel = (int) ($data['download_level'] ?? 0);

        // 관리
        $group->sortOrder = (int) ($data['sort_order'] ?? 0);
        $group->isActive = (bool) ($data['is_active'] ?? true);

        // 날짜
        $group->createdAt = self::parseDateTime($data['created_at'] ?? null);
        $group->updatedAt = self::parseDateTime($data['updated_at'] ?? null);

        return $group;
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
            'group_id' => $this->groupId,
            'domain_id' => $this->domainId,
            'group_slug' => $this->groupSlug,
            'group_name' => $this->groupName,
            'group_description' => $this->groupDescription,
            // Note: group_admin_ids는 별도 매핑 테이블 (board_group_admins)
            'list_level' => $this->listLevel,
            'read_level' => $this->readLevel,
            'write_level' => $this->writeLevel,
            'comment_level' => $this->commentLevel,
            'download_level' => $this->downloadLevel,
            'sort_order' => $this->sortOrder,
            'is_active' => $this->isActive,
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
            'updated_at' => $this->updatedAt->format('Y-m-d H:i:s'),
        ];
    }

    // ========================================
    // Getters - 기본 필드
    // ========================================

    public function getGroupId(): int
    {
        return $this->groupId;
    }

    public function getDomainId(): int
    {
        return $this->domainId;
    }

    public function getGroupSlug(): string
    {
        return $this->groupSlug;
    }

    public function getGroupName(): string
    {
        return $this->groupName;
    }

    public function getGroupDescription(): ?string
    {
        return $this->groupDescription;
    }

    // Note: getGroupAdminIds() 제거 - 별도 매핑 테이블로 이동 (BoardGroupAdminRepository 참조)

    // ========================================
    // Getters - 권한 레벨
    // ========================================

    public function getListLevel(): int
    {
        return $this->listLevel;
    }

    public function getReadLevel(): int
    {
        return $this->readLevel;
    }

    public function getWriteLevel(): int
    {
        return $this->writeLevel;
    }

    public function getCommentLevel(): int
    {
        return $this->commentLevel;
    }

    public function getDownloadLevel(): int
    {
        return $this->downloadLevel;
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

    // ========================================
    // 권한 판단 메서드
    // ========================================

    // Note: isAdmin() 제거 - 별도 매핑 테이블로 이동 (BoardGroupAdminRepository::isAdmin() 참조)

    /**
     * 목록 보기 권한 확인
     */
    public function canList(int $memberLevel): bool
    {
        return $memberLevel >= $this->listLevel;
    }

    /**
     * 글 읽기 권한 확인
     */
    public function canRead(int $memberLevel): bool
    {
        return $memberLevel >= $this->readLevel;
    }

    /**
     * 글쓰기 권한 확인
     */
    public function canWrite(int $memberLevel): bool
    {
        return $memberLevel >= $this->writeLevel;
    }

    /**
     * 댓글 쓰기 권한 확인
     */
    public function canComment(int $memberLevel): bool
    {
        return $memberLevel >= $this->commentLevel;
    }

    /**
     * 다운로드 권한 확인
     */
    public function canDownload(int $memberLevel): bool
    {
        return $memberLevel >= $this->downloadLevel;
    }
}
