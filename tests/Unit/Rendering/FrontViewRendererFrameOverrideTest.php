<?php
/**
 * FrontViewRenderer 도메인 프레임 오버라이드 테스트
 *
 * 계획문서 §3.5 렌더 우선순위 3단 판정을 검증한다:
 * ① Package frameBasePath (파트 단위) → ② published 오버라이드 → ③ 파일 스킨
 * - 오버라이드 렌더 실패 시 파일 스킨 폴백 (사이트 생존)
 * - 플래그 없는 도메인은 오버라이드 조회 쿼리 0회
 */

namespace Tests\Unit\Rendering;

use Tests\TestCase;
use Mublo\Core\Context\Context;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Core\Rendering\FrontViewRenderer;
use Mublo\Core\Rendering\ViewContext;
use Mublo\Entity\Domain\Domain;
use Mublo\Repository\Frame\DomainFrameOverrideRepository;

class FrontViewRendererFrameOverrideTest extends TestCase
{
    private string $prevErrorLog = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->prevErrorLog = (string) ini_get('error_log');
        ini_set('error_log', tempnam(sys_get_temp_dir(), 'mublo-test-log'));
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->prevErrorLog);
        parent::tearDown();
    }

    private array $commonData = [
        'mublo' => [
            'contractVersion' => 1,
            'site' => [
                'domainId' => 1,
                'url' => 'https://demo.test',
                'config' => ['site_title' => '무블로'],
                'company' => [],
                'seo' => [],
                'images' => [],
                'customerService' => [],
            ],
            'viewer' => [
                'available' => true,
                'authenticated' => false,
                'member' => null,
                'notificationUnreadCount' => 0,
            ],
            'request' => ['available' => true, 'url' => '', 'path' => '', 'query' => []],
            'navigation' => [
                'menuTree' => [],
                'utilityMenus' => [],
                'footerMenus' => [],
                'currentMenuCode' => '',
            ],
            'theme' => ['frameSkin' => 'basic'],
            'security' => ['available' => true, 'csrfToken' => ''],
            'runtime' => ['area' => 'front', 'viewerAware' => true, 'preview' => false],
        ],
    ];

    /**
     * 부모 생성자를 우회하고 프레임 렌더 내부에 접근하는 테스트용 렌더러
     */
    private function renderer(
        array $frameOverrides = [],
        ?EventDispatcher $dispatcher = null,
        ?string $frameBasePath = null,
        ?DomainFrameOverrideRepository $repository = null
    ): FrontViewRenderer {
        $r = new class extends FrontViewRenderer {
            public function __construct()
            {
            }

            public function setup(
                ViewContext $vc,
                array $commonData,
                array $frameOverrides,
                EventDispatcher $dispatcher,
                ?string $frameBasePath,
                ?DomainFrameOverrideRepository $repository
            ): void {
                $this->viewContext = $vc;
                $this->commonData = $commonData;
                $this->frameOverrides = $frameOverrides;
                $this->eventDispatcher = $dispatcher;
                $this->frameBasePath = $frameBasePath;
                $this->frameOverrideRepository = $repository;
                $this->frameSkin = 'basic';
                $this->frameDomainId = 1;
            }

            public function setAuthService(\Mublo\Service\Auth\AuthService $auth): void
            {
                $this->authService = $auth;
            }

            public function callIncludeFrameView(string $file): void
            {
                $this->includeFrameView($file, []);
            }

            public function callResolveFrameOverrides(Context $context): array
            {
                return $this->resolveFrameOverrides($context);
            }

            public function setFrameSkinForTest(string $skin): void
            {
                $this->frameSkin = $skin;
            }
        };

        $r->setup(
            new ViewContext('front'),
            $this->commonData,
            $frameOverrides,
            $dispatcher ?? new EventDispatcher(),
            $frameBasePath,
            $repository
        );

        return $r;
    }

    private function capture(FrontViewRenderer $r, string $file): string
    {
        ob_start();
        $r->callIncludeFrameView($file);

        return (string) ob_get_clean();
    }

    public function testPublishedOverrideRendersInsteadOfFileSkin(): void
    {
        $r = $this->renderer([
            'header' => [
                'html' => '<header class="my-header">{{site_name}}</header>',
                'css' => '.mublo-frame-header .my-header{color:red}',
                'js' => 'console.log("hi")',
            ],
        ]);

        $out = $this->capture($r, 'Header.php');

        $this->assertStringContainsString('<div class="mublo-frame-header" style="display:contents">', $out, '스코프 래퍼 (sticky containing block 방지 — display:contents)');
        $this->assertStringContainsString('<header class="my-header">무블로</header>', $out, '변수 치환');
        $this->assertStringContainsString('<style>.mublo-frame-header .my-header{color:red}</style>', $out);
        $this->assertStringContainsString('<script>console.log("hi")</script>', $out);
        $this->assertStringNotContainsString('mublo-header__logo', $out, '파일 스킨은 렌더되지 않아야 한다');
    }

    public function testPriorityIsPerPart(): void
    {
        $r = $this->renderer([
            'footer' => ['html' => '<footer class="my-footer">{{year}}</footer>', 'css' => '', 'js' => ''],
        ]);

        $headerOut = $this->capture($r, 'Header.php');
        $footerOut = $this->capture($r, 'Footer.php');

        $this->assertStringContainsString('mublo-header__logo', $headerOut, 'Header는 파일 스킨 유지');
        $this->assertStringContainsString('my-footer', $footerOut, 'Footer는 오버라이드');
        $this->assertStringContainsString(date('Y'), $footerOut, '{{year}} 치환');
        $this->assertStringNotContainsString('footer-copy', $footerOut, 'Footer 파일 스킨은 렌더되지 않아야 한다');
    }

    public function testOverrideRenderFailureFallsBackToFileSkin(): void
    {
        $throwingDispatcher = new class extends EventDispatcher {
            public function dispatch(\Mublo\Core\Event\EventInterface $event): \Mublo\Core\Event\EventInterface
            {
                throw new \RuntimeException('collect boom');
            }
        };

        $r = $this->renderer(
            ['header' => ['html' => '<header>{{site_name}}</header>', 'css' => '', 'js' => '']],
            $throwingDispatcher
        );

        $out = $this->capture($r, 'Header.php');

        $this->assertStringContainsString('mublo-header__logo', $out, '실패 시 파일 스킨으로 폴백해 사이트가 살아야 한다');
        $this->assertStringNotContainsString('mublo-frame-header', $out);
    }

    public function testPackageFrameBasePathBeatsDomainOverride(): void
    {
        $pkgDir = sys_get_temp_dir() . '/mublo-frame-test-' . uniqid();
        mkdir($pkgDir);
        file_put_contents($pkgDir . '/Header.php', '<header class="pkg-checkout-header"></header>');

        try {
            $r = $this->renderer(
                ['header' => ['html' => '<header class="my-header"></header>', 'css' => '', 'js' => '']],
                null,
                $pkgDir
            );

            $out = $this->capture($r, 'Header.php');

            $this->assertStringContainsString('pkg-checkout-header', $out, 'Package 프레임이 항상 이긴다');
            $this->assertStringNotContainsString('my-header', $out);
        } finally {
            unlink($pkgDir . '/Header.php');
            rmdir($pkgDir);
        }
    }

    public function testFrameSkinPartFallsBackToBasicWhenMissing(): void
    {
        // 선택된 프레임 스킨 폴더에 파트 파일이 없을 때(예: 스킨 폴더 삭제),
        // basic 프레임의 동일 파일로 per-file 폴백해 프레임이 통째로 비지 않아야 한다.
        $r = $this->renderer();
        $r->setFrameSkinForTest('nonexistent-frame-skin-' . uniqid());

        $out = $this->capture($r, 'Header.php');

        $this->assertStringContainsString('mublo-header__logo', $out,
            '존재하지 않는 프레임 스킨 → basic 프레임 동일 파일로 폴백');
    }

    public function testBasicFrameSkinDoesNotDoubleResolve(): void
    {
        // frameSkin 이 이미 basic 이면 폴백 분기를 타지 않고 그대로 렌더한다.
        $r = $this->renderer();

        $out = $this->capture($r, 'Header.php');

        $this->assertStringContainsString('mublo-header__logo', $out, 'basic 프레임 직접 렌더');
    }

    public function testNoFlagMeansZeroOverrideQueries(): void
    {
        $repository = $this->createMock(DomainFrameOverrideRepository::class);
        $repository->expects($this->never())->method('findPublished');

        $domain = $this->createMock(Domain::class);
        $domain->method('getThemeConfig')->willReturn(['skin' => 'basic']); // frame_edit 버킷 없음

        $context = $this->createMock(Context::class);
        $context->method('getDomainInfo')->willReturn($domain);

        $r = $this->renderer([], null, null, $repository);

        $this->assertSame([], $r->callResolveFrameOverrides($context), '플래그 없는 도메인은 쿼리 0회');
    }

    public function testDraftPreviewRequiresOperatorPermission(): void
    {
        $repository = $this->createMock(DomainFrameOverrideRepository::class);
        $repository->expects($this->never())->method('find');

        $auth = $this->createMock(\Mublo\Service\Auth\AuthService::class);
        $auth->method('canOperateDomain')->willReturn(false);

        $r = $this->renderer([], null, null, $repository);
        $r->setAuthService($auth);

        $this->assertSame([], $r->callResolveFrameOverrides($this->contextWithDraftParam('header')),
            '운영자가 아니면 draft는 절대 렌더되지 않는다');
    }

    public function testDraftPreviewLoadsDraftForOperator(): void
    {
        $repository = $this->createMock(DomainFrameOverrideRepository::class);
        $repository->expects($this->once())
            ->method('find')
            ->with(1, 'header')
            ->willReturn([
                'draft_html' => '<header class="draft-header"></header>',
                'draft_css' => '.x{}',
                'draft_js' => '',
                'status' => 'draft',
            ]);

        $auth = $this->createMock(\Mublo\Service\Auth\AuthService::class);
        $auth->method('canOperateDomain')->willReturn(true);

        $r = $this->renderer([], null, null, $repository);
        $r->setAuthService($auth);

        $overrides = $r->callResolveFrameOverrides($this->contextWithDraftParam('header'));

        $this->assertSame('<header class="draft-header"></header>', $overrides['header']['html']);
    }

    private function contextWithDraftParam(string $part): Context
    {
        $request = $this->createMock(\Mublo\Core\Http\Request::class);
        $request->method('query')->willReturnCallback(
            fn(string $key, $default = null) => $key === '_frame_draft' ? $part : $default
        );

        $domain = $this->createMock(Domain::class);
        $domain->method('getThemeConfig')->willReturn([]);

        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getDomainInfo')->willReturn($domain);

        return $context;
    }

    public function testFlaggedDomainLoadsOnlyFlaggedParts(): void
    {
        $repository = $this->createMock(DomainFrameOverrideRepository::class);
        $repository->expects($this->once())
            ->method('findPublished')
            ->with(1, 'footer')
            ->willReturn(['html' => '<footer></footer>', 'css' => '', 'js' => '', 'seeded_from_skin' => 'basic']);

        $domain = $this->createMock(Domain::class);
        $domain->method('getThemeConfig')->willReturn(['frame_edit' => ['parts' => ['footer']]]);

        $context = $this->createMock(Context::class);
        $context->method('getDomainInfo')->willReturn($domain);

        $r = $this->renderer([], null, null, $repository);
        $overrides = $r->callResolveFrameOverrides($context);

        $this->assertArrayHasKey('footer', $overrides);
        $this->assertArrayNotHasKey('header', $overrides);
    }
}
