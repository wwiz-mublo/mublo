<?php
declare(strict_types=1);
namespace Mublo\Core\Rendering;

use Mublo\Core\Response\ViewResponse;
use Mublo\Core\Context\Context;
use Mublo\Service\Auth\AuthService;
use Mublo\Service\Menu\MenuService;
use Mublo\Service\Block\BlockRenderService;
use Mublo\Infrastructure\Security\CsrfManager;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Core\Event\Rendering\ViewContextCreatedEvent;
use Mublo\Core\Event\Rendering\FrameTemplateSourceCollectEvent;
use Mublo\Core\Event\Tracking\PageViewedEvent;
use Mublo\Repository\Frame\DomainFrameOverrideRepository;
use Mublo\Service\Frame\DomainFrameService;
use Mublo\Core\Registry\CategoryProviderRegistry;
use Mublo\Core\Rendering\AssetManager;
use Mublo\Enum\Block\LayoutType;
use Mublo\Helper\View\ViewFormatHelper;
use Mublo\Helper\View\ViewContentHelper;
use Mublo\Helper\Editor\EditorHelper;
use Mublo\Service\Notification\MemberNotificationService;

/**
 * Class FrontViewRenderer
 *
 * ============================================================
 * FrontViewRenderer – Front 영역 화면 조립자
 * ============================================================
 *
 * 이 클래스는 Front 영역에서
 * 하나의 요청이 "어떤 순서와 구성으로 화면에 출력되는지"를
 * 결정하고 조립하는 최상위 렌더러이다.
 *
 * ------------------------------------------------------------
 * [역할 요약]
 * ------------------------------------------------------------
 *
 * - Controller 가 반환한 ViewResponse 를 해석한다.
 * - Front 영역에 맞는 출력 흐름을 결정한다.
 * - Header / Layout / Content / Footer 를 조립한다.
 * - 공통 데이터(메뉴, 회원, 사이트설정)를 모든 View에 주입한다.
 *
 * LayoutManager 는 이 클래스가 사용하는 "도구"일 뿐,
 * 출력의 주도권은 FrontViewRenderer 에 있다.
 *
 * ------------------------------------------------------------
 * [책임]
 * ------------------------------------------------------------
 *
 * - Front 영역 화면 조립 책임
 * - 출력 순서 제어
 * - Front 전용 규칙 적용
 *
 * ------------------------------------------------------------
 * [View Context]
 * ------------------------------------------------------------
 *
 * View 파일에서 $this로 접근 가능한 기능:
 * - $this->pagination($data) : 페이지네이션 렌더링
 * - $this->component($name, $data) : 컴포넌트 렌더링
 * - $this->format->method() : 포맷팅 헬퍼 (ViewFormatHelper)
 * - $this->content->method() : 콘텐츠 파싱 헬퍼 (ViewContentHelper)
 *
 * ------------------------------------------------------------
 * [LayoutManager 와의 관계]
 * ------------------------------------------------------------
 *
 * - LayoutManager 는 body 영역의 레이아웃과 스킨을
 *   결정하는 역할만 담당한다.
 * - LayoutManager 는 페이지 전체를 조립하지 않는다.
 * - FrontViewRenderer 는 LayoutManager 를 호출하여
 *   body 레이아웃 HTML 을 얻는다.
 *
 * ------------------------------------------------------------
 * [금지 사항]
 * ------------------------------------------------------------
 *
 * - Layout 내부 구조 정의
 * - 컴포넌트 내부 구현
 * - 비즈니스 로직 처리
 * - Admin 영역 출력 규칙 침범
 *
 * ------------------------------------------------------------
 * 이 클래스는
 * "Front 화면이 어떻게 만들어지는지"를
 * 가장 먼저 봐야 할 기준점(anchor)이다.
 * ------------------------------------------------------------
 */
class FrontViewRenderer implements ViewRendererInterface
{
    protected LayoutManager $layoutManager;
    protected AuthService $authService;
    protected MenuService $menuService;
    protected BlockRenderService $blockRenderService;
    protected CsrfManager $csrfManager;
    protected EventDispatcher $eventDispatcher;
    protected AssetManager $assetManager;
    protected CategoryProviderRegistry $categoryRegistry;
    protected ?MemberNotificationService $notificationService;
    protected FrontViewRuntime $frontViewRuntime;

    /**
     * View Context (View에서 $this로 접근)
     */
    protected ?ViewContext $viewContext = null;

    /**
     * Frame 스킨명
     */
    protected string $frameSkin = 'basic';

    /**
     * 프레임 파일 베이스 디렉터리 (패키지 오버라이드). null = 코어.
     */
    protected ?string $frameBasePath = null;

    /**
     * 공통 데이터 (모든 View에 주입)
     */
    protected array $commonData = [];

    /**
     * 도메인 프레임 오버라이드 게시본 (part => row). render() 초입에 1회 해석.
     */
    protected array $frameOverrides = [];

    /**
     * 프레임 오버라이드 렌더용 도메인 ID (수집 이벤트 디스패치에 사용)
     */
    protected int $frameDomainId = 1;

    public function __construct(
        LayoutManager $layoutManager,
        AuthService $authService,
        MenuService $menuService,
        BlockRenderService $blockRenderService,
        CsrfManager $csrfManager,
        EventDispatcher $eventDispatcher,
        AssetManager $assetManager,
        CategoryProviderRegistry $categoryRegistry,
        ?MemberNotificationService $notificationService = null,
        protected ?DomainFrameOverrideRepository $frameOverrideRepository = null,
        ?FrontViewRuntime $frontViewRuntime = null
    ) {
        $this->layoutManager = $layoutManager;
        $this->authService = $authService;
        $this->menuService = $menuService;
        $this->blockRenderService = $blockRenderService;
        $this->csrfManager = $csrfManager;
        $this->eventDispatcher = $eventDispatcher;
        $this->assetManager = $assetManager;
        $this->categoryRegistry = $categoryRegistry;
        $this->notificationService = $notificationService;
        $this->frontViewRuntime = $frontViewRuntime ?? new FrontViewRuntime();
    }

    /**
     * Front 화면 렌더링 진입점
     */
    public function render(ViewResponse $response, Context $context): void
    {
        /* =====================================================
         * 스킨 결정 (Context에서 가져오기)
         * ===================================================== */

        $this->frameSkin = $context->getFrameSkin();
        $this->frameBasePath = $context->getFrameBasePath();

        /* =====================================================
         * ViewContext 초기화 (Front용, Helper 없이 기본 기능만)
         * ===================================================== */

        $this->viewContext = new ViewContext('front');
        $this->viewContext->setQueryString(http_build_query($context->getRequest()->getQuery()));
        $this->viewContext->setHelper('format', new ViewFormatHelper());
        $this->viewContext->setHelper('content', new ViewContentHelper());
        $this->viewContext->setHelper('assets', $this->assetManager);
        $this->viewContext->setCategoryRegistry($this->categoryRegistry, $context->getDomainId() ?? 1);

        // Plugin/Package가 자체 ViewHelper를 등록할 수 있는 확장점
        $this->eventDispatcher->dispatch(
            new ViewContextCreatedEvent($this->viewContext)
        );

        /* =====================================================
         * 공통 데이터 수집
         * ===================================================== */

        $this->commonData = $this->collectCommonData($context);
        $this->frontViewRuntime->initialize($this->viewContext, $this->commonData['mublo']);

        /* =====================================================
         * 도메인 프레임 오버라이드 해석 (1회)
         *
         * theme_config frame_edit.parts 플래그(도메인 캐시에 실림)가 있는
         * 도메인만 파트별 게시본을 조회한다 — 플래그 없는 도메인은 쿼리 0회.
         * ===================================================== */

        $this->frameDomainId = $context->getDomainId() ?? 1;
        $this->frameOverrides = $this->resolveFrameOverrides($context);

        /* =====================================================
         * 에디터 런타임 설정 주입
         *
         * - 업로드 URL: 프레임워크 라우트 (/api/v1/editor/upload)
         * - CSRF 토큰: 업로드 요청 검증용 (X-CSRF-Token 헤더로 전송)
         * 세션 미들웨어가 완료된 이후이므로 안전하게 토큰 발급 가능
         * ===================================================== */
        EditorHelper::setEditor($context->getDomainInfo()?->getSiteConfig()['editor'] ?? 'mublo-editor');
        EditorHelper::configure([
            'upload_url'  => '/api/v1/editor/upload',
            'convert_url' => '/api/v1/editor/convert',
            'og_url'      => '/api/v1/editor/og',
            'csrf_token'  => $this->csrfManager->getToken(),
        ]);

        /* =====================================================
         * 페이지 설정 (_pageConfig) 추출
         *
         * BlockPage 등에서 전달하는 레이아웃 오버라이드
         * ===================================================== */

        $viewData = $response->getViewData();
        $pageConfig = $viewData['_pageConfig'] ?? [];
        $useHeader = (bool) ($pageConfig['use_header'] ?? true);
        $useFooter = (bool) ($pageConfig['use_footer'] ?? true);

        /* =====================================================
         * 출력 버퍼링 시작
         *
         * 블록/플러그인이 렌더링 중 addCss/addJs를 호출하면
         * 버퍼링 완료 후 플레이스홀더 치환으로
         * CSS → <head>, JS → </body> 앞에 삽입
         *
         * try-finally로 감싸서 에러 발생 시에도 버퍼가 반드시 출력되도록 보장
         * ===================================================== */
        ob_start();

        try {

        /* =====================================================
         * PARTIAL 출력 (단독 View)
         * ===================================================== */
        if ($response->isFullPageHint()) {
            // 1. Head
            $this->includeFrameView('Head.php', $viewData);

            // 2. Content
            $this->renderContent($response, $context);

            // 3. Foot
            $this->includeFrameView('Foot.php', $viewData);

            return;
        }

        /* =====================================================
         * FULL PAGE 출력 (2-pass 렌더링)
         *
         * 1차: Content를 버퍼에 먼저 렌더링
         *      → 스킨이 $this->layout() 으로 header/footer 힌트 선언
         * 2차: 힌트를 반영하여 Head → Header? → Layout → Content → Footer? → Foot 조립
         * ===================================================== */

        // --- 1차: Content 버퍼링 ---
        ob_start();
        $this->renderContent($response, $context);
        $contentHtml = ob_get_clean();

        // 스킨이 선언한 프레임 탈출 모드 적용.
        // - standalone: 스킨이 <html>부터 문서 전체를 작성
        // - chromeless: Core Head/Foot만 사용하고 사이트 크롬·레이아웃·블록 위치는 생략
        if ($this->renderContentEscapeMode($response, $contentHtml, $viewData)) {
            return;
        }

        // 스킨에서 선언한 레이아웃 힌트 반영 (스킨 > _pageConfig > 기본값)
        $useHeader = $this->viewContext->getLayoutOption('header', $useHeader);
        $useFooter = $this->viewContext->getLayoutOption('footer', $useFooter);

        // --- 2차: 페이지 조립 ---
        $domainId = $context->getDomainId() ?? 1;
        $menuCode = $context->getCurrentMenuCode();

        // 현재 요청이 메인화면(홈, 루트 '/')인지 — position_menu='__index__'(메인 전용) 블록 필터에 사용.
        // 홈은 블록 페이지로 렌더돼 pageType 이 'page' 이므로, 루트 경로로 판별한다.
        $pageType = $this->resolvePageType($response, $pageConfig);
        $isMainScreen = (rtrim($context->getRequest()->getPath(), '/') === '');

        // 1. Head (html / head / body 시작)
        $this->includeFrameView('Head.php', $viewData);

        // 1-1. topbar 블록 (Header '위', 최상단 — 공지바 등)
        if ($useHeader) {
            echo $this->blockRenderService->renderPosition($domainId, 'topbar', $menuCode, true, $isMainScreen);
        }

        // 2. Header (Layout 바깥, 전역 UI)
        if ($useHeader) {
            $this->includeFrameView('Header.php', $viewData);
        }

        // 2-1. subhead 블록 (Header 아래, Layout 바깥)
        if ($useHeader) {
            echo $this->blockRenderService->renderPosition($domainId, 'subhead', $menuCode, true, $isMainScreen);
        }

        // 3. Layout Open (본문 래퍼 시작)
        //
        // 레이아웃 결정 우선순위:
        //   블록페이지 > 메뉴 오버라이드 > 스킨 힌트 > 헤더없음(full) > 도메인 기본
        //
        // - 블록페이지는 _pageConfig 에 이미 layout_type 을 담아 오므로 최우선(아래 분기 전체를 건너뜀).
        // - 메뉴 오버라이드: 운영자가 특정 메뉴(예: 메뉴얼)에만 지정한 레이아웃. 도메인 기본과
        //   다른 레이아웃(예: 사이트는 우측 사이드바, 메뉴얼만 전체)을 쓰게 한다.
        // - 스킨 힌트: $this->layout(['layout' => 'full'|'left'|'right'|'both']) — 스킨이 선언한 기본형.
        // - 헤더를 끈 화면(로그인·가입 등)은 사이드바 없는 full 로 강제 — 사이드바는 사이트 크롬이라,
        //   크롬을 벗은 페이지에 사이트 설정의 사이드바만 남으면 어색하다.
        // 블록페이지(pageConfig 에 layout_type 존재)면 결정 로직 자체를 건너뛴다 —
        // 메뉴 오버라이드 조회 쿼리·스킨 힌트 조회를 아끼기 위한 단락(short-circuit)이다.
        // 실제 우선순위 판정은 순수 함수 applyLayoutPrecedence() 로 고정돼 있다.
        if (!isset($pageConfig['layout_type'])) {
            // 메뉴 오버라이드는 URL 맵 매칭 시점(ContextBuilder)에 이미 확정돼 Context 에 실려 있다 —
            // 렌더 때 별도 DB 조회를 하지 않는다(캐시된 URL 맵을 타므로 캐시 히트 시 쿼리 0).
            $pageConfig = self::applyLayoutPrecedence(
                $pageConfig,
                $context->getCurrentMenuLayout(),
                $this->viewContext->getLayoutOptionValue('layout'),
                $useHeader
            );
        }

        $layout = $this->layoutManager->resolve($context, $pageConfig);
        $layoutData = $layout['data'] ?? [];

        $this->includeFrameView('LayoutOpen.php', $layoutData);

        // 3-1. 좌측 사이드바
        $layoutType = (int) ($layoutData['layoutType'] ?? 1);
        if ($layoutType === 2 || $layoutType === 4) {
            $leftMobileClass = empty($layoutData['sidebarLeftMobile']) ? ' mublo-layout__sidebar--mobile-hidden' : '';
            $leftWidthStyle = !empty($layoutData['sidebarLeftWidth'])
                ? ' style="width:' . (int) $layoutData['sidebarLeftWidth'] . 'px"' : '';
            echo '<aside class="mublo-layout__sidebar mublo-layout__sidebar--left' . $leftMobileClass . '"' . $leftWidthStyle . '>';
            echo $this->blockRenderService->renderPosition($domainId, 'left', $menuCode, true, $isMainScreen);
            echo '</aside>';
        }

        // 4. 메인 콘텐츠 영역 (버퍼링된 Content 출력)
        echo '<div class="mublo-layout__content">';
        echo $this->blockRenderService->renderPosition($domainId, 'contenthead', $menuCode, true, $isMainScreen);
        echo $contentHtml;
        echo $this->blockRenderService->renderPosition($domainId, 'contentfoot', $menuCode, true, $isMainScreen);
        echo '</div>';

        // 4-1. 우측 사이드바
        if ($layoutType === 3 || $layoutType === 4) {
            $rightMobileClass = empty($layoutData['sidebarRightMobile']) ? ' mublo-layout__sidebar--mobile-hidden' : '';
            $rightWidthStyle = !empty($layoutData['sidebarRightWidth'])
                ? ' style="width:' . (int) $layoutData['sidebarRightWidth'] . 'px"' : '';
            echo '<aside class="mublo-layout__sidebar mublo-layout__sidebar--right' . $rightMobileClass . '"' . $rightWidthStyle . '>';
            echo $this->blockRenderService->renderPosition($domainId, 'right', $menuCode, true, $isMainScreen);
            echo '</aside>';
        }

        // 5. Layout Close
        $this->includeFrameView('LayoutClose.php', $layoutData);

        // 5-1. subfoot 블록 (Layout 아래, Footer 바깥)
        if ($useFooter) {
            echo $this->blockRenderService->renderPosition($domainId, 'subfoot', $menuCode, true, $isMainScreen);
        }

        // 6. Footer (Layout 바깥, 전역 UI)
        if ($useFooter) {
            $this->includeFrameView('Footer.php', $viewData);
        }

        // 6-1. 플러그인 프론트 렌더링 슬롯 (팝업, 위젯 등)
        $frontFootEvent = $this->eventDispatcher->dispatch(
            new \Mublo\Core\Event\Rendering\FrontFootRenderEvent($domainId)
        );
        $pluginFootHtml = $frontFootEvent->getHtml();
        if ($pluginFootHtml !== '') {
            echo $pluginFootHtml;
        }

        // 7. Foot (script / body 종료)
        $this->includeFrameView('Foot.php', $viewData);

        // PageView 이벤트 발행 (방문통계 등 플러그인이 구독)
        $request = $context->getRequest();
        $this->eventDispatcher->dispatch(new PageViewedEvent(
            domainId: $context->getDomainId() ?? 0,
            url: $request->getUri(),
            pageType: $pageType,
            memberId: $this->authService->user()['member_id'] ?? null,
            ipAddress: $request->getClientIp(),
            userAgent: $request->header('User-Agent') ?? '',
            referer: $request->header('Referer') ?? ''
        ));

        } finally {
            $this->flushWithAssets();
        }
    }

    /**
     * 스킨이 선언한 프레임 탈출 모드를 렌더링한다.
     *
     * 콘텐츠를 먼저 한 번 렌더링한 뒤 호출되므로 스킨이 ViewContext::layout()으로
     * 선언한 값을 해석할 수 있다. true를 반환하면 호출자는 일반 프레임 조립을
     * 중단해야 한다.
     *
     * @param array<string, mixed> $viewData
     */
    protected function renderContentEscapeMode(
        ViewResponse $response,
        string $contentHtml,
        array $viewData
    ): bool {
        // 기존 standalone 계약을 그대로 유지한다.
        if ($this->viewContext->getLayoutOption('standalone', false)) {
            echo $contentHtml;
            return true;
        }

        if ($this->viewContext->getLayoutOptionValue('mode', 'default') !== 'chromeless'
            || !$this->isIndexViewResponse($response)
        ) {
            return false;
        }

        $this->includeFrameView('Head.php', $viewData);
        echo $contentHtml;
        $this->includeFrameView('Foot.php', $viewData);
        return true;
    }

    /**
     * chromeless는 Core Index 스킨 전용 탈출구다.
     *
     * 절대 경로 뷰나 다른 Front 그룹에서 같은 옵션을 선언해도 일반 프레임 조립으로
     * 폴백시켜 회원·인증·패키지 화면이 실수로 사이트 크롬을 우회하지 못하게 한다.
     */
    private function isIndexViewResponse(ViewResponse $response): bool
    {
        if ($response->isAbsolutePath()) {
            return false;
        }

        $group = explode('/', $response->getViewPath(), 2)[0] ?? '';
        return strcasecmp($group, 'index') === 0;
    }

    /**
     * 레이아웃 결정 우선순위를 pageConfig 에 적용한다 (순수 함수 — 회귀 고정용).
     *
     * 우선순위: 블록페이지 > 메뉴 오버라이드 > 스킨 힌트 > 헤더없음(full) > 도메인 기본.
     * - 블록페이지: pageConfig 에 layout_type 이 이미 있으면 그대로 최우선(변경 없음).
     * - 메뉴 오버라이드: 운영자가 메뉴에 지정한 값(null 이면 없음). 스킨 힌트보다 우선.
     * - 스킨 힌트: $this->layout(['layout' => 'full'|'left'|'right'|'both']) 문자열.
     * - 헤더없음: 위 어느 것도 없고 헤더를 끈 화면이면 사이드바 없는 full 로 강제.
     * - 도메인 기본: 아무것도 안 정해지면 layout_type 을 비운 채 둬 LayoutManager 가 siteConfig 를 쓴다.
     *
     * @param array<string,mixed>      $pageConfig   현재 페이지 설정
     * @param array<string,mixed>|null $menuOverride 메뉴별 오버라이드(Context::getCurrentMenuLayout)
     * @param mixed                    $layoutHint   스킨 레이아웃 힌트(문자열 또는 null)
     * @param bool                     $useHeader    헤더 사용 여부
     * @return array<string,mixed> 우선순위가 반영된 pageConfig
     */
    public static function applyLayoutPrecedence(
        array $pageConfig,
        ?array $menuOverride,
        mixed $layoutHint,
        bool $useHeader
    ): array {
        // 블록페이지 최우선 — 페이지가 자체 레이아웃을 담아 왔으면 아무것도 덮지 않는다.
        if (isset($pageConfig['layout_type'])) {
            return $pageConfig;
        }

        if ($menuOverride !== null) {
            // 운영자가 이 메뉴에 명시 지정한 오버라이드가 스킨 힌트보다 우선.
            return array_merge($pageConfig, $menuOverride);
        }

        if (is_string($layoutHint) && $layoutHint !== '') {
            $pageConfig['layout_type'] = (match (strtolower($layoutHint)) {
                'left' => LayoutType::LEFT,
                'right' => LayoutType::RIGHT,
                'both' => LayoutType::BOTH,
                default => LayoutType::FULL,
            })->value;
            return $pageConfig;
        }

        if (!$useHeader) {
            $pageConfig['layout_type'] = LayoutType::FULL->value;
        }

        return $pageConfig;
    }

    /**
     * 페이지 유형 판별
     */
    private function resolvePageType(ViewResponse $response, array $pageConfig): string
    {
        if (!empty($pageConfig['page_id'])) {
            return 'page';
        }

        $viewPath = $response->getViewPath();

        // Core 고유 페이지 타입
        if (str_starts_with($viewPath, 'Index/')) return 'index';
        if (str_starts_with($viewPath, 'Auth/')) return 'auth';
        if (str_starts_with($viewPath, 'Member/')) return 'member';
        if (str_starts_with($viewPath, 'Search/')) return 'search';

        // Package/Plugin에 위임
        $event = $this->eventDispatcher->dispatch(
            new \Mublo\Core\Event\Rendering\PageTypeResolveEvent($viewPath)
        );

        return $event->getPageType() ?? 'other';
    }

    /**
     * 공통 데이터 수집
     *
     * 모든 Front View에는 단일 예약 변수 $mublo가 주입된다.
     * 원시 세션/Entity 대신 스킨에 안전한 읽기 전용 스냅샷만 포함한다.
     */
    protected function collectCommonData(Context $context): array
    {
        $domainId = $context->getDomainId() ?? 1;
        $domainInfo = $context->getDomainInfo();

        // 메뉴 트리 (에러 시 빈 배열)
        try {
            $menuTree = $this->menuService->getTreeHierarchy($domainId);
        } catch (\Throwable $e) {
            $menuTree = [];
            if (($_ENV['APP_DEBUG'] ?? 'false') === 'true') {
                error_log('[FrontViewRenderer] Menu tree error: ' . $e->getMessage());
            }
        }

        // 유틸리티 메뉴 (에러 시 빈 배열)
        try {
            $utilityMenus = $this->menuService->getUtilityMenus($domainId);
        } catch (\Throwable $e) {
            $utilityMenus = [];
        }

        // 푸터 메뉴 (에러 시 빈 배열)
        try {
            $footerMenus = $this->menuService->getFooterMenus($domainId);
        } catch (\Throwable $e) {
            $footerMenus = [];
        }

        // 고객센터 정보: 패키지 오버라이드(siteOverrides) → 사이트 고객센터 설정(cs_*) → 대표 정보
        $companyConfig = $domainInfo?->getCompanyConfig() ?? [];
        $csInfo = [
            'tel'      => $context->getSiteOverride('cs_tel', $companyConfig['cs_tel'] ?? $companyConfig['tel'] ?? ''),
            'time'     => $context->getSiteOverride('cs_time', $companyConfig['cs_time'] ?? ''),
            'email'    => $context->getSiteOverride('cs_email', $companyConfig['email'] ?? ''),
            'ict_mark' => $context->getSiteOverride('ict_mark', ''),
        ];

        $request = $context->getRequest();
        $siteUrl = $request->getScheme() . '://' . $request->getHost();
        $currentUrl = $siteUrl . $request->getPath();

        $currentUser = $this->authService->currentUser();
        $notificationUnreadCount = 0;
        if ($currentUser !== null && $this->notificationService !== null) {
            try {
                $notificationUnreadCount = $this->notificationService->unreadCount(
                    $domainId,
                    $currentUser->memberId
                );
            } catch (\Throwable $e) {
                if (($_ENV['APP_DEBUG'] ?? 'false') === 'true') {
                    error_log('[FrontViewRenderer] Notification count error: ' . $e->getMessage());
                }
            }
        }

        $member = $currentUser === null ? null : [
            'memberId' => $currentUser->memberId,
            'domainId' => $currentUser->domainId,
            'userId' => $currentUser->userId,
            'nickname' => $currentUser->nickname,
            'displayName' => $currentUser->displayName(),
            'levelValue' => $currentUser->levelValue,
            'isAdmin' => $currentUser->admin,
            'isSuper' => $currentUser->super,
            'canOperateDomain' => $currentUser->canOperateDomain,
            'avatarUrl' => $currentUser->avatar,
        ];

        return ['mublo' => [
            'contractVersion' => FrontViewContract::VERSION,
            'site' => [
                'domainId' => $domainId,
                'url' => $siteUrl,
                'config' => $domainInfo?->getSiteConfig() ?? [],
                'company' => $companyConfig,
                'seo' => $domainInfo?->getSeoConfig() ?? [],
                'images' => $context->getSiteImageUrls(),
                'customerService' => $csInfo,
            ],
            'viewer' => [
                'available' => true,
                'authenticated' => $currentUser !== null,
                'member' => $member,
                'notificationUnreadCount' => $notificationUnreadCount,
            ],
            'request' => [
                'available' => true,
                'url' => $currentUrl,
                'path' => $request->getPath(),
                'query' => $request->getQuery(),
            ],
            'navigation' => [
                'menuTree' => $menuTree,
                'utilityMenus' => $utilityMenus,
                'footerMenus' => $footerMenus,
                'currentMenuCode' => $context->getCurrentMenuCode(),
            ],
            'theme' => [
                'frameSkin' => $this->frameSkin,
            ],
            'security' => [
                'available' => true,
                'csrfToken' => $this->csrfManager->getToken(),
            ],
            'runtime' => [
                'area' => 'front',
                'viewerAware' => true,
                'preview' => false,
            ],
        ]];
    }

    /**
     * View 데이터에 공통 데이터 병합
     *
     * $mublo는 예약 키이며 Controller/확장이 덮어쓸 수 없다.
     */
    protected function mergeData(array $viewData): array
    {
        if (array_key_exists(FrontViewContract::RESERVED_KEY, $viewData)) {
            throw new \LogicException('"mublo" is a reserved view-data key.');
        }

        return $this->commonData + $viewData;
    }

    /**
     * --------------------------------------------------------
     * Content View 렌더링 (Front 전용, 방어 포함)
     *
     * View 파일은 ViewContext의 render() 메서드 내에서 include되므로
     * View에서 $this로 ViewContext에 접근 가능:
     * - $this->pagination()
     * - $this->component()
     *
     * 경로 예시:
     * - 상대 경로: board/list → views/Front/Board/{skin}/List.php
     * - 절대 경로: /path/to/Plugin/views/Front/Product → /path/to/Plugin/views/Front/Product.php
     * --------------------------------------------------------
     */
    protected function renderContent(ViewResponse $response, Context $context): void
    {
        $viewPath = $response->getViewPath();

        // 절대 경로인 경우 (Plugin/Package용)
        if ($response->isAbsolutePath()) {
            $path = $viewPath . '.php';

            // 경로 조작 방지 (.. 포함 여부만 체크)
            if (str_contains($viewPath, '..')) {
                if (($_ENV['APP_DEBUG'] ?? 'false') === 'true') {
                    error_log("[Security] Path traversal attempt detected: {$viewPath}");
                }
                return;
            }

            if (!file_exists($path)) {
                error_log("[FrontViewRenderer] Absolute view not found: {$path}");
                $this->renderFallbackError($response->getViewData());
                return;
            }

            // ViewContext의 render()를 통해 include (View에서 $this 접근 가능)
            $this->renderViewIsolated($path, $this->mergeData($response->getViewData()), $viewPath);
            return;
        }

        // 상대 경로인 경우 (Core용, 기존 로직)
        $logicalPath = $viewPath;

        // [보완 1] 경로 조작 방지 (Path Traversal)
        if (str_contains($logicalPath, '..') ||
            str_contains($logicalPath, '\\') ||
            str_starts_with($logicalPath, '/')) {
            if (($_ENV['APP_DEBUG'] ?? 'false') === 'true') {
                error_log("[Security] Path traversal attempt detected: {$logicalPath}");
            }
            return;
        }

        $parts = explode('/', $logicalPath);

        if (count($parts) !== 2) {
            return;
        }

        [$group, $file] = array_map(
            fn ($v) => ucfirst($v),
            $parts
        );

        // ViewGroup 별 Front 스킨 선택
        $skin = $context->getFrontSkin($group) ?? 'basic';

        $path = MUBLO_VIEW_PATH
            . "/Front/{$group}/{$skin}/{$file}.php";

        // 디버그: Content 렌더링 시도 로그
        if (($_ENV['APP_DEBUG'] ?? 'false') === 'true') {
            echo "<!-- renderContent: logicalPath={$logicalPath}, group={$group}, skin={$skin}, file={$file} -->\n";
            echo "<!-- Full path: {$path} -->\n";
        }

        if (!file_exists($path)) {
            // per-file 폴백: 선택 스킨에 해당 파일이 없으면 basic 스킨의 동일 파일로 폴백한다.
            // - 커스텀 스킨이 일부 파일만 오버라이드해도(예: Login.php 한 장) 나머지는 basic 그대로 렌더됨
            // - 코어가 그룹에 새 파일을 추가해도 기존 커스텀 스킨이 깨지지 않음(에러 대신 기본 렌더)
            // 프레임 렌더(includeFrameView)의 패키지→코어 폴백과 동일한 철학.
            if ($skin !== 'basic') {
                $basicPath = MUBLO_VIEW_PATH . "/Front/{$group}/basic/{$file}.php";
                if (file_exists($basicPath)) {
                    $this->renderViewIsolated($basicPath, $this->mergeData($response->getViewData()), "Front/{$group}/basic/{$file}.php");
                    return;
                }
            }

            error_log("[FrontViewRenderer] View not found: Front/{$group}/{$skin}/{$file}.php");
            $this->renderFallbackError($response->getViewData());
            return;
        }

        // ViewContext의 render()를 통해 include (View에서 $this 접근 가능)
        $this->renderViewIsolated($path, $this->mergeData($response->getViewData()), "Front/{$group}/{$skin}/{$file}.php");
    }

    /**
     * 출력 버퍼 수집 + 에셋 플레이스홀더 치환 후 출력
     *
     * 버퍼가 없거나 치환 실패 시에도 안전하게 출력
     */
    protected function flushWithAssets(): void
    {
        $html = ob_get_clean();

        if ($html === false) {
            return;
        }

        // CSS 슬롯: addCss(path, 'name') → <!-- MUBLO_CSS_name --> 자리에 주입(템플릿이 위치 결정).
        // 마커가 없는 슬롯은 유실 방지를 위해 기본 대역(<!-- MUBLO_CSS -->)으로 폴백한다.
        $leftover = [];
        foreach ($this->assetManager->getSlotCssGroups() as $slot => $paths) {
            $count = 0;
            $html = str_replace(
                '<!-- MUBLO_CSS_' . $slot . ' -->',
                $this->assetManager->renderCssLinks($paths),
                $html,
                $count
            );
            if ($count === 0) {
                $leftover = array_merge($leftover, $paths);
            }
        }
        // 사용되지 않은 슬롯 마커 제거 (등록 CSS 없는 슬롯 자리)
        $html = preg_replace('/[ \t]*<!-- MUBLO_CSS_[A-Za-z0-9_-]+ -->[ \t]*\n?/', '', $html);

        // 기본 대역(슬롯 없는 CSS + 폴백) → 스킨 뒤.
        $html = str_replace('<!-- MUBLO_CSS -->', $this->assetManager->renderMainCss($leftover), $html);

        // JS 슬롯 (CSS와 동일 메커니즘): addJs(path, 'name') → <!-- MUBLO_JS_name -->. 마커 없으면 기본 대역 폴백.
        $leftoverJs = [];
        foreach ($this->assetManager->getSlotJsGroups() as $slot => $paths) {
            $count = 0;
            $html = str_replace(
                '<!-- MUBLO_JS_' . $slot . ' -->',
                $this->assetManager->renderJsLinks($paths),
                $html,
                $count
            );
            if ($count === 0) {
                $leftoverJs = array_merge($leftoverJs, $paths);
            }
        }
        // 사용되지 않은 JS 슬롯 마커 제거
        $html = preg_replace('/[ \t]*<!-- MUBLO_JS_[A-Za-z0-9_-]+ -->[ \t]*\n?/', '', $html);

        // 기본 대역(슬롯 없는 JS + 폴백) → body 끝.
        $html = str_replace('<!-- MUBLO_JS -->', $this->assetManager->renderMainJs($leftoverJs), $html);
        echo $html;
    }

    /**
     * --------------------------------------------------------
     * 안전한 View 렌더링
     * (Partial / Layout / Header / Footer 공용)
     *
     * ViewContext를 통해 렌더링하여 $this 접근 가능
     * --------------------------------------------------------
     */
    /**
     * 프레임 partial include.
     *
     * frameBasePath(패키지 프레임 오버라이드)가 설정돼 있고 해당 파일이 존재하면 그걸,
     * 아니면 코어 views/Front/frame 를 사용한다 (per-file 폴백).
     *
     * @param string $file 프레임 파일명 (예: 'Head.php', 'Header.php')
     */
    protected function includeFrameView(string $file, array $data = []): void
    {
        // ① Package 프레임 오버라이드 — 파트 단위 판정, 항상 최우선 (기능 화면의 구체적 의도)
        if ($this->frameBasePath !== null) {
            $pkgPath = $this->frameBasePath . '/' . $file;
            if (is_file($pkgPath)) {
                $this->renderViewIsolated($pkgPath, $this->mergeData($data), "frame:{$file}");
                return;
            }
        }

        // ② 도메인 프레임 오버라이드 (published) — 실패 시 ③으로 폴백
        $part = strtolower(pathinfo($file, PATHINFO_FILENAME));
        if (isset($this->frameOverrides[$part]) && $this->renderFrameOverride($part, $this->frameOverrides[$part])) {
            return;
        }

        // ③ 파일 스킨 — 패키지가 일부 파트만 제공해도 나머지는 코어 그대로.
        //   선택 프레임 스킨에 해당 파트 파일이 없으면 basic 프레임의 동일 파일로 per-file 폴백한다
        //   (콘텐츠 스킨 renderContent 와 동일 철학 — 스킨 폴더 삭제/부분 오버라이드 방어).
        $relative = "frame/{$this->frameSkin}/{$file}";
        if ($this->frameSkin !== 'basic'
            && !file_exists(MUBLO_VIEW_PATH . '/Front/' . $relative)
        ) {
            $relative = "frame/basic/{$file}";
        }
        $this->includeViewSafely($relative, $data);
    }

    /**
     * 도메인 프레임 오버라이드 게시본 해석
     *
     * @return array<string, array> part => 게시본 행 (html 비어있으면 제외)
     */
    protected function resolveFrameOverrides(Context $context): array
    {
        if ($this->frameOverrideRepository === null) {
            return [];
        }

        $themeConfig = $context->getDomainInfo()?->getThemeConfig() ?? [];
        $parts = DomainFrameService::publishedParts($themeConfig);

        $overrides = [];
        foreach ($parts as $part) {
            try {
                $row = $this->frameOverrideRepository->findPublished($this->frameDomainId, $part);
            } catch (\Throwable $e) {
                error_log("[FrontViewRenderer] Frame override lookup failed ({$part}): " . $e->getMessage());
                continue;
            }

            if ($row !== null && trim((string) ($row['html'] ?? '')) !== '') {
                $overrides[$part] = $row;
            }
        }

        return array_merge($overrides, $this->resolveDraftPreview($context));
    }

    /**
     * draft 미리보기 해석 — 블록 에디터 iframe 전용 (§3.8)
     *
     * `?_frame_draft={part}` 쿼리 + 도메인 운영자 권한일 때만 해당 파트의
     * draft를 렌더한다. draft는 이 경로 외에는 절대 프론트에 노출되지 않는다.
     * 게시 플래그와 무관하게 동작한다 (게시 전 초안 미리보기가 목적).
     *
     * @return array<string, array> part => draft 행 (published와 같은 구조)
     */
    protected function resolveDraftPreview(Context $context): array
    {
        if ($this->frameOverrideRepository === null) {
            return [];
        }

        $part = strtolower(trim((string) $context->getRequest()->query('_frame_draft', '')));
        if (!in_array($part, DomainFrameService::PARTS, true)) {
            return [];
        }

        if (!$this->authService->canOperateDomain()) {
            return [];
        }

        try {
            $row = $this->frameOverrideRepository->find($this->frameDomainId, $part);
        } catch (\Throwable $e) {
            error_log("[FrontViewRenderer] Frame draft preview lookup failed ({$part}): " . $e->getMessage());
            return [];
        }

        if ($row === null || trim((string) ($row['draft_html'] ?? '')) === '') {
            return [];
        }

        return [$part => [
            'html' => (string) $row['draft_html'],
            'css' => (string) ($row['draft_css'] ?? ''),
            'js' => (string) ($row['draft_js'] ?? ''),
        ]];
    }

    /**
     * 도메인 프레임 오버라이드 렌더 — {{...}} 템플릿 치환 (원문 저장 원칙)
     *
     * 실패하면 false를 반환해 파일 스킨으로 폴백한다 (§3.5 — header가
     * 깨져도 사이트는 산다). 출력은 스코프 래퍼로 감싼다:
     * <div class="mublo-frame-{part}"> — 오버라이드 CSS의 표준 타깃.
     *
     * @return bool 렌더 성공 여부
     */
    protected function renderFrameOverride(string $part, array $override): bool
    {
        try {
            $renderer = new FrameTemplateRenderer();
            CoreFrameTemplateSources::apply($renderer, $this->commonData, $this->viewContext);

            // 확장 변수·슬롯 수집 (지연 해석 — 등록만 하고 사용된 토큰만 호출됨)
            $event = $this->eventDispatcher->dispatch(
                new FrameTemplateSourceCollectEvent($this->frameDomainId)
            );
            $renderer->applyCollected($event);

            $html = $renderer->render((string) $override['html']);

            foreach ($renderer->getDiagnostics() as $diag) {
                error_log("[FrontViewRenderer] Frame override {$part} {$diag['type']}: {{{$diag['token']}}} — {$diag['message']}");
            }

            $css = trim((string) ($override['css'] ?? ''));
            $js = trim((string) ($override['js'] ?? ''));

            if ($css !== '') {
                echo '<style>' . $css . '</style>';
            }
            // display:contents — 래퍼가 박스를 만들지 않아 sticky header의
            // containing block이 되지 않는다 (개선 계획 §10.1). DOM 부모·자식
            // 관계는 유지되므로 .mublo-frame-{part} 후손 셀렉터는 그대로 동작.
            echo '<div class="mublo-frame-' . $part . '" style="display:contents">' . $html . '</div>';
            if ($js !== '') {
                echo '<script>' . $js . '</script>';
            }

            return true;
        } catch (\Throwable $e) {
            error_log(sprintf(
                '[FrontViewRenderer] Frame override render failed (%s), falling back to file skin: %s (%s:%d)',
                $part,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));

            return false;
        }
    }

    protected function includeViewSafely(string $relativePath, array $data = []): void
    {
        $fullPath = MUBLO_VIEW_PATH . '/Front/' . $relativePath;

        if (!file_exists($fullPath)) {
            // 디버그 모드에서 파일 미발견 로그
            if (($_ENV['APP_DEBUG'] ?? 'false') === 'true') {
                echo "<!-- View not found: {$fullPath} -->\n";
            }
            return;
        }

        // ViewContext의 render()를 통해 include (View에서 $this 접근 가능)
        $this->renderViewIsolated($fullPath, $this->mergeData($data), $relativePath);
    }

    /**
     * --------------------------------------------------------
     * 스킨 격리 렌더링
     *
     * 스킨 파일에서 Error/Exception이 발생해도 페이지 전체를 죽이지 않고,
     * 그 스킨 자리에만 에러 박스를 출력한다. 블록 렌더(BlockRenderService)의
     * 칸 단위 격리와 같은 철학을 콘텐츠·프레임 스킨에 적용한 것.
     *
     * - 스킨 출력을 자체 버퍼에 먼저 모은다: 절반 출력하다 죽으면
     *   열린 태그가 레이아웃을 깨뜨리므로, 실패 시 통째로 버린다.
     * - 스킨이 자기 ob_start를 연 채 죽어도 버퍼 레벨을 복원한다.
     * --------------------------------------------------------
     */
    protected function renderViewIsolated(string $path, array $data, string $label): void
    {
        $level = ob_get_level();
        ob_start();

        try {
            $this->viewContext->render($path, $data);
            ob_end_flush();
        } catch (\Throwable $e) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }

            error_log(sprintf(
                '[FrontViewRenderer] Skin error in %s: %s (%s:%d)',
                $label,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));

            echo $this->skinErrorBox($label, $e);
        }
    }

    /**
     * 스킨 에러 박스 HTML
     *
     * 운영 모드: 일반 안내만 (예외 메시지는 경로 등 내부 정보를 담을 수 있어 숨김).
     * 디버그 모드: 예외 클래스·메시지·위치를 표시.
     */
    protected function skinErrorBox(string $label, \Throwable $e): string
    {
        $html = '<div class="mublo-skin-error" style="margin:16px;padding:16px;border:1px solid #fca5a5;'
            . 'border-radius:8px;background:#fef2f2;color:#991b1b;font-size:14px">'
            . '<strong>화면 일부를 표시할 수 없습니다.</strong>';

        if (($_ENV['APP_DEBUG'] ?? 'false') === 'true') {
            $html .= '<pre style="margin:8px 0 0;white-space:pre-wrap;font-size:12px;color:#7f1d1d">'
                . htmlspecialchars(
                    sprintf(
                        "%s\n%s: %s\n%s:%d",
                        $label,
                        get_class($e),
                        $e->getMessage(),
                        $e->getFile(),
                        $e->getLine()
                    ),
                    ENT_QUOTES,
                    'UTF-8'
                )
                . '</pre>';
        }

        return $html . '</div>';
    }

    /**
     * 뷰 파일 미발견 시 fallback 에러 화면 렌더링
     *
     * Core의 Error/NotFound 뷰 사용
     */
    protected function renderFallbackError(array $viewData = []): void
    {
        $corePath = MUBLO_VIEW_PATH . '/Error/NotFound.php';
        if (file_exists($corePath)) {
            $this->viewContext->render($corePath, $this->mergeData($viewData));
            return;
        }

        // Core 에러 뷰도 없으면 인라인 렌더링
        echo '<div style="text-align:center;padding:60px 20px">';
        echo '<h2>페이지를 찾을 수 없습니다</h2>';
        echo '<p>요청하신 페이지가 존재하지 않거나 이동되었습니다.</p>';
        echo '<a href="/">홈으로</a>';
        echo '</div>';
    }
}
