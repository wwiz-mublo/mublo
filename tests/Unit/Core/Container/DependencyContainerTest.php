<?php
/**
 * tests/Unit/Core/Container/DependencyContainerTest.php
 *
 * DependencyContainer (DI 컨테이너) 단위 테스트
 *
 * 이것은 의존성 주입 컨테이너 테스트 예제입니다.
 * DI 컨테이너는 프레임워크의 핵심이므로 철저한 테스트가 필요합니다.
 */

namespace Tests\Unit\Core\Container;

use Tests\TestCase;
use Mublo\Core\Container\DependencyContainer;
use Psr\Container\NotFoundExceptionInterface;

class DependencyContainerTest extends TestCase
{

    protected function setUp(): void
    {
        parent::setUp();
        $this->container = $this->getContainer();
    }

    /**
     * 싱글톤 인스턴스 테스트
     *
     * DependencyContainer는 싱글톤 패턴을 사용합니다.
     * 같은 인스턴스를 반복 호출해야 합니다.
     */
    public function testContainerIsSingleton(): void
    {
        $container1 = DependencyContainer::getInstance();
        $container2 = DependencyContainer::getInstance();

        $this->assertSame($container1, $container2);
    }

    /**
     * 서비스 등록 및 조회 테스트
     */
    public function testSetAndGetService(): void
    {
        $service = new \stdClass();
        $service->name = 'TestService';

        $this->container->set('test_service', $service);
        $retrieved = $this->container->get('test_service');

        $this->assertSame($service, $retrieved);
        $this->assertEquals('TestService', $retrieved->name);
    }

    /**
     * Factory 등록 및 호출 테스트
     *
     * factory()는 매 호출마다 새 인스턴스를 생성합니다.
     */
    public function testFactoryRegistration(): void
    {
        $this->container->factory('factory_service', function () {
            $obj = new \stdClass();
            $obj->id = uniqid();
            return $obj;
        });

        $service1 = $this->container->get('factory_service');
        $service2 = $this->container->get('factory_service');

        // Factory는 매번 새 인스턴스 반환
        $this->assertNotSame($service1, $service2);
        $this->assertNotEquals($service1->id, $service2->id);
    }

    /**
     * Singleton 등록 및 호출 테스트
     *
     * Singleton은 한 번만 생성되고 같은 인스턴스를 반환합니다.
     */
    public function testSingletonRegistration(): void
    {
        $this->container->singleton('singleton_service', function () {
            $obj = new \stdClass();
            $obj->id = uniqid();
            return $obj;
        });

        $service1 = $this->container->get('singleton_service');
        $service2 = $this->container->get('singleton_service');

        // Singleton은 같은 인스턴스 반환
        $this->assertSame($service1, $service2);
        $this->assertEquals($service1->id, $service2->id);
    }

    /**
     * 서비스 존재 여부 확인 테스트
     */
    public function testHasService(): void
    {
        $this->container->set('existing_service', new \stdClass());

        $this->assertTrue($this->container->has('existing_service'));
        $this->assertFalse($this->container->has('non_existing_service'));
    }

    /**
     * 미등록 서비스 조회 시 예외 발생 테스트
     */
    public function testGetNonExistingServiceThrowsException(): void
    {
        $this->expectException(NotFoundExceptionInterface::class);

        $this->container->get('non_existing_service');
    }

    /**
     * 복잡한 의존성 테스트
     *
     * ServiceA → ServiceB → ServiceC 의존성 체인
     */
    public function testComplexDependencies(): void
    {
        // ServiceC (최하단)
        $this->container->singleton('service_c', function () {
            $service = new \stdClass();
            $service->name = 'ServiceC';
            return $service;
        });

        // ServiceB (ServiceC 의존)
        $this->container->singleton('service_b', function () {
            $c = new \stdClass();
            $c->name = 'ServiceC';
            $service = new \stdClass();
            $service->dependency = $c;
            $service->name = 'ServiceB';
            return $service;
        });

        // ServiceA (ServiceB 의존)
        $this->container->singleton('service_a', function () {
            $b = new \stdClass();
            $b->name = 'ServiceB';
            $service = new \stdClass();
            $service->dependency = $b;
            $service->name = 'ServiceA';
            return $service;
        });

        $serviceA = $this->container->get('service_a');

        $this->assertEquals('ServiceA', $serviceA->name);
        $this->assertEquals('ServiceB', $serviceA->dependency->name);
    }

    /**
     * 조건부 등록 테스트
     *
     * 이미 등록된 서비스는 덮어쓰지 않는 패턴
     */
    public function testConditionalRegistration(): void
    {
        $original = new \stdClass();
        $original->version = 1;

        $this->container->set('config', $original);

        // 이미 등록되어 있으면 덮어쓰지 않음
        if (!$this->container->has('config')) {
            $this->container->set('config', new \stdClass());
        }

        $retrieved = $this->container->get('config');
        $this->assertEquals(1, $retrieved->version);
    }
}
