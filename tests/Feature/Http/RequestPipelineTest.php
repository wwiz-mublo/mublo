<?php

namespace Tests\Feature\Http;

use Mublo\Core\App\Router;
use Mublo\Core\Container\DependencyContainer;
use Mublo\Core\Context\Context;
use Mublo\Core\Http\Request;
use Mublo\Core\Provider\ServiceProvider;
use Mublo\Exception\HttpNotFoundException;
use PHPUnit\Framework\TestCase;

/**
 * URL 이 어느 컨트롤러로 가는지를 실제 라우터로 검증한다.
 *
 * Router 는 명시적 라우트 등록과 규약 기반 autoResolve 두 경로를 함께 쓴다.
 * 둘이 겹치면 어느 쪽이 이기는지가 URL 마다 달라지는데, 그 판정은 라우터를
 * 실제로 태워야만 확인된다.
 *
 * 여기서는 해석 결과(컨트롤러·메서드·파라미터)만 본다. 액션 실행은 DB 와
 * 세션을 요구하므로 Integration 이 맡는다.
 */
class RequestPipelineTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        DependencyContainer::resetInstance();

        $container = DependencyContainer::getInstance();
        (new ServiceProvider())->register($container);
        $container->set(Context::class, new Context(new Request('GET', '/')));

        $this->router = new Router($container);
    }

    protected function tearDown(): void
    {
        DependencyContainer::resetInstance();

        parent::tearDown();
    }

    /**
     * @return array{controller: string, method: string, params: array<string, mixed>}
     */
    private function resolve(string $httpMethod, string $path): array
    {
        $request = new Request($httpMethod, $path);
        $route = $this->router->dispatch($request, new Context($request));

        return [
            'controller' => $route['controller'] ?? '',
            'method' => $route['method'] ?? '',
            'params' => $route['params'] ?? [],
        ];
    }

    public function testRootGoesToTheFrontIndex(): void
    {
        $resolved = $this->resolve('GET', '/');

        $this->assertSame('Mublo\Controller\Front\IndexController', $resolved['controller']);
        $this->assertSame('index', $resolved['method']);
    }

    public function testAdminRootGoesToTheDashboard(): void
    {
        $resolved = $this->resolve('GET', '/admin');

        $this->assertSame('Mublo\Controller\Admin\DashboardController', $resolved['controller']);
        $this->assertSame('index', $resolved['method']);
    }

    public function testRouteParametersAreExtracted(): void
    {
        $resolved = $this->resolve('GET', '/p/company-intro');

        $this->assertSame('company-intro', $resolved['params']['code'] ?? null);
    }

    public function testTwoSegmentParametersAreExtractedInOrder(): void
    {
        $resolved = $this->resolve('GET', '/mypage/board/articles');

        $this->assertSame('board', $resolved['params']['provider'] ?? null);
        $this->assertSame('articles', $resolved['params']['section'] ?? null);
    }

    public function testMethodMattersForTheSamePath(): void
    {
        $get = $this->resolve('GET', '/member/register/form');
        $post = $this->resolve('POST', '/member/register/form');

        // 같은 경로라도 메서드마다 다른 액션이어야 한다. 폼 표시와 제출이 섞이면
        // 조회 요청이 가입을 처리한다.
        $this->assertNotSame(
            $get['controller'] . '::' . $get['method'],
            $post['controller'] . '::' . $post['method']
        );
    }

    public function testUnknownPathIsNotFound(): void
    {
        $this->expectException(HttpNotFoundException::class);

        $this->resolve('GET', '/no/such/path/at/all');
    }

    public function testExtensionRouteIsNotRegisteredWhenTheExtensionIsNotEnabled(): void
    {
        // Banner 의 라우트는 플러그인 이름을 접두로 갖는다(/banner/admin/list).
        // 확장을 활성화하지 않은 컨텍스트에서 이 경로가 잡히면, 라우트가 활성화
        // 여부와 무관하게 등록되고 있다는 뜻이다.
        $this->expectException(HttpNotFoundException::class);

        $this->resolve('GET', '/banner/admin/list');
    }

    public function testTrailingSlashResolvesToTheSameAction(): void
    {
        $withSlash = $this->resolve('GET', '/admin/');
        $withoutSlash = $this->resolve('GET', '/admin');

        $this->assertSame($withoutSlash, $withSlash);
    }
}
