<?php
namespace Mublo\Entity\Block;

use DateTimeImmutable;
use Mublo\Enum\Block\LayoutType;

/**
 * Class BlockPage
 *
 * 블록 페이지 엔티티
 *
 * 책임:
 * - block_pages 테이블의 데이터를 객체로 표현
 * - 블록으로 구성된 개별 페이지 정의
 *
 * 금지:
 * - DB 직접 접근
 * - 비즈니스 로직 (Service 담당)
 */
class BlockPage
{
    // ========================================
    // 기본 필드
    // ========================================
    protected int $pageId;
    protected int $domainId;
    protected string $pageCode;
    protected string $pageTitle;
    protected ?string $pageDescription;

    // ========================================
    // SEO
    // ========================================
    protected ?string $seoTitle;
    protected ?string $seoDescription;
    protected ?string $seoKeywords;

    // ========================================
    // 레이아웃
    // ========================================
    protected LayoutType $layoutType;
    protected int $useFullpage;
    protected int $customWidth;
    protected int $sidebarLeftWidth;
    protected bool $sidebarLeftMobile;
    protected int $sidebarRightWidth;
    protected bool $sidebarRightMobile;
    protected bool $useHeader;
    protected bool $useFooter;

    // ========================================
    // 권한
    // ========================================
    protected int $allowLevel;

    // ========================================
    // 확장 설정
    // ========================================
    protected array $pageConfig;

    // ========================================
    // 관리
    // ========================================
    protected bool $isActive;
    protected bool $isDeleted = false;
    protected DateTimeImmutable $createdAt;
    protected DateTimeImmutable $updatedAt;

    /**
     * DB 로우 데이터로부터 BlockPage 객체 생성
     */
    public static function fromArray(array $data): self
    {
        $page = new self();

        // 기본 필드
        $page->pageId = (int) ($data['page_id'] ?? 0);
        $page->domainId = (int) ($data['domain_id'] ?? 0);
        $page->pageCode = $data['page_code'] ?? '';
        $page->pageTitle = $data['page_title'] ?? '';
        $page->pageDescription = $data['page_description'] ?? null;

        // SEO
        $page->seoTitle = $data['seo_title'] ?? null;
        $page->seoDescription = $data['seo_description'] ?? null;
        $page->seoKeywords = $data['seo_keywords'] ?? null;

        // 레이아웃
        $page->layoutType = LayoutType::tryFrom((int) ($data['layout_type'] ?? 1)) ?? LayoutType::FULL;
        $page->useFullpage = (int) ($data['use_fullpage'] ?? 0);
        $page->customWidth = (int) ($data['custom_width'] ?? 0);
        $page->sidebarLeftWidth = (int) ($data['sidebar_left_width'] ?? 0);
        $page->sidebarLeftMobile = (bool) ($data['sidebar_left_mobile'] ?? false);
        $page->sidebarRightWidth = (int) ($data['sidebar_right_width'] ?? 0);
        $page->sidebarRightMobile = (bool) ($data['sidebar_right_mobile'] ?? false);
        $page->useHeader = (bool) ($data['use_header'] ?? true);
        $page->useFooter = (bool) ($data['use_footer'] ?? true);

        // 권한
        $page->allowLevel = (int) ($data['allow_level'] ?? 0);

        // 확장 설정
        $configRaw = $data['page_config'] ?? null;
        $page->pageConfig = is_string($configRaw) ? (json_decode($configRaw, true) ?? []) : (is_array($configRaw) ? $configRaw : []);

        // 관리
        $page->isActive = (bool) ($data['is_active'] ?? true);
        $page->isDeleted = (bool) ($data['is_deleted'] ?? false);

        // 날짜
        $page->createdAt = self::parseDateTime($data['created_at'] ?? null);
        $page->updatedAt = self::parseDateTime($data['updated_at'] ?? null);

        return $page;
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
            'page_id' => $this->pageId,
            'domain_id' => $this->domainId,
            'page_code' => $this->pageCode,
            'page_title' => $this->pageTitle,
            'page_description' => $this->pageDescription,
            'seo_title' => $this->seoTitle,
            'seo_description' => $this->seoDescription,
            'seo_keywords' => $this->seoKeywords,
            'layout_type' => $this->layoutType->value,
            'use_fullpage' => $this->useFullpage,
            'custom_width' => $this->customWidth,
            'sidebar_left_width' => $this->sidebarLeftWidth,
            'sidebar_left_mobile' => $this->sidebarLeftMobile,
            'sidebar_right_width' => $this->sidebarRightWidth,
            'sidebar_right_mobile' => $this->sidebarRightMobile,
            'use_header' => $this->useHeader,
            'use_footer' => $this->useFooter,
            'allow_level' => $this->allowLevel,
            'page_config' => $this->pageConfig,
            'is_active' => $this->isActive,
            'is_deleted' => $this->isDeleted,
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
            'updated_at' => $this->updatedAt->format('Y-m-d H:i:s'),
        ];
    }

    // ========================================
    // Getters - 기본 필드
    // ========================================

    public function getPageId(): int
    {
        return $this->pageId;
    }

    public function getDomainId(): int
    {
        return $this->domainId;
    }

    public function getPageCode(): string
    {
        return $this->pageCode;
    }

    public function getPageTitle(): string
    {
        return $this->pageTitle;
    }

    public function getPageDescription(): ?string
    {
        return $this->pageDescription;
    }

    // ========================================
    // Getters - SEO
    // ========================================

    public function getSeoTitle(): ?string
    {
        return $this->seoTitle;
    }

    public function getSeoDescription(): ?string
    {
        return $this->seoDescription;
    }

    public function getSeoKeywords(): ?string
    {
        return $this->seoKeywords;
    }

    /**
     * 실제 사용할 타이틀 (SEO 타이틀 우선)
     */
    public function getDisplayTitle(): string
    {
        return $this->seoTitle ?: $this->pageTitle;
    }

    // ========================================
    // Getters - 레이아웃
    // ========================================

    public function getLayoutType(): LayoutType
    {
        return $this->layoutType;
    }

    public function useFullpage(): int
    {
        return $this->useFullpage;
    }

    public function isUseFullpage(): bool
    {
        return $this->useFullpage > 0;
    }

    public function getCustomWidth(): int
    {
        return $this->customWidth;
    }

    public function getSidebarLeftWidth(): int
    {
        return $this->sidebarLeftWidth;
    }

    public function getSidebarLeftMobile(): bool
    {
        return $this->sidebarLeftMobile;
    }

    public function getSidebarRightWidth(): int
    {
        return $this->sidebarRightWidth;
    }

    public function getSidebarRightMobile(): bool
    {
        return $this->sidebarRightMobile;
    }

    public function useHeader(): bool
    {
        return $this->useHeader;
    }

    public function useFooter(): bool
    {
        return $this->useFooter;
    }

    // ========================================
    // Getters - 권한
    // ========================================

    public function getAllowLevel(): int
    {
        return $this->allowLevel;
    }

    /**
     * 접근 권한 확인
     */
    public function canAccess(int $memberLevel): bool
    {
        return $memberLevel >= $this->allowLevel;
    }

    // ========================================
    // Getters - 확장 설정
    // ========================================

    public function getPageConfig(): array
    {
        return $this->pageConfig;
    }

    public function getPageConfigValue(string $key, mixed $default = null): mixed
    {
        return $this->pageConfig[$key] ?? $default;
    }

    // ========================================
    // Getters - 관리
    // ========================================

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function isDeleted(): bool
    {
        return $this->isDeleted;
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
    // URL 생성
    // ========================================

    /**
     * 페이지 URL 반환
     */
    public function getUrl(): string
    {
        return '/p/' . $this->pageCode;
    }
}
