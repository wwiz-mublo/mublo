<?php
namespace Mublo\Packages\Board\Entity;

use DateTimeImmutable;
use Mublo\Packages\Board\Enum\PermissionType;
use Mublo\Packages\Board\Enum\PermissionTargetType;

/**
 * Class BoardPermission
 *
 * 게시판 권한 엔티티 (통합 권한 테이블)
 *
 * 책임:
 * - board_permissions 테이블의 데이터를 객체로 표현
 * - 그룹/카테고리/게시판 관리자 권한 통합 관리
 *
 * 금지:
 * - DB 직접 접근
 * - 비즈니스 로직 (Service 담당)
 */
class BoardPermission
{
    // ========================================
    // 필드
    // ========================================
    protected int $permissionId;
    protected int $domainId;
    protected PermissionTargetType $targetType;
    protected int $targetId;
    protected int $memberId;
    protected PermissionType $permissionType;
    protected DateTimeImmutable $createdAt;

    /**
     * DB 로우 데이터로부터 BoardPermission 객체 생성
     */
    public static function fromArray(array $data): self
    {
        $permission = new self();

        $permission->permissionId = (int) ($data['permission_id'] ?? 0);
        $permission->domainId = (int) ($data['domain_id'] ?? 0);
        $permission->targetType = PermissionTargetType::tryFrom($data['target_type'] ?? 'group') ?? PermissionTargetType::GROUP;
        $permission->targetId = (int) ($data['target_id'] ?? 0);
        $permission->memberId = (int) ($data['member_id'] ?? 0);
        $permission->permissionType = PermissionType::tryFrom($data['permission_type'] ?? 'admin') ?? PermissionType::ADMIN;

        $permission->createdAt = self::parseDateTime($data['created_at'] ?? null);

        return $permission;
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
            'permission_id' => $this->permissionId,
            'domain_id' => $this->domainId,
            'target_type' => $this->targetType->value,
            'target_id' => $this->targetId,
            'member_id' => $this->memberId,
            'permission_type' => $this->permissionType->value,
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
        ];
    }

    // ========================================
    // Getters
    // ========================================

    public function getPermissionId(): int
    {
        return $this->permissionId;
    }

    public function getDomainId(): int
    {
        return $this->domainId;
    }

    public function getTargetType(): PermissionTargetType
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

    public function getPermissionType(): PermissionType
    {
        return $this->permissionType;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    // ========================================
    // 타입 판단 메서드
    // ========================================

    /**
     * 그룹 관리자 권한인지 확인
     */
    public function isGroupPermission(): bool
    {
        return $this->targetType === PermissionTargetType::GROUP;
    }

    /**
     * 카테고리 관리자 권한인지 확인
     */
    public function isCategoryPermission(): bool
    {
        return $this->targetType === PermissionTargetType::CATEGORY;
    }

    /**
     * 게시판 관리자 권한인지 확인
     */
    public function isBoardPermission(): bool
    {
        return $this->targetType === PermissionTargetType::BOARD;
    }

    /**
     * 어드민 권한인지 확인
     */
    public function isAdmin(): bool
    {
        return $this->permissionType === PermissionType::ADMIN;
    }

    /**
     * 모더레이터 권한인지 확인
     */
    public function isModerator(): bool
    {
        return $this->permissionType === PermissionType::MODERATOR;
    }

    /**
     * 에디터 권한인지 확인
     */
    public function isEditor(): bool
    {
        return $this->permissionType === PermissionType::EDITOR;
    }
}
