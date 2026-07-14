<?php

namespace Tests\Fixtures\ExtensionLifecycle;

final class Trace
{
    /** @var string[] */
    public static array $events = [];

    public static function reset(): void
    {
        self::$events = [];
    }
}

namespace Mublo\Packages\LifecycleParent;

use Mublo\Core\Container\DependencyContainer;
use Mublo\Core\Context\Context;
use Mublo\Core\Extension\ExtensionProviderInterface;
use Mublo\Core\Extension\PluginHostInterface;
use Tests\Fixtures\ExtensionLifecycle\Trace;

class LifecycleParentProvider implements ExtensionProviderInterface, PluginHostInterface
{
    public function discoverPlugins(): array
    {
        return [
            'LifecycleChild' => [
                'dir' => __DIR__,
                'providerClass' => \Mublo\Packages\LifecycleParent\Plugins\LifecycleChild\LifecycleChildProvider::class,
            ],
        ];
    }

    public function register(DependencyContainer $container): void
    {
        Trace::$events[] = 'parent.register';
    }

    public function boot(DependencyContainer $container, Context $context): void
    {
        Trace::$events[] = 'parent.boot';
    }
}

namespace Mublo\Packages\LifecycleParent\Plugins\LifecycleChild;

use Mublo\Core\Container\DependencyContainer;
use Mublo\Core\Context\Context;
use Mublo\Core\Extension\ExtensionProviderInterface;
use Tests\Fixtures\ExtensionLifecycle\Trace;

class LifecycleChildProvider implements ExtensionProviderInterface
{
    public function register(DependencyContainer $container): void
    {
        Trace::$events[] = 'child.register';
    }

    public function boot(DependencyContainer $container, Context $context): void
    {
        Trace::$events[] = 'child.boot';
    }
}

namespace Mublo\Packages\RegisterFailParent;

use Mublo\Core\Container\DependencyContainer;
use Mublo\Core\Context\Context;
use Mublo\Core\Extension\ExtensionProviderInterface;

class RegisterFailParentProvider implements ExtensionProviderInterface
{
    public function register(DependencyContainer $container): void
    {
        $container->set(RegisterResidue::class, new RegisterResidue());
        throw new \RuntimeException('parent register failed');
    }

    public function boot(DependencyContainer $container, Context $context): void
    {
    }
}

class RegisterResidue
{
}

namespace Mublo\Packages\BootFailParent;

use Mublo\Core\Container\DependencyContainer;
use Mublo\Core\Context\Context;
use Mublo\Core\Extension\ExtensionProviderInterface;
use Mublo\Core\Extension\PluginHostInterface;
use Tests\Fixtures\ExtensionLifecycle\Trace;

class BootFailParentProvider implements ExtensionProviderInterface, PluginHostInterface
{
    public function discoverPlugins(): array
    {
        return [
            'BootFailChild' => [
                'dir' => __DIR__,
                'providerClass' => \Mublo\Packages\BootFailParent\Plugins\BootFailChild\BootFailChildProvider::class,
            ],
        ];
    }

    public function register(DependencyContainer $container): void
    {
        Trace::$events[] = 'boot-fail-parent.register';
    }

    public function boot(DependencyContainer $container, Context $context): void
    {
        Trace::$events[] = 'boot-fail-parent.boot';
        throw new \RuntimeException('parent boot failed');
    }
}

namespace Mublo\Packages\BootFailParent\Plugins\BootFailChild;

use Mublo\Core\Container\DependencyContainer;
use Mublo\Core\Context\Context;
use Mublo\Core\Extension\ExtensionProviderInterface;
use Tests\Fixtures\ExtensionLifecycle\Trace;

class BootFailChildProvider implements ExtensionProviderInterface
{
    public function register(DependencyContainer $container): void
    {
        Trace::$events[] = 'boot-fail-child.register';
        $container->set(ChildResidue::class, new ChildResidue());
    }

    public function boot(DependencyContainer $container, Context $context): void
    {
        Trace::$events[] = 'boot-fail-child.boot';
    }
}

class ChildResidue
{
}

namespace Mublo\Packages\RollbackFail;

use Mublo\Core\Block\BlockRegistry;
use Mublo\Core\Container\DependencyContainer;
use Mublo\Core\Context\Context;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Core\Extension\ExtensionProviderInterface;
use Mublo\Core\Registry\ContractRegistry;

interface RollbackContract
{
}

class RollbackImplementation implements RollbackContract
{
}

class RegisteredService
{
}

class RollbackFailProvider implements ExtensionProviderInterface
{
    public function register(DependencyContainer $container): void
    {
        $container->singleton(RegisteredService::class, fn () => new RegisteredService());
    }

    public function boot(DependencyContainer $container, Context $context): void
    {
        $container->get(EventDispatcher::class)->addListener('rollback.event', static fn () => null);
        $contracts = $container->get(ContractRegistry::class);
        $contracts->bind(
            RollbackContract::class,
            static fn () => new RollbackImplementation()
        );
        $contracts->resolve(RollbackContract::class);
        BlockRegistry::registerContentType(
            'rollback-test',
            'package',
            'Rollback Test',
            'Mublo\\Packages\\RollbackFail\\MissingRenderer'
        );

        throw new \RuntimeException('rollback boot failed');
    }
}

namespace Tests\Unit\Core\Extension;

use Mublo\Core\Context\Context;
use Mublo\Core\Block\BlockRegistry;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Core\Extension\ExtensionLoadDiagnostics;
use Mublo\Core\Extension\ExtensionManager;
use Mublo\Core\Registry\ContractRegistry;
use Tests\Fixtures\ExtensionLifecycle\Trace;
use Tests\TestCase;

class ExtensionManagerLifecycleTest extends TestCase
{
    private ?string $previousDebug = null;

    protected function setUp(): void
    {
        parent::setUp();
        Trace::reset();
        $this->previousDebug = $_ENV['APP_DEBUG'] ?? null;
        $_ENV['APP_DEBUG'] = 'false';
        BlockRegistry::reset();
    }

    protected function tearDown(): void
    {
        if ($this->previousDebug === null) {
            unset($_ENV['APP_DEBUG']);
        } else {
            $_ENV['APP_DEBUG'] = $this->previousDebug;
        }
        BlockRegistry::reset();
        parent::tearDown();
    }

    public function testPackageRegistersBeforeNestedPluginAndAllRegisterBeforeBoot(): void
    {
        $manager = new ExtensionManager($this->getContainer());

        $manager->loadExtensions(
            $this->createMock(Context::class),
            ['LifecycleParent/LifecycleChild'],
            ['LifecycleParent']
        );

        $this->assertSame([
            'parent.register',
            'child.register',
            'parent.boot',
            'child.boot',
        ], Trace::$events);
        $this->assertInstanceOf(
            \Mublo\Packages\LifecycleParent\LifecycleParentProvider::class,
            $manager->getLoadedProvider('package', 'LifecycleParent')
        );
        $this->assertInstanceOf(
            \Mublo\Packages\LifecycleParent\Plugins\LifecycleChild\LifecycleChildProvider::class,
            $manager->getLoadedProvider('plugin', 'LifecycleParent/LifecycleChild')
        );
    }

    public function testParentRegisterFailureSkipsNestedPluginRegisterAndBoot(): void
    {
        $manager = new ExtensionManager($this->getContainer(), new ExtensionLoadDiagnostics());

        $manager->loadExtensions(
            $this->createMock(Context::class),
            ['RegisterFailParent/Child'],
            ['RegisterFailParent']
        );

        $this->assertSame([], Trace::$events);
        $this->assertSame(['load', 'dependency'], array_column($manager->getLoadErrors(), 'stage'));
        $this->assertFalse($this->getContainer()->has(
            \Mublo\Packages\RegisterFailParent\RegisterResidue::class
        ));
    }

    public function testParentBootFailureSkipsNestedPluginBoot(): void
    {
        $manager = new ExtensionManager($this->getContainer(), new ExtensionLoadDiagnostics());

        $manager->loadExtensions(
            $this->createMock(Context::class),
            ['BootFailParent/BootFailChild'],
            ['BootFailParent']
        );

        $this->assertSame([
            'boot-fail-parent.register',
            'boot-fail-child.register',
            'boot-fail-parent.boot',
        ], Trace::$events);
        $this->assertSame(['boot', 'dependency'], array_column($manager->getLoadErrors(), 'stage'));
        $this->assertFalse($this->getContainer()->has(
            \Mublo\Packages\BootFailParent\Plugins\BootFailChild\ChildResidue::class
        ));
        // boot 실패로 롤백된 확장은 loadedExtensions 에서도 제거돼야 한다
        // (isPackageLoaded 가 '로드됨'으로 오보하지 않도록 — R4/배치5b 회귀 고정).
        $this->assertFalse($manager->isPackageLoaded('BootFailParent'));
        $this->assertNull($manager->getLoadedProvider('package', 'BootFailParent'));
        $this->assertNull($manager->getLoadedProvider('plugin', 'BootFailParent/BootFailChild'));
    }

    public function testBootFailureRollsBackCoreManagedRegistrations(): void
    {
        $dispatcher = new EventDispatcher();
        $contracts = new ContractRegistry();
        $this->getContainer()->set(EventDispatcher::class, $dispatcher);
        $this->getContainer()->set(ContractRegistry::class, $contracts);

        $manager = new ExtensionManager($this->getContainer(), new ExtensionLoadDiagnostics());
        $manager->loadExtensions(
            $this->createMock(Context::class),
            [],
            ['RollbackFail']
        );

        $this->assertFalse($this->getContainer()->has(
            \Mublo\Packages\RollbackFail\RegisteredService::class
        ));
        $this->assertFalse($dispatcher->hasListeners('rollback.event'));
        $this->assertFalse($contracts->has(\Mublo\Packages\RollbackFail\RollbackContract::class));
        $this->assertFalse(BlockRegistry::hasContentType('rollback-test'));
    }
}
