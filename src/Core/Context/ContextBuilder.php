<?php
declare(strict_types=1);
namespace Mublo\Core\Context;

use Mublo\Core\Http\Request;
use Mublo\Core\Rendering\AssetManager;
use Mublo\Core\Rendering\ThemeSkinPolicy;
use Mublo\Entity\Menu\MenuItem;
use Mublo\Infrastructure\Cache\CacheFactory;
use Mublo\Infrastructure\Cache\CacheInterface;
use Mublo\Infrastructure\Cache\DomainCache;
use Mublo\Infrastructure\Database\DatabaseManager;
use Mublo\Repository\Domain\DomainRepository;
use Mublo\Repository\Menu\MenuItemRepository;
use Mublo\Service\Domain\DomainResolver;

/**
 * Class ContextBuilder
 *
 * ============================================================
 * ContextBuilder – Request → Context 해석 전담 빌더
 * ============================================================
 *
 * ContextBuilder 는
 * Request 객체를 분석하여
 * Context 에 필요한 "해석 결과"를 채워 넣는 역할을 담당한다.
 *
 * ------------------------------------------------------------
 * [책임]
 * ------------------------------------------------------------
 *
 * - Front / Admin / Api 요청 판별
 * - 도메인 정보 설정
 * - 출력 스킨 결정
 * - 현재 메뉴 코드 해석 (URL 매칭)
 *
 * ------------------------------------------------------------
 * [중요 원칙]
 * ------------------------------------------------------------
 *
 * - 판단은 여기서만 한다.
 * - Context 는 결과만 보관한다.
 * - Renderer / Controller 는 Context 를 신뢰한다.
 *
 * ------------------------------------------------------------
 * [금지 사항]
 * ------------------------------------------------------------
 *
 * - 인증 처리
 * - 비즈니스 로직
 *
 * 이 클래스는
 * "요청 환경 해석기"이다.
 * ------------------------------------------------------------
 */
class ContextBuilder
{
    protected ?DomainResolver $domainResolver = null;
    protected ?MenuItemRepository $menuItemRepository = null;
    protected ?CacheInterface $cache = null;

    private const MENU_URLMAP_TTL = 3600;

    public function __construct(
        ?DomainResolver $domainResolver = null,
        ?MenuItemRepository $menuItemRepository = null,
        ?CacheInterface $cache = null
    ) {
        $this->domainResolver = $domainResolver;
        $this->menuItemRepository = $menuItemRepository;
        $this->cache = $cache;
    }

    /**
     * Request 를 기반으로 Context 생성
     */
    public function build(Request $request): Context
    {
        $context = new Context($request);

        /* =====================================================
         * 1. 요청 영역 판별
         * ===================================================== */

        if ($this->isAdminRequest($request)) {
            $context->setAdmin(true);
        }

        if ($this->isApiRequest($request)) {
            $context->setApi(true);
        }

        /* =====================================================
         * 2. 도메인 정보 설정
         * ===================================================== */

        $domainName = $request->getHost();
        $context->setDomain($domainName);

        // 도메인 정보 로드 (캐시 → DB)
        $domainInfo = $this->resolveDomain($domainName);
        $context->setDomainInfo($domainInfo);

        // 사이트 이미지 Full URL 변환 (DB/캐시는 상대경로 원본 유지)
        // scheme+host는 요청마다 달라질 수 있으므로 Context(요청 범위)에서 처리
        $seoConfig  = $domainInfo?->getSeoConfig() ?? [];
        $siteConfig = $domainInfo?->getSiteConfig() ?? [];
        $baseUrl = $request->getSchemeAndHost();
        $context->setSiteImageUrls([
            'logo_pc'     => $this->buildImageUrl($baseUrl, $seoConfig['logo_pc'] ?? ''),
            'logo_mobile' => $this->buildImageUrl($baseUrl, $seoConfig['logo_mobile'] ?? ''),
            'favicon'     => $this->buildImageUrl($baseUrl, $seoConfig['favicon'] ?? ''),
            'app_icon'    => $this->buildImageUrl($baseUrl, $seoConfig['app_icon'] ?? ''),
            'og_image'    => $this->buildImageUrl($baseUrl, $seoConfig['og_image'] ?? ''),
        ]);

        // 로고 텍스트 기본값: site_title (SiteContextReadyEvent 구독자가 파트너명으로 override 가능)
        $context->setSiteLogoText($siteConfig['site_title'] ?? '');

        // Domain의 theme_config에서 스킨 설정 가져오기
        $themeConfig = $domainInfo?->getThemeConfig() ?? [];

        /* =====================================================
         * 3. 스킨 결정 (의미별 명시)
         * ===================================================== */
        if ($context->isApi()) {
            // -------------------------------
            // API 영역
            // -------------------------------
            // API 는 렌더링/스킨 개념 없음
            return $context;
        }

        if ($context->isAdmin()) {
            // -------------------------------
            // Admin 영역
            // -------------------------------
            $context->setAdminSkin(ThemeSkinPolicy::resolveExisting('admin', $themeConfig['admin'] ?? null));

            // Admin 은 Front / Block / Frame 스킨을 사용하지 않음
            return $context;
        }

        // -------------------------------
        // Frame 스킨 (Head, Header, Layout, Footer, Foot 통합)
        // -------------------------------
        $context->setFrameSkin(ThemeSkinPolicy::resolveExisting('frame', $themeConfig['frame'] ?? null));

        // -------------------------------
        // Front 콘텐츠 영역 스킨
        // -------------------------------

        // 코어 기능 스킨
        $context->setFrontSkin('Member', ThemeSkinPolicy::resolveExisting('member', $themeConfig['member'] ?? null));
        $context->setFrontSkin('Auth', ThemeSkinPolicy::resolveExisting('auth', $themeConfig['auth'] ?? null));
        $context->setFrontSkin('Mypage', ThemeSkinPolicy::resolveExisting('mypage', $themeConfig['mypage'] ?? null));
        $context->setFrontSkin('Index', ThemeSkinPolicy::resolveExisting('index', $themeConfig['index'] ?? null));
        $context->setFrontSkin('Policy', ThemeSkinPolicy::resolveExisting('policy', $themeConfig['policy'] ?? null));
        $context->setFrontSkin('Search', ThemeSkinPolicy::resolveExisting('search', $themeConfig['search'] ?? null));

        // -------------------------------
        // Block 스킨
        // -------------------------------
        $context->setBlockSkin('latest', 'basic');
        $context->setBlockSkin('image',  'basic');
        $context->setBlockSkin('file',   'basic');

        /* =====================================================
         * 4. 현재 메뉴 코드 해석 (URL 매칭)
         * ===================================================== */
        $this->resolveCurrentMenu($request, $context);

        return $context;
    }

    /* =========================================================
     * 요청 판별 로직 (내부 전용)
     * ========================================================= */

    /**
     * 관리자 요청 여부 판별
     */
    protected function isAdminRequest(Request $request): bool
    {
        $path = $request->getPath();
        return $path === '/admin' || str_starts_with($path, '/admin/');
    }

    /**
     * API 요청 여부 판별
     */
    protected function isApiRequest(Request $request): bool
    {
        $path = $request->getPath();
        return $path === '/api' || str_starts_with($path, '/api/');
    }

    /* =========================================================
     * Domain 해결
     * ========================================================= */

    /**
     * 도메인명으로 Domain 해결 (캐시 → DB)
     *
     * @param string|null $domainName 도메인명
     * @return \Mublo\Entity\Domain\Domain|null
     */
    protected function resolveDomain(?string $domainName): ?\Mublo\Entity\Domain\Domain
    {
        if (empty($domainName)) {
            return null;
        }

        try {
            $resolver = $this->getDomainResolver();
            return $resolver->resolve($domainName);
        } catch (\Mublo\Infrastructure\Database\DatabaseException $e) {
            // DB 연결 실패 - 설치 전이거나 설정 오류
            // 조용히 null 반환 (설치 페이지로 이동 가능)
            return null;
        }
        // 다른 예외는 전파하여 디버깅 가능하게 함
    }

    /**
     * DomainResolver 인스턴스 반환 (지연 초기화)
     */
    protected function getDomainResolver(): DomainResolver
    {
        if ($this->domainResolver === null) {
            $this->domainResolver = new DomainResolver(
                new DomainCache(),
                new DomainRepository()
            );
        }

        return $this->domainResolver;
    }

    /* =========================================================
     * 메뉴 매칭 (URL → menuCode)
     * ========================================================= */

    /**
     * 현재 요청 URL과 메뉴 URL을 비교하여 현재 메뉴 코드 결정
     *
     * 매칭 우선순위:
     * 1. path + query 모두 일치 (예: /devices?telecom=SKT) → 최우선
     * 2. path만 일치하고 메뉴에 query 없음 (예: /devices)
     * 3. path prefix 일치 → 최장 경로 우선 (longest match wins)
     */
    protected function resolveCurrentMenu(Request $request, Context $context): void
    {
        $domainId = $context->getDomainId();
        if ($domainId === null) {
            return;
        }

        try {
            $urlMap = $this->getMenuUrlMap($domainId);
        } catch (\Throwable $e) {
            // DB 오류 시 조용히 무시 (menuCode=null → 글로벌 블록만 표시)
            return;
        }

        if (empty($urlMap)) {
            return;
        }

        $requestPath = rtrim($request->getPath(), '/');
        if ($requestPath === '') {
            $requestPath = '/';
        }

        // 요청 쿼리 파라미터
        $requestQuery = $request->getQuery();

        $pathMatch   = null;  // 2순위: path만 일치 (쿼리 없는 메뉴) — 매칭된 urlMap 엔트리
        $prefixBest  = null;  // 3순위: prefix 일치 (최장) — 매칭된 urlMap 엔트리
        $prefixLen   = 0;

        foreach ($urlMap as $entry) {
            [$menuPath, $menuQuery] = $this->extractPathAndQuery($entry['url']);
            if ($menuPath === null) {
                continue;
            }

            $menuPath = rtrim($menuPath, '/');
            if ($menuPath === '') {
                $menuPath = '/';
            }

            if ($requestPath === $menuPath) {
                if (!empty($menuQuery) && $this->queryMatches($menuQuery, $requestQuery)) {
                    // 1순위: path + query 모두 일치 → 즉시 확정
                    $this->applyMatchedMenu($context, $entry);
                    return;
                }
                if (empty($menuQuery) && $pathMatch === null) {
                    // 2순위 후보: query 없는 순수 path 일치
                    $pathMatch = $entry;
                }
                continue;
            }

            // 3순위: prefix 일치 (메뉴 path + '/' 로 시작해야 함)
            if ($menuPath !== '/' && str_starts_with($requestPath, $menuPath . '/')) {
                $pathLength = strlen($menuPath);
                if ($pathLength > $prefixLen) {
                    $prefixBest = $entry;
                    $prefixLen  = $pathLength;
                }
            }
        }

        $matched = $pathMatch ?? $prefixBest;
        if ($matched !== null) {
            $this->applyMatchedMenu($context, $matched);
        }
    }

    /**
     * 매칭된 urlMap 엔트리로 현재 메뉴 코드 + 레이아웃 오버라이드를 함께 확정한다.
     *
     * 레이아웃 오버라이드 계산은 MenuItem::getLayoutOverride() 를 재사용해
     * "layout_type 상속(NULL)이면 오버라이드 없음, 전체면 사이드바 키 생략" 규약을 단일 소스로 유지한다.
     *
     * @param array<string,mixed> $entry
     */
    private function applyMatchedMenu(Context $context, array $entry): void
    {
        $context->setCurrentMenuCode($entry['menu_code']);
        $context->setCurrentMenuLayout(MenuItem::fromArray($entry)->getLayoutOverride());
    }

    /**
     * URL에서 path와 query 배열을 함께 추출
     *
     * @return array{0: string|null, 1: array} [path, queryParams]
     */
    private function extractPathAndQuery(string $url): array
    {
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            $path = parse_url($url, PHP_URL_PATH) ?: null;
            $queryStr = parse_url($url, PHP_URL_QUERY) ?? '';
            parse_str($queryStr, $query);
            return [$path, $query];
        }

        $pos = strpos($url, '?');
        if ($pos === false) {
            return [$url, []];
        }

        $path = substr($url, 0, $pos);
        parse_str(substr($url, $pos + 1), $query);
        return [$path, $query];
    }

    /**
     * 메뉴의 쿼리 파라미터가 요청 쿼리에 모두 포함되어 있는지 확인
     *
     * 메뉴에 정의된 key=value가 요청에 모두 있으면 매칭.
     * 요청에 추가 파라미터가 있어도 허용 (subset 매칭).
     */
    private function queryMatches(array $menuQuery, array $requestQuery): bool
    {
        foreach ($menuQuery as $key => $value) {
            if (!isset($requestQuery[$key]) || $requestQuery[$key] !== $value) {
                return false;
            }
        }
        return true;
    }

    /**
     * 도메인별 메뉴 URL 맵 조회 (캐시)
     *
     * @return array [['menu_code' => string, 'url' => string], ...]
     */
    private function getMenuUrlMap(int $domainId): array
    {
        $cache = $this->getCache($domainId);
        $cacheKey = "menu:urlmap:{$domainId}";

        $cached = $cache->get($cacheKey);
        if ($cached !== null && is_array($cached)) {
            return $cached;
        }

        $repository = $this->getMenuItemRepository();
        $urlMap = $repository->findUrlMap($domainId);

        $cache->set($cacheKey, $urlMap, self::MENU_URLMAP_TTL);

        return $urlMap;
    }

    /**
     * MenuItemRepository 인스턴스 반환 (지연 초기화)
     */
    protected function getMenuItemRepository(): MenuItemRepository
    {
        if ($this->menuItemRepository === null) {
            $db = DatabaseManager::getInstance()->connect();
            $this->menuItemRepository = new MenuItemRepository($db);
        }

        return $this->menuItemRepository;
    }

    /**
     * CacheInterface 인스턴스 반환 (지연 초기화)
     */
    protected function getCache(int $domainId): CacheInterface
    {
        if ($this->cache === null) {
            $this->cache = CacheFactory::getInstance($domainId);
        }

        return $this->cache;
    }

    /* =========================================================
     * Helpers
     * ========================================================= */

    /**
     * 이미지 경로를 Full URL로 변환
     *
     * - 빈 값 → 빈 문자열 반환
     * - 이미 http:// / https:// 로 시작하면 그대로 반환
     * - 상대 경로(/uploads/...) → baseUrl + path (+ 캐시버스팅 ?<filemtime>)
     *
     * 파비콘/로고/OG이미지 등은 파일명이 그대로(logo.png) 교체되는 경우가 많아
     * URL이 안 바뀌면 브라우저가 옛 이미지를 캐시한다. AssetManager::versionedPath
     * (asset() 헬퍼와 동일 로직)로 filemtime 쿼리를 붙여 교체 즉시 반영되게 한다.
     * 디스크에서 파일을 못 찾으면(외부 경로·CLI 등) 버전 없이 원본을 반환한다.
     */
    protected function buildImageUrl(string $baseUrl, string $path): string
    {
        if ($path === '') {
            return '';
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return $baseUrl . AssetManager::versionedPath('/' . ltrim($path, '/'));
    }
}
