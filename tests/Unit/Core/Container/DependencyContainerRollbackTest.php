<?php

namespace Tests\Unit\Core\Container;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Mublo\Core\Container\DependencyContainer;
use Mublo\Core\Extension\ExtensionRegistrationScope;

/**
 * 확장 등록 scope 롤백 회귀 테스트 — 특히 '이중 등록' 케이스.
 *
 * 확장이 register()와 boot()에서 같은 서비스 id 를 두 번 등록한 뒤 boot 가 실패하면,
 * 롤백은 코어 원본 정의로 되돌려야 한다. 과거엔 두 번째 등록이 undo 를 중복 기록해
 * owner 가드가 어긋나면서 원본 복원 체인이 끊겨, 실패·롤백된 확장의 1차 정의가
 * 컨테이너에 잔류하고 코어 원본이 소실됐다.
 */
#[CoversClass(DependencyContainer::class)]
class DependencyContainerRollbackTest extends TestCase
{
    private DependencyContainer $container;

    protected function setUp(): void
    {
        parent::setUp();
        DependencyContainer::resetInstance();
        ExtensionRegistrationScope::reset();
        $this->container = DependencyContainer::getInstance();
    }

    protected function tearDown(): void
    {
        DependencyContainer::resetInstance();
        ExtensionRegistrationScope::reset();
        parent::tearDown();
    }

    #[Test]
    public function testDoubleRegistrationRollbackRestoresCoreDefinition(): void
    {
        // 코어 원본 (scope 밖에서 등록)
        $this->container->singleton('svc', fn() => 'CORE');

        // 확장 A: register()에서 override, boot()에서 다시 override
        ExtensionRegistrationScope::begin('ext_a');
        $this->container->singleton('svc', fn() => 'EXT_V1'); // register
        $this->container->singleton('svc', fn() => 'EXT_V2'); // boot
        // boot 실패 → 롤백
        ExtensionRegistrationScope::rollback('ext_a');

        // 코어 원본으로 정확히 복원되어야 한다 (과거엔 EXT_V1 이 잔류했음)
        $this->assertSame('CORE', $this->container->get('svc'));
    }

    #[Test]
    public function testSingleRegistrationRollbackStillRestoresCore(): void
    {
        // 이중 등록이 아닌 기존 경로도 여전히 정상 복원되는지(회귀 방지)
        $this->container->singleton('svc', fn() => 'CORE');

        ExtensionRegistrationScope::begin('ext_a');
        $this->container->singleton('svc', fn() => 'EXT_V1');
        ExtensionRegistrationScope::rollback('ext_a');

        $this->assertSame('CORE', $this->container->get('svc'));
    }

    #[Test]
    public function testDoubleRegistrationCommitKeepsLatest(): void
    {
        // 정상 커밋 시에는 마지막 등록(EXT_V2)이 유지되어야 한다
        $this->container->singleton('svc', fn() => 'CORE');

        ExtensionRegistrationScope::begin('ext_a');
        $this->container->singleton('svc', fn() => 'EXT_V1');
        $this->container->singleton('svc', fn() => 'EXT_V2');
        ExtensionRegistrationScope::commit('ext_a');

        $this->assertSame('EXT_V2', $this->container->get('svc'));
    }

    #[Test]
    public function testNewIdRegistrationIsRemovedOnRollback(): void
    {
        // 코어에 없던 새 id 를 확장이 등록했다가 롤백하면 완전히 사라져야 한다
        ExtensionRegistrationScope::begin('ext_a');
        $this->container->singleton('brand_new', fn() => 'X');
        $this->container->singleton('brand_new', fn() => 'Y'); // 이중 등록
        ExtensionRegistrationScope::rollback('ext_a');

        $this->assertFalse($this->container->has('brand_new'));
    }
}
