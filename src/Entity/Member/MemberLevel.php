<?php
declare(strict_types=1);
namespace Mublo\Entity\Member;

use Mublo\Contract\Member\MemberLevelType;

/**
 * Class MemberLevel
 *
 * 회원 등급 엔티티 (전역)
 *
 * 책임:
 * - member_levels 테이블의 데이터를 객체로 표현
 * - 등급 타입/권한 판단 메서드 제공
 *
 * Note: 이 테이블은 전역 테이블로, 모든 도메인이 동일한 레벨 체계를 공유합니다.
 *       슈퍼관리자만 레벨을 생성/수정/삭제할 수 있습니다.
 */
class MemberLevel
{
    // ========================================
    // 레벨 타입 상수 (고정값)
    // ========================================

    public const TYPE_SUPER = MemberLevelType::SUPER;       // 최고관리자
    public const TYPE_STAFF = MemberLevelType::STAFF;       // 스태프/직원
    public const TYPE_PARTNER = MemberLevelType::PARTNER;   // 파트너
    public const TYPE_SITE_MASTER = MemberLevelType::SITE_MASTER; // 사이트 운영자 (해당 도메인의 최고 권한)
    public const TYPE_SUPPLIER = MemberLevelType::SUPPLIER;  // 공급처
    public const TYPE_BASIC = MemberLevelType::BASIC;       // 일반회원

    /**
     * 레벨 타입 목록 (타입 => 라벨)
     */
    public const LEVEL_TYPES = [
        self::TYPE_SUPER => '최고관리자',
        self::TYPE_STAFF => '스태프/직원',
        self::TYPE_PARTNER => '파트너',
        self::TYPE_SITE_MASTER => '사이트 운영자',
        self::TYPE_SUPPLIER => '공급처',
        self::TYPE_BASIC => '일반회원',
    ];

    // ========================================
    // 속성
    // ========================================

    protected int $levelId = 0;
    protected int $levelValue = 0;
    protected string $levelName = '';
    protected string $levelType = self::TYPE_BASIC;

    // 역할 구분
    protected bool $isSuper = false;          // 최고관리자 (전체 시스템)
    protected bool $isAdmin = false;          // 관리자 모드 접근 권한 (스태프 등)
    protected bool $canOperateDomain = false; // 도메인 소유/운영 가능 여부

    // 시간
    protected ?string $createdAt = null;
    protected ?string $updatedAt = null;

    // ========================================
    // Getters - 기본 정보
    // ========================================

    public function getLevelId(): int
    {
        return $this->levelId;
    }

    public function getLevelValue(): int
    {
        return $this->levelValue;
    }

    public function getLevelName(): string
    {
        return $this->levelName;
    }

    public function getLevelType(): string
    {
        return $this->levelType;
    }

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?string
    {
        return $this->updatedAt;
    }

    // ========================================
    // Getters - 역할 판단
    // ========================================

    /**
     * 최고관리자 여부 (전체 시스템 관리)
     */
    public function isSuper(): bool
    {
        return $this->isSuper;
    }

    /**
     * 관리자 모드 접근 가능 여부
     * (is_admin이 true이거나 is_super이면 관리자 모드 접근 가능)
     */
    public function canAccessAdmin(): bool
    {
        return $this->isAdmin || $this->isSuper;
    }

    /**
     * @deprecated Use canAccessAdmin() instead
     */
    public function isAdmin(): bool
    {
        return $this->canAccessAdmin();
    }

    /**
     * 도메인 소유/운영 가능 여부
     */
    public function canOperateDomain(): bool
    {
        return $this->canOperateDomain || $this->isSuper;
    }

    /**
     * 일반 회원 등급 여부 (관리자 모드 접근 불가)
     */
    public function isMember(): bool
    {
        return !$this->canAccessAdmin();
    }

    // ========================================
    // Factory
    // ========================================

    public static function fromArray(array $data): self
    {
        $entity = new self();
        $entity->levelId = (int) ($data['level_id'] ?? 0);
        $entity->levelValue = (int) ($data['level_value'] ?? 0);
        $entity->levelName = $data['level_name'] ?? '';
        $entity->levelType = $data['level_type'] ?? 'BASIC';

        // 역할 구분
        $entity->isSuper = (bool) ($data['is_super'] ?? false);
        $entity->isAdmin = (bool) ($data['is_admin'] ?? false);
        $entity->canOperateDomain = (bool) ($data['can_operate_domain'] ?? false);

        // 시간
        $entity->createdAt = $data['created_at'] ?? null;
        $entity->updatedAt = $data['updated_at'] ?? null;

        return $entity;
    }

    public function toArray(): array
    {
        return [
            'level_id' => $this->levelId,
            'level_value' => $this->levelValue,
            'level_name' => $this->levelName,
            'level_type' => $this->levelType,
            'is_super' => $this->isSuper,
            'is_admin' => $this->isAdmin,
            'can_operate_domain' => $this->canOperateDomain,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    /**
     * DB 저장용 배열 반환 (PK 제외)
     */
    public function toDbArray(): array
    {
        $data = $this->toArray();
        unset($data['level_id'], $data['created_at'], $data['updated_at']);
        return $data;
    }
}
