<?php
namespace Mublo\Core\Context;

use Mublo\Core\Rendering\ThemeSkinPolicy;

use Mublo\Core\Http\Request;
use Mublo\Entity\Domain\Domain;

/**
 * Class Context
 *
 * ============================================================
 * Context – 요청(Request) 해석 결과를 담는 상태 컨테이너
 * ============================================================
 *
 * Context 는 하나의 HTTP 요청에 대해
 * 해석된 "환경 상태"를 보관하는 객체이다.
 *
 * ------------------------------------------------------------
 * [책임]
 * ------------------------------------------------------------
 *
 * - 요청 단위 상태 보관
 * - Front / Admin / Api 여부 결과 보관
 * - 도메인 정보 보관
 * - 렌더링에 필요한 "스킨 선택 결과" 보관
 *
 * ------------------------------------------------------------
 * [중요 원칙]
 * ------------------------------------------------------------
 *
 * - Context 는 판단하지 않는다.
 * - Context 는 결과만 보관한다.
 * - 스킨 결정 로직은 ContextBuilder 의 책임이다.
 *
 * ------------------------------------------------------------
 * [금지 사항]
 * ------------------------------------------------------------
 *
 * - DB 접근
 * - 인증 처리
 * - 비즈니스 로직
 * ------------------------------------------------------------
 */
class Context
{
    /* =========================================================
     * Request
     * ========================================================= */

    /**
     * 원본 Request 객체
     */
    protected Request $request;

    /* =========================================================
     * Area flags (해석 결과)
     * ========================================================= */

    /**
     * 관리자 요청 여부
     */
    protected bool $isAdmin = false;

    /**
     * API 요청 여부
     */
    protected bool $isApi = false;

    /* =========================================================
     * Domain & Site
     * ========================================================= */

    /**
     * 현재 도메인
     */
    protected ?string $domain = null;

    /**
     * 현재 도메인 정보 (domain_configs 기반)
     */
    protected ?Domain $domainInfo = null;

    /* =========================================================
     * Skin selections (의미별 분리)
     * ========================================================= */

    /**
     * 관리자 영역 스킨
     *
     * View/Admin/{skin}/...
     */
    protected string $adminSkin = 'basic';

    /**
     * Front 프레임 스킨
     *
     * views/Front/frame/{frameSkin}/Head.php, Header.php, Layout, Footer, Foot
     */
    protected string $frameSkin = 'basic';

    /**
     * 패키지 프레임 스킨 폴더 (전체 경로, 오버라이드용).
     * null = 코어 프레임. 설정 시 그 폴더의 파트 우선, 없는 파트는 코어 frameSkin 으로 폴백.
     */
    protected ?string $frameBasePath = null;

    /**
     * Front 콘텐츠 영역 스킨
     *
     * views/Front/{Group}/{skin}/...
     * 예: Board, Auth, Member, Index, Page ...
     */
    protected array $frontSkins = [];

    /**
     * 블록 스킨
     *
     * View/Block/{type}/{skin}/...
     * 예: latest, image, file ...
     */
    protected array $blockSkins = [];

    /**
     * 현재 메뉴 코드 (URL 매칭 결과)
     *
     * 블록 시스템의 메뉴 스코프 + 프론트 메뉴 active 표시에 사용
     */
    protected ?string $currentMenuCode = null;

    /**
     * 현재 메뉴의 레이아웃 오버라이드 (NULL = 오버라이드 없음 → 도메인 기본).
     *
     * URL 맵 매칭 시점에 함께 확정돼, FrontViewRenderer 가 렌더 때 별도 DB 조회 없이 쓴다.
     * 형태는 MenuItem::getLayoutOverride() 반환값과 동일.
     */
    protected ?array $currentMenuLayout = null;

    /**
     * 사이트 이미지 Full URL 목록 (요청 범위, 뷰 렌더링용)
     *
     * DB/캐시에는 상대경로 원본을 유지하고,
     * ContextBuilder에서 scheme+host를 붙여 Full URL로 변환해 보관한다.
     * SiteContextReadyEvent 구독자(파트너 등)가 개별 키를 override할 수 있다.
     *
     * keys: logo_pc, logo_mobile, favicon, app_icon, og_image
     */
    protected array $siteImageUrls = [];

    /**
     * 사이트 로고 텍스트 (요청 범위)
     *
     * 기본값: 사이트 설정의 site_title.
     * SiteContextReadyEvent 구독자(파트너 등)가 파트너명으로 override할 수 있다.
     */
    protected string $siteLogoText = '';

    /**
     * 사이트 표시 오버라이드 (요청 범위)
     *
     * Package가 SiteContextReadyEvent에서 사이트 기본값을 덮어씌울 때 사용.
     * 예: Mshop이 /mshop/* 요청 시 고객센터 전화번호를 패키지 설정값으로 교체.
     *
     * 키 컨벤션: '{항목명}' (예: 'cs_tel', 'cs_time')
     * attributesLocked의 영향을 받지 않는다 (siteImageUrls, siteLogoText와 동일).
     */
    protected array $siteOverrides = [];

    /**
     * Context 는 반드시 Request 를 기반으로 생성된다.
     */
    public function __construct(Request $request)
    {
        $this->request = $request;

        // 해석 로직은 ContextBuilder 에서 수행
        // Context 는 순수 상태 컨테이너 역할만 담당한다
    }

    /* =========================================================
     * Request
     * ========================================================= */

    public function getRequest(): Request
    {
        return $this->request;
    }

    /* =========================================================
     * Area flags
     * ========================================================= */

    public function isAdmin(): bool
    {
        return $this->isAdmin;
    }

    public function isApi(): bool
    {
        return $this->isApi;
    }

    public function isFront(): bool
    {
        return !$this->isAdmin && !$this->isApi;
    }

    /* =========================================================
     * Domain & Site
     * ========================================================= */

    public function getDomain(): ?string
    {
        return $this->domain;
    }

    public function getDomainInfo(): ?Domain
    {
        return $this->domainInfo;
    }

    /**
     * 도메인 정보가 유효한지 확인
     */
    public function hasDomainInfo(): bool
    {
        return $this->domainInfo !== null;
    }

    /**
     * 도메인 접근 가능 여부 확인
     */
    public function isDomainAccessible(): bool
    {
        return $this->domainInfo !== null && $this->domainInfo->isAccessible();
    }

    /**
     * 도메인 ID 반환 (편의 메서드)
     */
    public function getDomainId(): ?int
    {
        return $this->domainInfo?->getDomainId();
    }

    /**
     * 도메인 그룹 반환 (편의 메서드)
     */
    public function getDomainGroup(): ?string
    {
        return $this->domainInfo?->getDomainGroup();
    }

    /* =========================================================
     * Admin skins
     * ========================================================= */

    public function getAdminSkin(): string
    {
        return $this->adminSkin;
    }

    /* =========================================================
     * Frame skin
     * ========================================================= */

    public function getFrameSkin(): string
    {
        return $this->frameSkin;
    }

    public function getFrameBasePath(): ?string
    {
        return $this->frameBasePath;
    }

    /* =========================================================
     * Front content skins
     * ========================================================= */

    public function getFrontSkin(string $group): ?string
    {
        return $this->frontSkins[$group] ?? null;
    }

    /* =========================================================
     * Block skins
     * ========================================================= */

    public function getBlockSkin(string $type): ?string
    {
        return $this->blockSkins[$type] ?? null;
    }

    /**
     * @deprecated 템플릿 스킨은 block skin으로 통합됨. getBlockSkin() 사용 권장.
     */
    public function getTemplateSkin(string $type): ?string
    {
        return $this->getBlockSkin($type);
    }

    /* =========================================================
     * Setter (ContextBuilder 전용)
     * ========================================================= */

    public function setAdmin(bool $isAdmin): void
    {
        $this->isAdmin = $isAdmin;
    }

    public function setApi(bool $isApi): void
    {
        $this->isApi = $isApi;
    }

    public function setDomain(?string $domain): void
    {
        $this->domain = $domain;
    }

    public function setDomainInfo(?Domain $domainInfo): void
    {
        $this->domainInfo = $domainInfo;
    }

    public function setAdminSkin(string $skin): void
    {
        $this->adminSkin = ThemeSkinPolicy::normalizeName($skin);
    }

    public function setFrameSkin(string $skin): void
    {
        $this->frameSkin = ThemeSkinPolicy::normalizeName($skin);
    }

    /**
     * 패키지 프레임 오버라이드: 프레임 스킨 폴더(전체 경로) 지정.
     * 예) MUBLO_PACKAGE_PATH . '/Shop/views/Front/frame/wide'  (null = 코어)
     */
    public function setFrameBasePath(?string $path): void
    {
        $this->frameBasePath = $path;
    }

    public function setFrontSkin(string $group, string $skin): void
    {
        $this->frontSkins[$group] = ThemeSkinPolicy::normalizeName($skin);
    }

    public function setBlockSkin(string $type, string $skin): void
    {
        $this->blockSkins[$type] = $skin;
    }

    /**
     * @deprecated 템플릿 스킨은 block skin으로 통합됨. setBlockSkin() 사용 권장.
     */
    public function setTemplateSkin(string $type, string $skin): void
    {
        $this->setBlockSkin($type, $skin);
    }

    public function setCurrentMenuCode(?string $menuCode): void
    {
        $this->currentMenuCode = $menuCode;
    }

    public function setCurrentMenuLayout(?array $layout): void
    {
        $this->currentMenuLayout = $layout;
    }

    public function setSiteImageUrls(array $urls): void
    {
        $this->siteImageUrls = $urls;
    }

    /**
     * 특정 키의 사이트 이미지 URL만 교체 (SiteContextReadyEvent 구독자용)
     *
     * @param string $key  logo_pc | logo_mobile | favicon | og_image
     */
    public function setSiteImageUrl(string $key, string $url): void
    {
        $this->siteImageUrls[$key] = $url;
    }

    public function setSiteLogoText(string $text): void
    {
        $this->siteLogoText = $text;
    }

    /* =========================================================
     * Current menu
     * ========================================================= */

    public function getCurrentMenuCode(): ?string
    {
        return $this->currentMenuCode;
    }

    /**
     * 현재 메뉴의 레이아웃 오버라이드 (NULL = 도메인 기본 상속).
     *
     * @return array{layout_type:int, sidebar_left_width?:int, sidebar_left_mobile?:bool, sidebar_right_width?:int, sidebar_right_mobile?:bool}|null
     */
    public function getCurrentMenuLayout(): ?array
    {
        return $this->currentMenuLayout;
    }

    /* =========================================================
     * Site image URLs (요청 범위 Full URL)
     * ========================================================= */

    /**
     * 사이트 이미지 Full URL 전체 반환
     */
    public function getSiteImageUrls(): array
    {
        return $this->siteImageUrls;
    }

    /**
     * 특정 키의 사이트 이미지 Full URL 반환
     *
     * @param string $key  logo_pc | logo_mobile | favicon | og_image
     */
    public function getSiteImageUrl(string $key): string
    {
        return $this->siteImageUrls[$key] ?? '';
    }

    /**
     * 사이트 로고 텍스트 반환
     *
     * 파트너 접속 시 파트너명, 일반 접속 시 site_title.
     */
    public function getSiteLogoText(): string
    {
        return $this->siteLogoText;
    }

    /* =========================================================
     * Site Overrides (패키지별 표시 오버라이드)
     *
     * Package가 SiteContextReadyEvent에서 사이트 기본 표시값을
     * 패키지 설정값으로 덮어씌울 때 사용한다.
     *
     * 예: Mshop → cs_tel, cs_time / Rental → cs_tel, cs_time
     * ========================================================= */

    public function setSiteOverride(string $key, mixed $value): void
    {
        $this->siteOverrides[$key] = $value;
    }

    public function getSiteOverride(string $key, mixed $default = null): mixed
    {
        return $this->siteOverrides[$key] ?? $default;
    }

    public function getSiteOverrides(): array
    {
        return $this->siteOverrides;
    }

    /* =========================================================
     * Dynamic Attributes (패키지 확장용)
     *
     * 패키지가 boot() 단계에서 비즈니스 신호를 설정하고,
     * Controller/View에서 읽어 조건부 UI 개입에 활용한다.
     *
     * 키 컨벤션: '{패키지명}.{속성명}' (예: 'shop.is_checkout')
     * ========================================================= */

    /**
     * 패키지 확장 속성
     */
    protected array $attributes = [];

    /**
     * 속성 잠금 상태
     */
    protected bool $attributesLocked = false;

    /**
     * 확장 속성 설정
     *
     * Package/Plugin의 boot() 단계에서만 호출한다.
     * lockAttributes() 이후 호출 시 LogicException 발생.
     */
    public function setAttribute(string $key, mixed $value): void
    {
        if ($this->attributesLocked) {
            throw new \LogicException(
                "Context attributes are locked. setAttribute() is only allowed during boot phase. key: {$key}"
            );
        }

        $this->attributes[$key] = $value;
    }

    /**
     * 확장 속성 반환 (읽기 전용, 잠금과 무관)
     */
    public function getAttribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /**
     * 속성 존재 여부 확인
     */
    public function hasAttribute(string $key): bool
    {
        return array_key_exists($key, $this->attributes);
    }

    /**
     * 전체 확장 속성 반환 (디버깅용)
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * 속성 잠금 — 이후 setAttribute() 호출 차단
     *
     * Application::run()에서 loadEnabledExtensions() 직후 호출.
     * boot() 단계 이후에 속성이 변경되는 것을 방지한다.
     */
    public function lockAttributes(): void
    {
        $this->attributesLocked = true;
    }
}
