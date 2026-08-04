<?php

namespace Tests\Unit\Core\Container;

use Tests\TestCase;
use Mublo\Core\Container\DependencyContainer;
use Psr\Container\NotFoundExceptionInterface;

/**
 * ContainerCanResolveTest
 *
 * DependencyContainer의 canResolve, has, 등록 우선순위 테스트
 * - canResolve() vs has()
 * - factory 재등록 시 singleton 캐시 무효화
 * - singleton 재등록 시 factory 캐시 무효화
 * - 컨테이너 자기 자신 등록 확인
 */
class ContainerCanResolveTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->container = $this->getContainer();
    }

    // =========================================================================
    // has() 와 canResolve() 구분
    // =========================================================================

    public function testHasReturnsFalseForUnregisteredService(): void
    {
        $this->assertFalse($this->container->has('non_registered_service_xyz'));
    }

    public function testHasReturnsTrueAfterSet(): void
    {
        $this->container->set('my_service', new \stdClass());
        $this->assertTrue($this->container->has('my_service'));
    }

    public function testHasReturnsTrueAfterSingleton(): void
    {
        $this->container->singleton('my_singleton', fn() => new \stdClass());
        $this->assertTrue($this->container->has('my_singleton'));
    }

    public function testHasReturnsTrueAfterFactory(): void
    {
        $this->container->factory('my_factory', fn() => new \stdClass());
        $this->assertTrue($this->container->has('my_factory'));
    }

    public function testCanResolveReturnsTrueForRegisteredService(): void
    {
        $this->container->set('my_service', new \stdClass());
        $this->assertTrue($this->container->canResolve('my_service'));
    }

    public function testCanResolveReturnsFalseForUnknownClass(): void
    {
        $this->assertFalse($this->container->canResolve('NonExistent\\Class\\Name'));
    }

    // =========================================================================
    // 컨테이너 자기 자신 등록
    // =========================================================================

    public function testContainerRegistersItself(): void
    {
        // DependencyContainer는 getInstance() 시 자기 자신을 등록함
        $container = DependencyContainer::getInstance();
        $retrieved = $container->get(DependencyContainer::class);

        $this->assertSame($container, $retrieved);
    }

    // =========================================================================
    // 등록 우선순위 및 재등록 동작
    // =========================================================================

    public function testSetOverridesExistingInstance(): void
    {
        // Given
        $original = new \stdClass();
        $original->version = 1;
        $this->container->set('versioned_service', $original);

        // When: 덮어쓰기
        $replacement = new \stdClass();
        $replacement->version = 2;
        $this->container->set('versioned_service', $replacement);

        // Then: 새 인스턴스 반환
        $retrieved = $this->container->get('versioned_service');
        $this->assertEquals(2, $retrieved->version);
    }

    public function testFactoryRegistrationClearsSingletonCache(): void
    {
        // Given: singleton 등록 후 한 번 get (캐시 생성)
        $this->container->singleton('shared_service', function () {
            $obj = new \stdClass();
            $obj->type = 'singleton';
            return $obj;
        });

        $first = $this->container->get('shared_service');
        $this->assertEquals('singleton', $first->type);

        // When: factory로 재등록
        $this->container->factory('shared_service', function () {
            $obj = new \stdClass();
            $obj->type = 'factory';
            $obj->id = uniqid();
            return $obj;
        });

        // Then: factory가 매번 새 인스턴스 반환
        $a = $this->container->get('shared_service');
        $b = $this->container->get('shared_service');

        $this->assertEquals('factory', $a->type);
        $this->assertNotSame($a, $b);
    }

    public function testSingletonRegistrationClearsFactoryCache(): void
    {
        // Given: factory 등록
        $this->container->factory('shared_service_2', function () {
            $obj = new \stdClass();
            $obj->id = uniqid();
            return $obj;
        });

        $fa = $this->container->get('shared_service_2');
        $fb = $this->container->get('shared_service_2');
        $this->assertNotSame($fa, $fb);

        // When: singleton으로 재등록
        $this->container->singleton('shared_service_2', function () {
            $obj = new \stdClass();
            $obj->type = 'singleton_now';
            return $obj;
        });

        // Then: 같은 인스턴스 반환
        $s1 = $this->container->get('shared_service_2');
        $s2 = $this->container->get('shared_service_2');
        $this->assertSame($s1, $s2);
        $this->assertEquals('singleton_now', $s1->type);
    }

    public function testSingletonIsCreatedOnFirstAccessOnly(): void
    {
        // Given
        $callCount = 0;
        $this->container->singleton('lazy_service', function () use (&$callCount) {
            $callCount++;
            return new \stdClass();
        });

        // When: 여러 번 접근
        $this->container->get('lazy_service');
        $this->container->get('lazy_service');
        $this->container->get('lazy_service');

        // Then: 팩토리는 한 번만 호출
        $this->assertEquals(1, $callCount);
    }

    public function testFactoryIsCalledEveryTime(): void
    {
        // Given
        $callCount = 0;
        $this->container->factory('stateful_service', function () use (&$callCount) {
            $callCount++;
            return new \stdClass();
        });

        // When
        $this->container->get('stateful_service');
        $this->container->get('stateful_service');
        $this->container->get('stateful_service');

        // Then: 팩토리는 매번 호출
        $this->assertEquals(3, $callCount);
    }

    // =========================================================================
    // 컨테이너에서 다른 서비스 주입 패턴
    // =========================================================================

    public function testSingletonFactoryReceivesContainer(): void
    {
        // Given: 컨테이너가 팩토리 콜백에 전달됨
        $this->container->set('config_service', (object)['debug' => true]);

        $receivedContainer = null;
        $this->container->singleton('dependent_service', function ($container) use (&$receivedContainer) {
            $receivedContainer = $container;
            return new \stdClass();
        });

        // When
        $this->container->get('dependent_service');

        // Then: 컨테이너가 팩토리에 전달됨
        $this->assertSame($this->container, $receivedContainer);
    }

    public function testChainedDependenciesViaSingletons(): void
    {
        // Given: A → B → C 체인
        $this->container->singleton('chain_c', function () {
            return (object)['name' => 'C'];
        });
        $this->container->singleton('chain_b', function ($c) {
            $b = new \stdClass();
            $b->name = 'B';
            $b->dep = $c->get('chain_c');
            return $b;
        });
        $this->container->singleton('chain_a', function ($c) {
            $a = new \stdClass();
            $a->name = 'A';
            $a->dep = $c->get('chain_b');
            return $a;
        });

        // When
        $a = $this->container->get('chain_a');

        // Then
        $this->assertEquals('A', $a->name);
        $this->assertEquals('B', $a->dep->name);
        $this->assertEquals('C', $a->dep->dep->name);
    }

    // =========================================================================
    // NotFound 예외
    // =========================================================================

    public function testGetThrowsNotFoundForUnregisteredNonServiceClass(): void
    {
        $this->expectException(NotFoundExceptionInterface::class);
        $this->container->get('totally_unknown_service_abc');
    }

    public function testGetThrowsNotFoundForEmptyString(): void
    {
        $this->expectException(NotFoundExceptionInterface::class);
        $this->container->get('');
    }
}
