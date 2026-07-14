<?php
namespace Mublo\Helper;

use Mublo\Core\Container\DependencyContainer;
use Mublo\Service\Block\BlockRenderService;

/**
 * BlockHelper
 *
 * 블록 렌더링 헬퍼 함수
 *
 * 레이아웃 파일에서 쉽게 블록을 출력할 수 있도록 지원
 *
 * 사용 예:
 * ```php
 * // 레이아웃 파일에서
 * <?= BlockHelper::position($domainId, 'index') ?>
 * <?= BlockHelper::position($domainId, 'left', $currentMenu) ?>
 *
 * // 블록 페이지에서
 * <?= BlockHelper::page($pageId) ?>
 * ```
 */
class BlockHelper
{
    private static ?BlockRenderService $renderService = null;

    /**
     * 위치별 블록 출력
     *
     * @param int $domainId 도메인 ID
     * @param string $position 위치 (index, left, right, subhead, subfoot, contenthead, contentfoot)
     * @param string|null $menuCode 메뉴 코드 (특정 메뉴용)
     * @param bool $useCache 캐시 사용 여부
     * @return string 렌더링된 HTML
     */
    public static function position(
        int $domainId,
        string $position,
        ?string $menuCode = null,
        bool $useCache = true,
        ?bool $isMainScreen = null
    ): string {
        // null 이면 요청 경로로 메인화면(루트 '/') 자동 판별 — FrontViewRenderer 와 동일 기준.
        // 이렇게 해야 스킨이 이 헬퍼로 직접 렌더해도 메인 전용(position_menu='__index__') 행이 나온다.
        if ($isMainScreen === null) {
            $isMainScreen = self::isMainScreenRequest();
        }
        return self::getRenderService()->renderPosition($domainId, $position, $menuCode, $useCache, $isMainScreen);
    }

    /**
     * 현재 HTTP 요청이 메인화면(홈, 루트 '/')인지 — 요청 URI 경로로 판별.
     *
     * REQUEST_URI 가 없는 CLI/cron 등 비-HTTP 컨텍스트에서는 false 를 반환해
     * 메인 전용(position_menu='__index__') 행이 새어나가지 않게 한다(기존 기본값과 동일).
     * 도메인 루트 기반 배포 기준이며, 서브디렉터리 설치는 대상이 아니다.
     */
    private static function isMainScreenRequest(): bool
    {
        if (!isset($_SERVER['REQUEST_URI'])) {
            return false;
        }
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        return rtrim((string) ($path ?? ''), '/') === '';
    }

    /**
     * 페이지별 블록 출력
     *
     * @param int $pageId 페이지 ID
     * @param bool $useCache 캐시 사용 여부
     * @return string 렌더링된 HTML
     */
    public static function page(int $pageId, bool $useCache = true): string
    {
        return self::getRenderService()->renderPage($pageId, $useCache);
    }

    /**
     * 헤더 위 최상단 바 블록 (topbar 위치 — 공지바 등)
     */
    public static function topbar(int $domainId, ?string $menuCode = null, bool $useCache = true, ?bool $isMainScreen = null): string
    {
        return self::position($domainId, 'topbar', $menuCode, $useCache, $isMainScreen);
    }

    /**
     * 메인 화면 블록 (index 위치)
     */
    public static function index(int $domainId, bool $useCache = true): string
    {
        return self::position($domainId, 'index', null, $useCache);
    }

    /**
     * 왼쪽 사이드바 블록
     */
    public static function left(int $domainId, ?string $menuCode = null, bool $useCache = true): string
    {
        return self::position($domainId, 'left', $menuCode, $useCache);
    }

    /**
     * 오른쪽 사이드바 블록
     */
    public static function right(int $domainId, ?string $menuCode = null, bool $useCache = true): string
    {
        return self::position($domainId, 'right', $menuCode, $useCache);
    }

    /**
     * 서브페이지 상단 블록
     */
    public static function subhead(int $domainId, ?string $menuCode = null, bool $useCache = true): string
    {
        return self::position($domainId, 'subhead', $menuCode, $useCache);
    }

    /**
     * 서브페이지 하단 블록
     */
    public static function subfoot(int $domainId, ?string $menuCode = null, bool $useCache = true): string
    {
        return self::position($domainId, 'subfoot', $menuCode, $useCache);
    }

    /**
     * 콘텐츠 상단 블록
     */
    public static function contenthead(int $domainId, ?string $menuCode = null, bool $useCache = true): string
    {
        return self::position($domainId, 'contenthead', $menuCode, $useCache);
    }

    /**
     * 콘텐츠 하단 블록
     */
    public static function contentfoot(int $domainId, ?string $menuCode = null, bool $useCache = true): string
    {
        return self::position($domainId, 'contentfoot', $menuCode, $useCache);
    }

    /**
     * 캐시 무효화 - 위치별
     */
    public static function invalidatePosition(int $domainId, string $position, ?string $menuCode = null): void
    {
        self::getRenderService()->invalidatePositionListCache($domainId, $position, $menuCode);
    }

    /**
     * 캐시 무효화 - 페이지별
     */
    public static function invalidatePage(int $pageId): void
    {
        self::getRenderService()->invalidatePageListCache($pageId);
    }

    /**
     * 캐시 무효화 - 도메인 전체
     */
    public static function invalidateDomain(int $domainId): void
    {
        self::getRenderService()->invalidateDomainCache($domainId);
    }

    /**
     * RenderService 인스턴스 반환
     */
    private static function getRenderService(): BlockRenderService
    {
        if (self::$renderService === null) {
            self::$renderService = DependencyContainer::getInstance()->get(BlockRenderService::class);
        }

        return self::$renderService;
    }

    /**
     * RenderService 인스턴스 설정 (테스트용)
     */
    public static function setRenderService(?BlockRenderService $service): void
    {
        self::$renderService = $service;
    }
}
