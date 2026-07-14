<?php
/**
 * tests/Feature/Http/RoutingTest.php
 *
 * 라우팅 기능 테스트
 *
 * Note: 현재 Router는 내부적으로 라우트를 관리하며
 * dispatch() 메서드를 통해 요청을 처리합니다.
 * 라우트 등록은 registerCoreRoutes(), Plugin/Package routes.php에서 이루어집니다.
 */

namespace Tests\Feature\Http;

use Tests\TestCase;

class RoutingTest extends TestCase
{
    /**
     * Router 클래스 존재 확인
     */
    public function testRouterClassExists(): void
    {
        $this->assertTrue(class_exists(\Mublo\Core\App\Router::class));
    }

    /**
     * Dispatcher 클래스 존재 확인
     */
    public function testDispatcherClassExists(): void
    {
        $this->assertTrue(class_exists(\Mublo\Core\App\Dispatcher::class));
    }

    /**
     * Application 클래스 존재 확인
     */
    public function testApplicationClassExists(): void
    {
        $this->assertTrue(class_exists(\Mublo\Core\App\Application::class));
    }
}
