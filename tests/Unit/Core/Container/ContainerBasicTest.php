<?php
/**
 * tests/Unit/Core/Container/ContainerBasicTest.php
 *
 * 매우 간단한 DI 컨테이너 테스트
 * 싱글톤 문제를 피하기 위해 간단한 테스트만 포함
 */

namespace Tests\Unit\Core\Container;

use Tests\TestCase;
use Mublo\Core\Container\DependencyContainer;

class ContainerBasicTest extends TestCase
{
    /**
     * 컨테이너가 생성되는지 확인
     */
    public function testContainerCanBeRetrieved(): void
    {
        $container = DependencyContainer::getInstance();
        $this->assertNotNull($container);
    }

    /**
     * 서비스 등록 및 조회
     */
    public function testCanRegisterAndRetrieveService(): void
    {
        $container = DependencyContainer::getInstance();
        $service = new \stdClass();
        $service->name = 'test';

        $container->set('test_service', $service);
        $retrieved = $container->get('test_service');

        $this->assertSame($service, $retrieved);
        $this->assertEquals('test', $retrieved->name);
    }

    /**
     * 서비스 존재 확인
     */
    public function testCanCheckIfServiceExists(): void
    {
        $container = DependencyContainer::getInstance();
        $container->set('existing_service', new \stdClass());

        $this->assertTrue($container->has('existing_service'));
        $this->assertFalse($container->has('non_existing_service'));
    }
}
