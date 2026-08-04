<?php

namespace Tests\Unit\Core\App {

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Mublo\Core\App\Router;
use Mublo\Core\Http\Request;
use Mublo\Core\Context\Context;
use Mublo\Core\Middleware\AdminMiddleware;

class RouterTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // 테스트 환경에서 상수가 정의되지 않았을 경우를 대비한 더미 값
        if (!defined('MUBLO_SRC_PATH')) {
            define('MUBLO_SRC_PATH', sys_get_temp_dir() . '/Mublo_test/src');
        }
        if (!defined('MUBLO_STORAGE_PATH')) {
            define('MUBLO_STORAGE_PATH', sys_get_temp_dir() . '/Mublo_test/storage');
        }
    }

    protected function setUp(): void
    {
        // 라우트 캐시 비활성화 (개발 모드 시뮬레이션)
        $_ENV['APP_DEBUG'] = 'true';
    }

    #[Test]
    public function testAutoResolveFrontController(): void
    {
        $router = new Router();
        
        // 명시적 라우트에 없는 경로 요청
        $request = $this->createConfiguredMock(Request::class, [
            'getMethod' => 'GET',
            'getPath' => '/board/list'
        ]);
        
        // Context 모의 객체 사용
        $context = $this->createMock(Context::class);
        $context->method('getDomain')->willReturn('test.local');
        $context->method('getDomainId')->willReturn(0);
        $context->method('isAdmin')->willReturn(false);

        $route = $router->dispatch($request, $context);

        $this->assertEquals('Mublo\Packages\Board\Controller\Front\BoardController', $route['controller']);
        $this->assertEquals('list', $route['method']);
        $this->assertEmpty($route['middleware']);
    }

    #[Test]
    public function testAutoResolveAdminController(): void
    {
        $router = new Router();
        
        $request = $this->createConfiguredMock(Request::class, [
            'getMethod' => 'GET',
            'getPath' => '/admin/member/edit/123'
        ]);
        
        // Context 모의 객체 사용
        $context = $this->createMock(Context::class);
        $context->method('getDomain')->willReturn('test.local');
        $context->method('getDomainId')->willReturn(0);
        $context->method('isAdmin')->willReturn(true);

        $route = $router->dispatch($request, $context);

        // Mublo\Controller\Admin\MemberController 가 되어야 함
        $this->assertEquals('Mublo\Controller\Admin\MemberController', $route['controller']);
        
        // 메서드는 edit
        $this->assertEquals('edit', $route['method']);
        
        // 파라미터는 ['123']
        $this->assertEquals(['123'], $route['params']);
        
        // AdminMiddleware가 포함되어야 함
        $this->assertContains(AdminMiddleware::class, $route['middleware']);
    }
}

}

// RouterTest가 의존하는 Context 클래스 스텁
namespace Mublo\Core\Context {
    if (!class_exists(Context::class)) {
        class Context {
            public function __construct(private ?\Mublo\Entity\Member\Member $member, private bool $isAdmin) {}
            public function getMember(): ?\Mublo\Entity\Member\Member { return $this->member; }
            public function isAdmin(): bool { return $this->isAdmin; }
            public function getDomain(): ?string { return 'test.com'; }
            public function getDomainId(): ?int { return 1; }
        }
    }
}
