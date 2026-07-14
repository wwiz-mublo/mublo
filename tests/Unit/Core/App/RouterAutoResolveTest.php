<?php

namespace Tests\Unit\Core\App;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Mublo\Core\App\Router;
use Mublo\Exception\HttpNotFoundException;

/**
 * RouterAutoResolveTest
 *
 * Router의 URL → Controller/Method 자동 매핑 규칙 테스트
 *
 * autoResolve()는 private이므로 Reflection을 통해 직접 접근합니다.
 * 실제로 존재하는 Controller만 테스트합니다.
 */
class RouterAutoResolveTest extends TestCase
{
    private Router $router;
    private \ReflectionMethod $autoResolve;
    private \ReflectionMethod $buildRoutePrefix;
    private \ReflectionMethod $normalizeExtensionName;

    protected function setUp(): void
    {
        parent::setUp();
        $this->router = new Router(null);

        $ref = new \ReflectionClass($this->router);

        $this->autoResolve = $ref->getMethod('autoResolve');
        $this->autoResolve->setAccessible(true);

        $this->buildRoutePrefix = $ref->getMethod('buildRoutePrefix');
        $this->buildRoutePrefix->setAccessible(true);

        $this->normalizeExtensionName = $ref->getMethod('normalizeExtensionName');
        $this->normalizeExtensionName->setAccessible(true);
    }

    private function resolve(string $path, bool $isAdmin = false, string $httpMethod = 'GET'): array
    {
        $context = $this->createMock(\Mublo\Core\Context\Context::class);
        $context->method('isAdmin')->willReturn($isAdmin);

        return $this->autoResolve->invoke($this->router, $path, $context, $httpMethod);
    }

    // ─── 루트 경로 ───

    public function testRootPathResolvesToIndexController(): void
    {
        $result = $this->resolve('/');

        $this->assertStringContainsString('IndexController', $result['controller']);
        $this->assertSame('index', $result['method']);
        $this->assertSame([], $result['params']);
    }

    // ─── Front 자동 매핑 ───

    public function testSingleSegmentResolvesToControllerIndex(): void
    {
        // SearchController@index 존재 확인
        $result = $this->resolve('/search');

        $this->assertStringContainsString('SearchController', $result['controller']);
        $this->assertSame('index', $result['method']);
        $this->assertSame([], $result['params']);
    }

    public function testTwoSegmentsResolvesToControllerMethod(): void
    {
        // MemberController@register → 실제 존재
        $result = $this->resolve('/member/register');

        $this->assertStringContainsString('MemberController', $result['controller']);
        $this->assertSame('register', $result['method']);
    }

    public function testThreeSegmentsYieldsParams(): void
    {
        // MemberController@register with params
        $result = $this->resolve('/member/register/extra');

        $this->assertStringContainsString('MemberController', $result['controller']);
        $this->assertSame(['extra'], $result['params']);
    }

    // ─── Front 미들웨어 없음 ───

    public function testFrontRouteHasNoMiddleware(): void
    {
        $result = $this->resolve('/search');

        $this->assertSame([], $result['middleware']);
    }

    // ─── Admin 자동 매핑 ───

    public function testAdminRootResolvesToDashboard(): void
    {
        $result = $this->resolve('/admin', isAdmin: true);

        $this->assertStringContainsString('DashboardController', $result['controller']);
        $this->assertSame('index', $result['method']);
    }

    public function testAdminMemberIndex(): void
    {
        $result = $this->resolve('/admin/member', isAdmin: true);

        $this->assertStringContainsString('MemberController', $result['controller']);
        $this->assertSame('index', $result['method']);
    }

    public function testAdminMemberEditWithParam(): void
    {
        $result = $this->resolve('/admin/member/edit/123', isAdmin: true);

        $this->assertStringContainsString('MemberController', $result['controller']);
        $this->assertSame('edit', $result['method']);
        $this->assertSame(['123'], $result['params']);
    }

    public function testAdminMultipleParams(): void
    {
        $result = $this->resolve('/admin/member/edit/notice/5', isAdmin: true);

        $this->assertSame(['notice', '5'], $result['params']);
    }

    #[DataProvider('adminStateChangingGetProvider')]
    public function testAdminStateChangingActionCannotAutoResolveAsSafeMethod(
        string $path,
        string $httpMethod = 'GET'
    ): void
    {
        $this->expectException(HttpNotFoundException::class);
        $this->expectExceptionMessage('Not Found');

        $this->resolve($path, isAdmin: true, httpMethod: $httpMethod);
    }

    public static function adminStateChangingGetProvider(): array
    {
        return [
            'extension config update' => ['/admin/extensions/update'],
            'extension config update via head' => ['/admin/extensions/update', 'HEAD'],
            'cache clear alias' => ['/admin/system/clear-cache'],
            'migration run alias' => ['/admin/system/run-migration'],
            'temporary file cleanup alias' => ['/admin/system/cleanup-temp'],
            'dashboard reset alias' => ['/admin/dashboard/reset-layout'],
        ];
    }

    public function testAdminStateChangingActionStillAutoResolvesAsPost(): void
    {
        $result = $this->resolve('/admin/extensions/update', isAdmin: true, httpMethod: 'POST');

        $this->assertSame('update', $result['method']);
        $this->assertStringContainsString('AdminMiddleware', $result['middleware'][0]);
    }

    public function testAdminOptionsCannotAutoResolveToController(): void
    {
        $this->expectException(HttpNotFoundException::class);

        $this->resolve('/admin/member', isAdmin: true, httpMethod: 'OPTIONS');
    }

    // ─── Admin 미들웨어 ───

    public function testAdminRouteHasAdminMiddleware(): void
    {
        $result = $this->resolve('/admin/member', isAdmin: true);

        $this->assertNotEmpty($result['middleware']);
        $this->assertStringContainsString('AdminMiddleware', $result['middleware'][0]);
    }

    // ─── 없는 경로는 예외 ───

    public function testNonExistentControllerThrows404(): void
    {
        $this->expectException(HttpNotFoundException::class);
        $this->expectExceptionMessage('Not Found');

        $this->resolve('/nonexistent-controller-xyz/action');
    }

    // ─── buildRoutePrefix ───

    #[DataProvider('routePrefixProvider')]
    public function testBuildRoutePrefix(string $name, string $expected): void
    {
        $prefix = $this->buildRoutePrefix->invoke($this->router, $name);
        $this->assertSame($expected, $prefix);
    }

    public static function routePrefixProvider(): array
    {
        return [
            'PascalCase 변환'    => ['MemberPoint', 'member-point'],
            'AutoForm 변환'      => ['AutoForm', 'auto-form'],
            'underscore 변환'    => ['auto_form', 'auto-form'],
            '이미 소문자'        => ['banner', 'banner'],
            'Shop 변환'          => ['Shop', 'shop'],
            '단일 대문자'        => ['FAQ', 'faq'],
        ];
    }

    // ─── normalizeExtensionName ───

    #[DataProvider('normalizeProvider')]
    public function testNormalizeExtensionName(string $input, string $expected): void
    {
        $result = $this->normalizeExtensionName->invoke($this->router, $input);
        $this->assertSame($expected, $result);
    }

    public static function normalizeProvider(): array
    {
        return [
            'PascalCase'   => ['AutoForm', 'autoform'],
            'kebab-case'   => ['auto-form', 'autoform'],
            'underscore'   => ['auto_form', 'autoform'],
            '이미 소문자'  => ['autoform', 'autoform'],
            'MemberPoint'  => ['MemberPoint', 'memberpoint'],
        ];
    }
}
