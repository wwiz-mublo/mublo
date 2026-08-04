<?php
declare(strict_types=1);
namespace Mublo\Entity\Menu;

use Mublo\Entity\BaseEntity;
use Mublo\Enum\Common\ProviderType;

/**
 * MenuItem Entity
 *
 * 메뉴 아이템 엔티티 (menu_items 테이블)
 *
 * 책임:
 * - menu_items 테이블의 데이터를 객체로 표현
 * - 메뉴 표시/접근 권한 판단 메서드 제공
 *
 * 금지:
 * - DB 직접 접근
 */
class MenuItem extends BaseEntity
{
    // ========================================
    // 기본 정보
    // ========================================
    protected int $itemId = 0;
    protected int $domainId = 0;
    protected string $menuCode = '';
    protected string $label = '';
    protected ?string $url = null;
    protected ?string $icon = null;
    protected string $target = '_self';

    // ========================================
    // 접근 제어 (레벨 기반)
    // ========================================
    protected string $visibility = 'all';  // all | guest | member
    protected ?string $pairCode = null;    // 메뉴 쌍 코드 (같은 값 = 한 묶음)
    protected int $minLevel = 0;  // 0: 비회원 포함 모든 사용자
    protected ?string $requiredPermission = null;

    // ========================================
    // 디바이스별 표시
    // ========================================
    protected bool $showOnPc = true;
    protected bool $showOnMobile = true;

    // ========================================
    // 유틸리티/푸터 메뉴
    // ========================================
    protected bool $showInUtility = false;
    protected bool $showInFooter = false;
    protected int $utilityOrder = 0;
    protected int $footerOrder = 0;

    // ========================================
    // 제공자 (Plugin/Package)
    // ========================================
    protected ProviderType $providerType;
    protected ?string $providerName = null;

    // ========================================
    // 레이아웃 오버라이드 (NULL = 상속: 도메인 기본을 따름)
    // 블록 페이지는 자체 레이아웃을 가지므로 이 오버라이드의 영향을 받지 않는다.
    // ========================================
    protected ?int $layoutType = null;          // NULL=상속, 1:전체 2:좌 3:우 4:양쪽
    protected ?int $sidebarLeftWidth = null;    // NULL=사이트 기본
    protected ?bool $sidebarLeftMobile = null;
    protected ?int $sidebarRightWidth = null;
    protected ?bool $sidebarRightMobile = null;

    // ========================================
    // 상태
    // ========================================
    protected bool $isActive = true;

    // ========================================
    // 상수
    // ========================================
    public const TARGET_SELF = '_self';
    public const TARGET_BLANK = '_blank';

    /**
     * 기본키 필드명
     */
    protected function getPrimaryKeyField(): string
    {
        return 'itemId';
    }

    /**
     * DB 로우 데이터로부터 객체 생성
     */
    public static function fromArray(array $data): self
    {
        $entity = new self();

        // 기본 정보
        $entity->itemId = (int) ($data['item_id'] ?? 0);
        $entity->domainId = (int) ($data['domain_id'] ?? 0);
        $entity->menuCode = $data['menu_code'] ?? '';
        $entity->label = $data['label'] ?? '';
        $entity->url = $data['url'] ?? null;
        $entity->icon = $data['icon'] ?? null;
        $entity->target = $data['target'] ?? '_self';

        // 접근 제어 (레벨 기반)
        $entity->visibility = $data['visibility'] ?? 'all';
        $entity->pairCode = $data['pair_code'] ?? null;
        $entity->minLevel = (int) ($data['min_level'] ?? 0);
        $entity->requiredPermission = $data['required_permission'] ?? null;

        // 디바이스
        $entity->showOnPc = (bool) ($data['show_on_pc'] ?? true);
        $entity->showOnMobile = (bool) ($data['show_on_mobile'] ?? true);

        // 유틸리티/푸터
        $entity->showInUtility = (bool) ($data['show_in_utility'] ?? false);
        $entity->showInFooter = (bool) ($data['show_in_footer'] ?? false);
        $entity->utilityOrder = (int) ($data['utility_order'] ?? 0);
        $entity->footerOrder = (int) ($data['footer_order'] ?? 0);

        // 제공자
        $entity->providerType = ProviderType::tryFrom($data['provider_type'] ?? 'core') ?? ProviderType::CORE;
        $entity->providerName = $data['provider_name'] ?? null;

        // 레이아웃 오버라이드 (NULL/'' = 상속)
        $entity->layoutType = self::nullableInt($data['layout_type'] ?? null);
        $entity->sidebarLeftWidth = self::nullableInt($data['sidebar_left_width'] ?? null);
        $entity->sidebarRightWidth = self::nullableInt($data['sidebar_right_width'] ?? null);
        $entity->sidebarLeftMobile = self::nullableBool($data['sidebar_left_mobile'] ?? null);
        $entity->sidebarRightMobile = self::nullableBool($data['sidebar_right_mobile'] ?? null);

        // 상태
        $entity->isActive = (bool) ($data['is_active'] ?? true);

        // 타임스탬프
        $entity->createdAt = $data['created_at'] ?? '';
        $entity->updatedAt = $data['updated_at'] ?? null;

        return $entity;
    }

    /**
     * 배열로 변환
     */
    public function toArray(): array
    {
        return [
            'item_id' => $this->itemId,
            'domain_id' => $this->domainId,
            'menu_code' => $this->menuCode,
            'label' => $this->label,
            'url' => $this->url,
            'icon' => $this->icon,
            'target' => $this->target,
            'visibility' => $this->visibility,
            'pair_code' => $this->pairCode,
            'min_level' => $this->minLevel,
            'required_permission' => $this->requiredPermission,
            'show_on_pc' => $this->showOnPc,
            'show_on_mobile' => $this->showOnMobile,
            'show_in_utility' => $this->showInUtility,
            'show_in_footer' => $this->showInFooter,
            'utility_order' => $this->utilityOrder,
            'footer_order' => $this->footerOrder,
            'provider_type' => $this->providerType->value,
            'provider_name' => $this->providerName,
            'layout_type' => $this->layoutType,
            'sidebar_left_width' => $this->sidebarLeftWidth,
            'sidebar_left_mobile' => $this->sidebarLeftMobile === null ? null : ($this->sidebarLeftMobile ? 1 : 0),
            'sidebar_right_width' => $this->sidebarRightWidth,
            'sidebar_right_mobile' => $this->sidebarRightMobile === null ? null : ($this->sidebarRightMobile ? 1 : 0),
            'is_active' => $this->isActive,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    // ========================================
    // Getter 메서드
    // ========================================

    public function getItemId(): int
    {
        return $this->itemId;
    }

    public function getDomainId(): int
    {
        return $this->domainId;
    }

    public function getMenuCode(): string
    {
        return $this->menuCode;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function getIcon(): ?string
    {
        return $this->icon;
    }

    public function getTarget(): string
    {
        return $this->target;
    }

    public function getVisibility(): string
    {
        return $this->visibility;
    }

    public function getPairCode(): ?string
    {
        return $this->pairCode;
    }

    public function hasPairCode(): bool
    {
        return $this->pairCode !== null && $this->pairCode !== '';
    }

    public function getMinLevel(): int
    {
        return $this->minLevel;
    }

    public function getRequiredPermission(): ?string
    {
        return $this->requiredPermission;
    }

    public function getProviderType(): ProviderType
    {
        return $this->providerType;
    }

    public function getProviderName(): ?string
    {
        return $this->providerName;
    }

    // ========================================
    // 레이아웃 오버라이드
    // ========================================

    public function getLayoutType(): ?int
    {
        return $this->layoutType;
    }

    public function getSidebarLeftWidth(): ?int
    {
        return $this->sidebarLeftWidth;
    }

    public function getSidebarLeftMobile(): ?bool
    {
        return $this->sidebarLeftMobile;
    }

    public function getSidebarRightWidth(): ?int
    {
        return $this->sidebarRightWidth;
    }

    public function getSidebarRightMobile(): ?bool
    {
        return $this->sidebarRightMobile;
    }

    /**
     * FrontViewRenderer 의 pageConfig 에 병합할 레이아웃 오버라이드.
     *
     * layout_type 이 상속(NULL)이면 오버라이드 없음으로 보고 null 을 반환한다 —
     * 즉 이 메뉴는 도메인 기본 레이아웃을 그대로 따른다.
     *
     * @return array{layout_type:int, sidebar_left_width?:int, sidebar_left_mobile?:bool, sidebar_right_width?:int, sidebar_right_mobile?:bool}|null
     */
    public function getLayoutOverride(): ?array
    {
        if ($this->layoutType === null) {
            return null;
        }

        $override = ['layout_type' => $this->layoutType];
        if ($this->sidebarLeftWidth !== null) {
            $override['sidebar_left_width'] = $this->sidebarLeftWidth;
        }
        if ($this->sidebarLeftMobile !== null) {
            $override['sidebar_left_mobile'] = $this->sidebarLeftMobile;
        }
        if ($this->sidebarRightWidth !== null) {
            $override['sidebar_right_width'] = $this->sidebarRightWidth;
        }
        if ($this->sidebarRightMobile !== null) {
            $override['sidebar_right_mobile'] = $this->sidebarRightMobile;
        }

        return $override;
    }

    /**
     * '' 또는 null 을 null 로, 그 외는 int 로 정규화 (레이아웃 오버라이드 상속 판정용).
     */
    private static function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (int) $value;
    }

    /**
     * null 은 상속(null), 그 외는 bool.
     */
    private static function nullableBool(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (bool) $value;
    }

    // ========================================
    // 상태 판단 메서드
    // ========================================

    /**
     * 활성 상태 여부
     */
    public function isActive(): bool
    {
        return $this->isActive;
    }

    /**
     * 클릭 가능한 URL이 있는지 여부
     */
    public function hasUrl(): bool
    {
        return $this->url !== null && $this->url !== '' && $this->url !== '#';
    }

    /**
     * 새 창에서 열리는지 여부
     */
    public function opensInNewTab(): bool
    {
        return $this->target === self::TARGET_BLANK;
    }

    /**
     * 특정 레벨의 회원이 접근 가능한지 여부
     *
     * @param int $memberLevel 회원 레벨 (0: 비회원)
     */
    public function canAccessByLevel(int $memberLevel): bool
    {
        return $memberLevel >= $this->minLevel;
    }

    /**
     * 비회원(레벨 0)에게 표시 가능한지
     */
    public function isVisibleForGuest(): bool
    {
        return $this->minLevel === 0;
    }

    /**
     * 로그인 사용자에게 표시 가능한지 (레벨 1 이상)
     */
    public function isVisibleForMember(): bool
    {
        return true;  // 회원은 항상 레벨 1 이상이므로 minLevel 체크로 판단
    }

    /**
     * 특정 디바이스에서 표시 가능한지 여부
     *
     * @param string $device 'pc' | 'mobile'
     */
    public function isShowOnDevice(string $device): bool
    {
        return match ($device) {
            'pc' => $this->showOnPc,
            'mobile' => $this->showOnMobile,
            default => true,
        };
    }

    /**
     * PC에서 표시 가능한지
     */
    public function isShowOnPc(): bool
    {
        return $this->showOnPc;
    }

    /**
     * 모바일에서 표시 가능한지
     */
    public function isShowOnMobile(): bool
    {
        return $this->showOnMobile;
    }

    /**
     * 유틸리티 메뉴에 표시되는지
     */
    public function isInUtility(): bool
    {
        return $this->showInUtility;
    }

    /**
     * 푸터 메뉴에 표시되는지
     */
    public function isInFooter(): bool
    {
        return $this->showInFooter;
    }

    /**
     * Core에서 제공하는 메뉴인지
     */
    public function isFromCore(): bool
    {
        return $this->providerType === ProviderType::CORE;
    }

    /**
     * Plugin에서 제공하는 메뉴인지
     */
    public function isFromPlugin(): bool
    {
        return $this->providerType === ProviderType::PLUGIN;
    }

    /**
     * Package에서 제공하는 메뉴인지
     */
    public function isFromPackage(): bool
    {
        return $this->providerType === ProviderType::PACKAGE;
    }

    /**
     * 특정 Plugin/Package에서 제공하는 메뉴인지
     */
    public function isFromProvider(ProviderType $type, string $name): bool
    {
        return $this->providerType === $type && $this->providerName === $name;
    }

    // ========================================
    // 옵션 목록 (정적 메서드)
    // ========================================

    /**
     * target 옵션 목록
     */
    public static function getTargetOptions(): array
    {
        return [
            self::TARGET_SELF => '현재 창',
            self::TARGET_BLANK => '새 창',
        ];
    }

    /**
     * providerType 옵션 목록
     */
    public static function getProviderTypeOptions(): array
    {
        return ProviderType::options();
    }
}
