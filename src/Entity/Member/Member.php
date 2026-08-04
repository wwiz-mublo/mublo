<?php
declare(strict_types=1);
namespace Mublo\Entity\Member;

use Mublo\Enum\Member\MemberStatus;

/**
 * Class Member
 *
 * 회원 엔티티
 *
 * 책임:
 * - members 테이블의 데이터를 객체로 표현
 * - 회원 상태/권한 판단 메서드 제공
 *
 * 금지:
 * - DB 직접 접근
 * - 비밀번호 해싱 로직 (Service 담당)
 *
 * 참고:
 * - nickname은 Core 필드 (members 테이블)
 * - email, name, phone 등은 member_field_values 테이블에 저장 (EAV 패턴)
 * - 추가 필드는 MemberService::getFieldValues()로 조회
 */
class Member
{
    // ========================================
    // Core 필드 (members 테이블)
    // ========================================
    protected int $memberId;
    protected int $domainId;
    protected ?int $originDomainId = null;   // 최초 가입 도메인(불변). legacy 회원은 null
    protected string $userId;
    protected string $password;
    protected ?string $nickname = null;
    protected int $levelValue;
    protected ?string $domainGroup;      // 관리자용 권한 범위
    protected bool $canCreateSiteFlag = false;  // 사이트 생성 가능 여부
    protected int $point;
    protected MemberStatus $status;
    protected ?string $lastLoginAt;
    protected ?string $lastLoginIp;
    protected string $createdAt;
    protected ?string $updatedAt;
    protected ?string $withdrawnAt = null;
    protected ?string $withdrawalReason = null;

    // ========================================
    // 등급 정보 (member_levels 조인)
    // ========================================
    protected ?string $levelName = null;
    protected ?string $levelType = null;
    protected bool $isSuper = false;
    protected bool $isAdmin = false;
    protected bool $isMember = true;
    protected bool $canOperateDomainFlag = false;

    // ========================================
    // 추가 필드 값 (member_field_values, 선택적 로드)
    // ========================================
    protected array $fieldValues = [];

    /**
     * DB 로우 데이터로부터 Member 객체 생성
     */
    public static function fromArray(array $data): self
    {
        $member = new self();

        // Core 필드
        $member->memberId = (int) ($data['member_id'] ?? 0);
        $member->domainId = (int) ($data['domain_id'] ?? 0);
        $member->originDomainId = isset($data['origin_domain_id']) && $data['origin_domain_id'] !== null
            ? (int) $data['origin_domain_id']
            : null;
        $member->userId = $data['user_id'] ?? '';
        $member->password = $data['password'] ?? '';
        $member->nickname = $data['nickname'] ?? null;
        $member->levelValue = (int) ($data['level_value'] ?? 1);
        $member->domainGroup = $data['domain_group'] ?? null;
        $member->canCreateSiteFlag = (bool) ($data['can_create_site'] ?? false);
        $member->point = (int) ($data['point_balance'] ?? $data['point'] ?? 0);
        $member->status = MemberStatus::tryFrom($data['status'] ?? 'active') ?? MemberStatus::ACTIVE;
        $member->lastLoginAt = $data['last_login_at'] ?? null;
        $member->lastLoginIp = $data['last_login_ip'] ?? null;
        $member->createdAt = $data['created_at'] ?? '';
        $member->updatedAt = $data['updated_at'] ?? null;
        $member->withdrawnAt = $data['withdrawn_at'] ?? null;
        $member->withdrawalReason = $data['withdrawal_reason'] ?? null;

        // 등급 정보 (조인된 경우)
        $member->levelName = $data['level_name'] ?? null;
        $member->levelType = $data['level_type'] ?? null;
        $member->isSuper = (bool) ($data['is_super'] ?? false);
        $member->isAdmin = (bool) ($data['is_admin'] ?? false);
        $member->isMember = (bool) ($data['is_member'] ?? true);
        $member->canOperateDomainFlag = (bool) ($data['can_operate_domain'] ?? false);

        return $member;
    }

    /**
     * 배열로 변환
     */
    public function toArray(): array
    {
        return [
            'member_id' => $this->memberId,
            'domain_id' => $this->domainId,
            'origin_domain_id' => $this->originDomainId,
            'user_id' => $this->userId,
            'nickname' => $this->nickname,
            'level_value' => $this->levelValue,
            'domain_group' => $this->domainGroup,
            'can_create_site' => $this->canCreateSiteFlag,
            'point' => $this->point,
            'status' => $this->status->value,
            'last_login_at' => $this->lastLoginAt,
            'last_login_ip' => $this->lastLoginIp,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'withdrawn_at' => $this->withdrawnAt,
            'withdrawal_reason' => $this->withdrawalReason,
            // 등급 정보
            'level_name' => $this->levelName,
            'level_type' => $this->levelType,
            'is_super' => $this->isSuper,
            'is_admin' => $this->isAdmin,
            'is_member' => $this->isMember,
            'can_operate_domain' => $this->canOperateDomainFlag,
        ];
    }

    /**
     * 안전한 데이터만 반환 (비밀번호 제외)
     */
    public function toSafeArray(): array
    {
        $data = $this->toArray();
        // 비밀번호는 toArray에 포함되지 않음
        return $data;
    }

    // ========================================
    // Getters - Core 필드
    // ========================================

    public function getMemberId(): int
    {
        return $this->memberId;
    }

    public function getDomainId(): int
    {
        return $this->domainId;
    }

    /**
     * 최초 가입 도메인 ID (불변). legacy 회원은 null.
     */
    public function getOriginDomainId(): ?int
    {
        return $this->originDomainId;
    }

    /**
     * 사이트를 개설해 독립해나간 회원인지 (현재 도메인 ≠ 태생 도메인).
     * origin이 null(legacy)이면 판정 불가로 false.
     */
    public function hasLeftOrigin(): bool
    {
        return $this->originDomainId !== null && $this->originDomainId !== $this->domainId;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function getLevelValue(): int
    {
        return $this->levelValue;
    }

    public function getDomainGroup(): ?string
    {
        return $this->domainGroup;
    }

    public function getPoint(): int
    {
        return $this->point;
    }

    public function getStatus(): MemberStatus
    {
        return $this->status;
    }

    public function getLastLoginAt(): ?string
    {
        return $this->lastLoginAt;
    }

    public function getLastLoginIp(): ?string
    {
        return $this->lastLoginIp;
    }

    public function getCreatedAt(): string
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?string
    {
        return $this->updatedAt;
    }

    public function getWithdrawnAt(): ?string
    {
        return $this->withdrawnAt;
    }

    public function getWithdrawalReason(): ?string
    {
        return $this->withdrawalReason;
    }

    // ========================================
    // Getters - 등급 정보
    // ========================================

    public function getLevelName(): ?string
    {
        return $this->levelName;
    }

    public function getLevelType(): ?string
    {
        return $this->levelType;
    }

    // ========================================
    // 추가 필드 값 (EAV)
    // ========================================

    /**
     * 추가 필드 값 설정 (Service에서 호출)
     */
    public function setFieldValues(array $fieldValues): void
    {
        $this->fieldValues = $fieldValues;
    }

    /**
     * 추가 필드 값 전체 반환
     */
    public function getFieldValues(): array
    {
        return $this->fieldValues;
    }

    /**
     * 특정 추가 필드 값 반환
     */
    public function getFieldValue(string $fieldName, mixed $default = null): mixed
    {
        return $this->fieldValues[$fieldName] ?? $default;
    }

    /**
     * 이메일 반환 (추가 필드)
     */
    public function getEmail(): ?string
    {
        return $this->getFieldValue('email');
    }

    /**
     * 이름 반환 (추가 필드)
     */
    public function getName(): ?string
    {
        return $this->getFieldValue('name');
    }

    /**
     * 닉네임 반환 (Core 필드 우선, EAV 폴백)
     */
    public function getNickname(): ?string
    {
        return $this->nickname ?? $this->getFieldValue('nickname');
    }

    /**
     * 전화번호 반환 (추가 필드)
     */
    public function getPhone(): ?string
    {
        return $this->getFieldValue('phone');
    }

    // ========================================
    // 상태/권한 판단 메서드
    // ========================================

    /**
     * 활성 상태 여부
     */
    public function isActive(): bool
    {
        return $this->status === MemberStatus::ACTIVE;
    }

    /**
     * 비활성 상태 여부
     */
    public function isInactive(): bool
    {
        return $this->status === MemberStatus::INACTIVE;
    }

    /**
     * 휴면 상태 여부
     */
    public function isDormant(): bool
    {
        return $this->status === MemberStatus::DORMANT;
    }

    /**
     * 차단 상태 여부
     */
    public function isBlocked(): bool
    {
        return $this->status === MemberStatus::BLOCKED;
    }

    /**
     * 탈퇴 상태 여부
     */
    public function isWithdrawn(): bool
    {
        return $this->status === MemberStatus::WITHDRAWN;
    }

    /**
     * 슈퍼 관리자 여부
     */
    public function isSuper(): bool
    {
        return $this->isSuper;
    }

    /**
     * 관리자 여부 (슈퍼 또는 일반 관리자)
     */
    public function isAdmin(): bool
    {
        return $this->isAdmin || $this->isSuper;
    }

    /**
     * 일반 회원 여부
     */
    public function isMember(): bool
    {
        return $this->isMember;
    }

    /**
     * 접근 가능 여부 (활성 상태)
     */
    public function isAccessible(): bool
    {
        return $this->isActive();
    }

    /**
     * 도메인 운영 가능 여부
     *
     * 슈퍼관리자이거나 can_operate_domain 권한이 있으면 true
     */
    public function canOperateDomain(): bool
    {
        return $this->isSuper || $this->canOperateDomainFlag;
    }

    /**
     * 사이트(도메인) 생성 가능 여부
     *
     * 슈퍼관리자이거나 can_create_site 플래그가 true이면 true
     */
    public function canCreateSite(): bool
    {
        return $this->isSuper || $this->canCreateSiteFlag;
    }

    /**
     * 특정 도메인 그룹 하위 관리 권한 여부
     */
    public function canManageDomainGroup(string $targetGroup): bool
    {
        if ($this->isSuper()) {
            return true;
        }

        if (empty($this->domainGroup)) {
            return false;
        }

        // 자신의 도메인 그룹 또는 하위 그룹만 관리 가능
        return str_starts_with($targetGroup, $this->domainGroup);
    }

    // ========================================
    // 표시용 메서드
    // ========================================

    /**
     * 표시명 반환 (닉네임 우선, 없으면 이름, 없으면 userId)
     */
    public function getDisplayName(): string
    {
        return $this->getNickname() ?: $this->getName() ?: $this->userId;
    }

    /**
     * 마스킹된 이메일 반환
     */
    public function getMaskedEmail(): string
    {
        $email = $this->getEmail();
        if (empty($email)) {
            return '';
        }

        $parts = explode('@', $email);
        if (count($parts) !== 2) {
            return $email;
        }

        $local = $parts[0];
        $domain = $parts[1];

        $visibleLength = min(3, strlen($local));
        $maskedLocal = substr($local, 0, $visibleLength) . str_repeat('*', max(0, strlen($local) - $visibleLength));

        return $maskedLocal . '@' . $domain;
    }

    /**
     * 마스킹된 전화번호 반환
     */
    public function getMaskedPhone(): string
    {
        $phone = $this->getPhone();
        if (empty($phone)) {
            return '';
        }

        // 숫자만 추출
        $digits = preg_replace('/[^0-9]/', '', $phone);

        if (strlen($digits) >= 10) {
            return substr($digits, 0, 3) . '-****-' . substr($digits, -4);
        }

        return $phone;
    }
}
