<?php
namespace Mublo\Entity\Member;

use Mublo\Enum\Policy\PolicyType;

/**
 * Class Policy
 *
 * 정책/약관 엔티티
 *
 * 책임:
 * - policies 테이블의 데이터를 객체로 표현
 * - 정책 상태/타입 판단 메서드 제공
 */
class Policy
{
    // ========================================
    // 속성
    // ========================================

    protected int $policyId = 0;
    protected int $domainId = 0;

    // 정책 식별
    protected string $slug = '';
    protected PolicyType $policyType;

    // 정책 내용
    protected string $policyTitle = '';
    protected string $policyContent = '';
    protected string $policyVersion = '1.0';
    protected ?int $currentRevisionId = null;

    // 동의 설정
    protected bool $isRequired = true;
    protected bool $isActive = true;
    protected int $sortOrder = 0;

    // 회원가입 출력
    protected bool $showInRegister = false;

    // 시간
    protected ?string $createdAt = null;
    protected ?string $updatedAt = null;

    // ========================================
    // Getters - 기본 정보
    // ========================================

    public function getPolicyId(): int
    {
        return $this->policyId;
    }

    public function getDomainId(): int
    {
        return $this->domainId;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getPolicyType(): PolicyType
    {
        return $this->policyType;
    }

    public function getPolicyTypeLabel(): string
    {
        return $this->policyType->label();
    }

    public function getPolicyTitle(): string
    {
        return $this->policyTitle;
    }

    public function getPolicyContent(): string
    {
        return $this->policyContent;
    }

    public function getPolicyVersion(): string
    {
        return $this->policyVersion;
    }

    public function getCurrentRevisionId(): ?int
    {
        return $this->currentRevisionId;
    }

    // ========================================
    // Getters - 동의 설정
    // ========================================

    public function isRequired(): bool
    {
        return $this->isRequired;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    // ========================================
    // Getters - 회원가입 출력
    // ========================================

    public function showInRegister(): bool
    {
        return $this->showInRegister;
    }

    // ========================================
    // Getters - 시간
    // ========================================

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?string
    {
        return $this->updatedAt;
    }

    // ========================================
    // 상태 판단 메서드
    // ========================================

    /**
     * 필수 약관 여부 (이용약관, 개인정보처리방침)
     */
    public function isEssentialType(): bool
    {
        return $this->policyType->isEssential();
    }

    /**
     * 선택 약관 여부 (마케팅 등)
     */
    public function isOptionalType(): bool
    {
        return $this->policyType->isOptional();
    }

    // ========================================
    // Factory
    // ========================================

    public static function fromArray(array $data): self
    {
        $entity = new self();
        $entity->policyId = (int) ($data['policy_id'] ?? 0);
        $entity->domainId = (int) ($data['domain_id'] ?? 0);

        // 정책 식별
        $entity->slug = $data['slug'] ?? '';
        $entity->policyType = PolicyType::tryFrom($data['policy_type'] ?? 'custom') ?? PolicyType::CUSTOM;

        // 정책 내용 (DB 컬럼명 title/content/version 기준, 신 스키마 호환)
        $entity->policyTitle = $data['title'] ?? $data['policy_title'] ?? '';
        $entity->policyContent = $data['content'] ?? $data['policy_content'] ?? '';
        $entity->policyVersion = $data['version'] ?? $data['policy_version'] ?? '1.0';
        $entity->currentRevisionId = isset($data['current_revision_id'])
            ? (int) $data['current_revision_id']
            : null;

        // 동의 설정
        $entity->isRequired = (bool) ($data['is_required'] ?? true);
        $entity->isActive = (bool) ($data['is_active'] ?? true);
        $entity->sortOrder = (int) ($data['sort_order'] ?? 0);

        // 회원가입 출력
        $entity->showInRegister = (bool) ($data['show_in_register'] ?? false);

        // 시간
        $entity->createdAt = $data['created_at'] ?? null;
        $entity->updatedAt = $data['updated_at'] ?? null;

        return $entity;
    }

    public function toArray(): array
    {
        return [
            'policy_id' => $this->policyId,
            'domain_id' => $this->domainId,
            'slug' => $this->slug,
            'policy_type' => $this->policyType->value,
            'title' => $this->policyTitle,
            'content' => $this->policyContent,
            'version' => $this->policyVersion,
            'current_revision_id' => $this->currentRevisionId,
            'is_required' => $this->isRequired,
            'is_active' => $this->isActive,
            'sort_order' => $this->sortOrder,
            'show_in_register' => $this->showInRegister,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    /**
     * DB 저장용 배열 반환 (PK 제외, DB 컬럼명 기준)
     */
    public function toDbArray(): array
    {
        return [
            'domain_id' => $this->domainId,
            'slug' => $this->slug,
            'policy_type' => $this->policyType->value,
            'title' => $this->policyTitle,
            'content' => $this->policyContent,
            'version' => $this->policyVersion,
            'is_required' => $this->isRequired,
            'is_active' => $this->isActive,
            'sort_order' => $this->sortOrder,
            'show_in_register' => $this->showInRegister,
        ];
    }
}
