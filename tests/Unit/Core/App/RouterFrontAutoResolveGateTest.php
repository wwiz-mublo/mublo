<?php

namespace Tests\Unit\Core\App;

use PHPUnit\Framework\TestCase;
use Mublo\Core\App\Router;
use Mublo\Core\Context\Context;
use Mublo\Core\Http\Request;
use Mublo\Exception\HttpNotFoundException;

/**
 * RouterFrontAutoResolveGateTest
 *
 * dispatch() 레벨 게이팅 검증:
 * - Front 미매칭 경로는 autoResolve로 빠지지 않고 404 (자동 노출/우회 차단)
 * - 명시 Front 라우트는 정상 매칭
 * - Admin 미매칭 GET 경로는 조회 action allowlist만 autoResolve
 *
 * 배경: autoResolve는 라우트별 미들웨어를 부여하지 못하므로 Front까지 허용하면
 *       명시 라우트의 AuthMiddleware가 비정규 경로(대소문자 변형 등)로 우회된다.
 */
class RouterFrontAutoResolveGateTest extends TestCase
{
    private ?string $prevDebug = null;

    protected function setUp(): void
    {
        parent::setUp();
        // simpleDispatcher 강제 (라우트 캐시 파일 미생성)
        $this->prevDebug = $_ENV['APP_DEBUG'] ?? null;
        $_ENV['APP_DEBUG'] = 'true';
    }

    protected function tearDown(): void
    {
        if ($this->prevDebug === null) {
            unset($_ENV['APP_DEBUG']);
        } else {
            $_ENV['APP_DEBUG'] = $this->prevDebug;
        }
        parent::tearDown();
    }

    private function dispatch(string $method, string $path, bool $isAdmin): array
    {
        $router = new Router(null);

        $request = $this->createMock(Request::class);
        $request->method('getMethod')->willReturn($method);
        $request->method('getPath')->willReturn($path);

        $context = $this->createMock(Context::class);
        $context->method('isAdmin')->willReturn($isAdmin);
        $context->method('getDomain')->willReturn('default');
        $context->method('getDomainId')->willReturn(1);

        return $router->dispatch($request, $context);
    }

    public function testFrontCaseVariantOfAuthRouteIs404NotAutoResolved(): void
    {
        // /mypage/profile 은 AuthMiddleware가 붙은 명시 라우트.
        // 대소문자 변형은 예전엔 autoResolve로 미들웨어 없이 실행됐다 → 이제 404.
        $this->expectException(HttpNotFoundException::class);
        $this->expectExceptionMessage('Not Found');

        $this->dispatch('GET', '/Mypage/profile', isAdmin: false);
    }

    public function testFrontUnregisteredPathIs404(): void
    {
        $this->expectException(HttpNotFoundException::class);
        $this->expectExceptionMessage('Not Found');

        $this->dispatch('GET', '/search/some-internal-method', isAdmin: false);
    }

    public function testExplicitFrontRouteStillMatches(): void
    {
        $route = $this->dispatch('GET', '/search', isAdmin: false);

        $this->assertStringContainsString('SearchController', $route['controller']);
        $this->assertSame('index', $route['method']);
    }

    public function testExplicitAuthGatedRouteKeepsAuthMiddleware(): void
    {
        $route = $this->dispatch('GET', '/mypage/profile', isAdmin: false);

        $this->assertStringContainsString('MypageController', $route['controller']);
        $this->assertNotEmpty($route['middleware']);
        $this->assertStringContainsString('AuthMiddleware', $route['middleware'][0]);
    }

    public function testAdminUnregisteredPathStillAutoResolves(): void
    {
        // /admin/member 는 명시 라우트가 아니라 autoResolve로 처리됨 (AdminMiddleware 부여)
        $route = $this->dispatch('GET', '/admin/member', isAdmin: true);

        $this->assertStringContainsString('MemberController', $route['controller']);
        $this->assertStringContainsString('AdminMiddleware', $route['middleware'][0]);
    }

    public function testAdminStateChangingGetAliasIs404(): void
    {
        $this->expectException(HttpNotFoundException::class);
        $this->expectExceptionMessage('Not Found');

        $this->dispatch('GET', '/admin/extensions/update', isAdmin: true);
    }

    public function testAdminStateChangingPostStillAutoResolvesBehindGlobalCsrf(): void
    {
        $route = $this->dispatch('POST', '/admin/extensions/update', isAdmin: true);

        $this->assertStringContainsString('ExtensionsController', $route['controller']);
        $this->assertSame('update', $route['method']);
        $this->assertStringContainsString('AdminMiddleware', $route['middleware'][0]);
    }

    public function testAdminAllowlistedJsonReadStillAutoResolvesAsGet(): void
    {
        $route = $this->dispatch('GET', '/admin/block-editor/contexts', isAdmin: true);

        $this->assertStringContainsString('BlockEditorController', $route['controller']);
        $this->assertSame('contexts', $route['method']);
    }
}
