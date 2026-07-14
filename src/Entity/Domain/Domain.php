<?php
namespace Mublo\Entity\Domain;

/**
 * Class Domain
 *
 * domain_configs 테이블 엔티티
 */
class Domain
{
    private int $domainId;
    private string $domain;
    private string $domainGroup;              // 계층 구조 (예: 1/3/5)
    private ?int $memberId;                   // 소유자 회원 ID
    private string $status;                   // active, inactive, blocked
    private int $storageLimit;                // 저장공간 제한 (bytes)
    private int $memberLimit;                 // 회원수 제한
    private array $siteConfig;                // JSON 설정
    private array $companyConfig;             // JSON 설정 (회사 정보)
    private array $seoConfig;                 // JSON 설정
    private array $themeConfig;               // JSON 설정
    private array $extensionConfig;           // JSON 설정 (플러그인/패키지 활성화)
    private array $extraConfig;               // JSON 설정 (확장/임시)
    private ?string $createdAt;
    private ?string $updatedAt;

    public function __construct(
        int $domainId,
        string $domain,
        string $domainGroup = '',
        ?int $memberId = null,
        string $status = 'active',
        int $storageLimit = 1073741824,       // 1GB
        int $memberLimit = 0,
        array $siteConfig = [],
        array $companyConfig = [],
        array $seoConfig = [],
        array $themeConfig = [],
        array $extensionConfig = [],
        array $extraConfig = [],
        ?string $createdAt = null,
        ?string $updatedAt = null
    ) {
        $this->domainId = $domainId;
        $this->domain = $domain;
        $this->domainGroup = $domainGroup;
        $this->memberId = $memberId;
        $this->status = $status;
        $this->storageLimit = $storageLimit;
        $this->memberLimit = $memberLimit;
        $this->siteConfig = $siteConfig;
        $this->companyConfig = $companyConfig;
        $this->seoConfig = $seoConfig;
        $this->themeConfig = $themeConfig;
        $this->extensionConfig = $extensionConfig;
        $this->extraConfig = $extraConfig;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
    }

    /**
     * DB 배열 데이터를 Entity로 변환
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (int)$data['domain_id'],
            $data['domain'],
            $data['domain_group'] ?? '',
            isset($data['member_id']) ? (int)$data['member_id'] : null,
            $data['status'] ?? 'active',
            (int)($data['storage_limit'] ?? 1073741824),
            (int)($data['member_limit'] ?? 0),
            self::parseJsonField($data['site_config'] ?? null),
            self::parseJsonField($data['company_config'] ?? null),
            self::parseJsonField($data['seo_config'] ?? null),
            self::parseJsonField($data['theme_config'] ?? null),
            self::parseJsonField($data['extension_config'] ?? null),
            self::parseJsonField($data['extra_config'] ?? null),
            $data['created_at'] ?? null,
            $data['updated_at'] ?? null
        );
    }

    /**
     * JSON 필드 파싱 헬퍼
     */
    private static function parseJsonField(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            return json_decode($value, true) ?? [];
        }
        return [];
    }

    /**
     * Entity를 배열로 변환 (캐싱용)
     */
    public function toArray(): array
    {
        return [
            'domain_id' => $this->domainId,
            'domain' => $this->domain,
            'domain_group' => $this->domainGroup,
            'member_id' => $this->memberId,
            'status' => $this->status,
            'storage_limit' => $this->storageLimit,
            'member_limit' => $this->memberLimit,
            'site_config' => $this->siteConfig,
            'company_config' => $this->companyConfig,
            'seo_config' => $this->seoConfig,
            'theme_config' => $this->themeConfig,
            'extension_config' => $this->extensionConfig,
            'extra_config' => $this->extraConfig,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    // =========================================================================
    // Getters - 기본 정보
    // =========================================================================

    public function getDomainId(): int
    {
        return $this->domainId;
    }

    public function getDomain(): string
    {
        return $this->domain;
    }

    public function getDomainGroup(): string
    {
        return $this->domainGroup;
    }

    public function getMemberId(): ?int
    {
        return $this->memberId;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    // =========================================================================
    // Getters - 자원 제한
    // =========================================================================

    public function getStorageLimit(): int
    {
        return $this->storageLimit;
    }

    /**
     * 저장공간 제한을 사람이 읽기 쉬운 형태로 반환
     */
    public function getStorageLimitFormatted(): string
    {
        $bytes = $this->storageLimit;
        if ($bytes >= 1099511627776) {
            return round($bytes / 1099511627776, 2) . ' TB';
        }
        if ($bytes >= 1073741824) {
            return round($bytes / 1073741824, 2) . ' GB';
        }
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        }
        return round($bytes / 1024, 2) . ' KB';
    }

    public function getMemberLimit(): int
    {
        return $this->memberLimit;
    }

    // =========================================================================
    // Getters - JSON 설정
    // =========================================================================

    public function getSiteConfig(): array
    {
        return $this->siteConfig;
    }

    public function getCompanyConfig(): array
    {
        return $this->companyConfig;
    }

    public function getSeoConfig(): array
    {
        return $this->seoConfig;
    }

    public function getThemeConfig(): array
    {
        return $this->themeConfig;
    }

    public function getExtensionConfig(): array
    {
        return $this->extensionConfig;
    }

    public function getExtraConfig(): array
    {
        return $this->extraConfig;
    }

    // =========================================================================
    // Getters - 타임스탬프
    // =========================================================================

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?string
    {
        return $this->updatedAt;
    }

    // =========================================================================
    // 상태 판단 메서드
    // =========================================================================

    /**
     * 활성 상태 여부
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * 비활성 상태 여부
     */
    public function isInactive(): bool
    {
        return $this->status === 'inactive';
    }

    /**
     * 차단 상태 여부
     */
    public function isBlocked(): bool
    {
        return $this->status === 'blocked';
    }

    /**
     * 접속 가능 여부
     *
     * 코어는 status 만 본다. 계약 만료 차단 같은 상용/임대 정책은
     * 해당 패키지가 자체 데이터로 판단해 status 를 변경(inactive/blocked)하는
     * 방식으로 표현한다 — 정책은 패키지, 메커니즘(status)은 코어.
     */
    public function isAccessible(): bool
    {
        return $this->isActive();
    }

    // =========================================================================
    // 설정 값 편의 메서드
    // =========================================================================

    /**
     * 사이트 타이틀 반환
     */
    public function getSiteTitle(): string
    {
        return $this->siteConfig['site_title'] ?? '';
    }

    /**
     * 관리자 이메일 반환
     */
    public function getAdminEmail(): string
    {
        return $this->siteConfig['admin_email'] ?? '';
    }

    /**
     * 아이디를 이메일로 사용하는지 여부
     */
    public function isUseEmailAsUserId(): bool
    {
        return (bool) ($this->siteConfig['use_email_as_userid'] ?? false);
    }

    /**
     * 회원가입 방식 (immediate: 바로 가입, approval: 관리자 승인)
     */
    public function getJoinType(): string
    {
        return $this->siteConfig['join_type'] ?? 'immediate';
    }

    /**
     * 가입 시 적용할 기본 레벨
     */
    public function getDefaultLevelValue(): int
    {
        return (int) ($this->siteConfig['default_level_value'] ?? 1);
    }

    /**
     * 활성화된 플러그인 목록
     */
    public function getEnabledPlugins(): array
    {
        return $this->extensionConfig['plugins'] ?? [];
    }

    /**
     * 활성화된 패키지 목록
     */
    public function getEnabledPackages(): array
    {
        return $this->extensionConfig['packages'] ?? [];
    }

    /**
     * 특정 플러그인 활성화 여부
     */
    public function isPluginEnabled(string $pluginName): bool
    {
        return in_array($pluginName, $this->getEnabledPlugins(), true);
    }

    /**
     * 특정 패키지 활성화 여부
     */
    public function isPackageEnabled(string $packageName): bool
    {
        return in_array($packageName, $this->getEnabledPackages(), true);
    }
}
