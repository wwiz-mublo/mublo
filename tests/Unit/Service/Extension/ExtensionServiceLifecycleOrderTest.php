<?php

namespace Tests\Fixtures\ExtensionServiceLifecycle;

final class Trace
{
    /** @var string[] */
    public static array $events = [];

    public static function reset(): void
    {
        self::$events = [];
    }
}

namespace Mublo\Packages\ServiceLifecycleParent;

use Mublo\Core\Container\DependencyContainer;
use Mublo\Core\Context\Context;
use Mublo\Core\Extension\ExtensionProviderInterface;
use Mublo\Core\Extension\InstallableExtensionInterface;
use Mublo\Core\Extension\PluginHostInterface;
use Tests\Fixtures\ExtensionServiceLifecycle\Trace;

class ServiceLifecycleParentProvider implements ExtensionProviderInterface, InstallableExtensionInterface, PluginHostInterface
{
    public function discoverPlugins(): array
    {
        return [
            'ServiceLifecycleChild' => [
                'dir' => __DIR__,
                'providerClass' => \Mublo\Packages\ServiceLifecycleParent\Plugins\ServiceLifecycleChild\ServiceLifecycleChildProvider::class,
            ],
        ];
    }

    public function register(DependencyContainer $container): void
    {
        Trace::$events[] = 'parent.register';
    }

    public function boot(DependencyContainer $container, Context $context): void
    {
    }

    public function install(DependencyContainer $container, Context $context): void
    {
        Trace::$events[] = 'parent.install';
    }

    public function uninstall(DependencyContainer $container, Context $context): void
    {
        Trace::$events[] = 'parent.uninstall';
    }
}

namespace Mublo\Packages\ServiceLifecycleParent\Plugins\ServiceLifecycleChild;

use Mublo\Core\Container\DependencyContainer;
use Mublo\Core\Context\Context;
use Mublo\Core\Extension\ExtensionProviderInterface;
use Mublo\Core\Extension\InstallableExtensionInterface;
use Tests\Fixtures\ExtensionServiceLifecycle\Trace;

class ServiceLifecycleChildProvider implements ExtensionProviderInterface, InstallableExtensionInterface
{
    public function register(DependencyContainer $container): void
    {
        Trace::$events[] = 'child.register';
    }

    public function boot(DependencyContainer $container, Context $context): void
    {
    }

    public function install(DependencyContainer $container, Context $context): void
    {
        Trace::$events[] = 'child.install';
    }

    public function uninstall(DependencyContainer $container, Context $context): void
    {
        Trace::$events[] = 'child.uninstall';
    }
}

namespace Mublo\Packages\ServiceFailParent;

use Mublo\Core\Container\DependencyContainer;
use Mublo\Core\Context\Context;
use Mublo\Core\Extension\ExtensionProviderInterface;
use Mublo\Core\Extension\InstallableExtensionInterface;
use Tests\Fixtures\ExtensionServiceLifecycle\Trace;

class ServiceFailParentProvider implements ExtensionProviderInterface, InstallableExtensionInterface
{
    public function register(DependencyContainer $container): void
    {
        Trace::$events[] = 'fail-parent.register';
    }

    public function boot(DependencyContainer $container, Context $context): void
    {
    }

    public function install(DependencyContainer $container, Context $context): void
    {
        Trace::$events[] = 'fail-parent.install';
        throw new \RuntimeException('forced install failure');
    }

    public function uninstall(DependencyContainer $container, Context $context): void
    {
    }
}

namespace Tests\Unit\Service\Extension;

use Mublo\Core\Context\Context;
use Mublo\Infrastructure\Cache\DomainCache;
use Mublo\Infrastructure\Database\Database;
use Mublo\Repository\Domain\DomainRepository;
use Mublo\Service\Extension\ExtensionCompatibility;
use Mublo\Service\Extension\ExtensionService;
use ReflectionClass;
use Tests\Fixtures\ExtensionServiceLifecycle\Trace;
use Tests\TestCase;

class ExtensionServiceLifecycleOrderTest extends TestCase
{
    private ExtensionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Trace::reset();
        $this->service = new ExtensionService(
            $this->createMock(DomainRepository::class),
            $this->createMock(DomainCache::class),
            $this->createMock(Database::class),
            new ExtensionCompatibility()
        );
    }

    public function testActivationInstallsPackageBeforeNestedPlugin(): void
    {
        $result = $this->executeLifecycle(
            ['plugins' => [], 'packages' => [], 'installed' => ['plugins' => [], 'packages' => []]],
            [
                'plugins' => ['ServiceLifecycleParent/ServiceLifecycleChild'],
                'packages' => ['ServiceLifecycleParent'],
                'installed' => ['plugins' => [], 'packages' => []],
            ]
        );

        $this->assertSame([
            'parent.register',
            'parent.install',
            'child.register',
            'child.install',
        ], Trace::$events);
        $this->assertSame(['ServiceLifecycleParent'], $result['installed']['packages']);
        $this->assertSame(
            ['ServiceLifecycleParent/ServiceLifecycleChild'],
            $result['installed']['plugins']
        );
    }

    public function testDeactivationUninstallsNestedPluginBeforePackage(): void
    {
        $result = $this->executeLifecycle(
            [
                'plugins' => ['ServiceLifecycleParent/ServiceLifecycleChild'],
                'packages' => ['ServiceLifecycleParent'],
                'installed' => [
                    'plugins' => ['ServiceLifecycleParent/ServiceLifecycleChild'],
                    'packages' => ['ServiceLifecycleParent'],
                ],
            ],
            [
                'plugins' => [],
                'packages' => [],
                'installed' => [
                    'plugins' => ['ServiceLifecycleParent/ServiceLifecycleChild'],
                    'packages' => ['ServiceLifecycleParent'],
                ],
            ]
        );

        $this->assertSame([
            'child.register',
            'child.uninstall',
            'parent.register',
            'parent.uninstall',
        ], Trace::$events);
        $this->assertSame([], $result['installed']['plugins']);
        $this->assertSame([], $result['installed']['packages']);
    }

    public function testParentInstallFailureRemovesParentAndNestedPluginFromDomainConfig(): void
    {
        $result = $this->executeLifecycle(
            ['plugins' => [], 'packages' => [], 'installed' => ['plugins' => [], 'packages' => []]],
            [
                'plugins' => ['ServiceFailParent/Child'],
                'packages' => ['ServiceFailParent'],
                'installed' => ['plugins' => [], 'packages' => []],
            ]
        );

        $this->assertSame(['fail-parent.register', 'fail-parent.install'], Trace::$events);
        $this->assertSame([], $result['packages']);
        $this->assertSame([], $result['plugins']);
        $this->assertSame([], $result['installed']['packages']);
        $this->assertSame([], $result['installed']['plugins']);
    }

    /** @return array<string, mixed> */
    private function executeLifecycle(array $oldConfig, array $newConfig): array
    {
        $method = (new ReflectionClass($this->service))->getMethod('executeLifecycle');
        $method->setAccessible(true);

        return $method->invoke(
            $this->service,
            1,
            $oldConfig,
            $newConfig,
            $this->getContainer(),
            $this->createMock(Context::class)
        );
    }
}
