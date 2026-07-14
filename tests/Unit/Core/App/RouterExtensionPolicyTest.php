<?php

namespace Tests\Unit\Core\App;

use FastRoute\DataGenerator\GroupCountBased as GroupCountBasedDataGenerator;
use FastRoute\Dispatcher;
use FastRoute\Dispatcher\GroupCountBased as GroupCountBasedDispatcher;
use FastRoute\RouteCollector;
use FastRoute\RouteParser\Std;
use Mublo\Core\App\PrefixedRouteCollector;
use Mublo\Core\App\Router;
use Mublo\Core\Container\DependencyContainer;
use Mublo\Core\Context\Context;
use Mublo\Core\Extension\ExtensionLoadDiagnostics;
use Mublo\Core\Provider\ServiceProvider;
use Mublo\Service\Extension\ExtensionService;
use PHPUnit\Framework\TestCase;

class RouterExtensionPolicyTest extends TestCase
{
    private string $previousErrorLog = '';
    private ?string $tempErrorLog = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousErrorLog = (string) ini_get('error_log');
        $this->tempErrorLog = tempnam(sys_get_temp_dir(), 'mublo-router-error-');
        ini_set('error_log', $this->tempErrorLog);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousErrorLog);
        if ($this->tempErrorLog !== null && is_file($this->tempErrorLog)) {
            unlink($this->tempErrorLog);
        }
        DependencyContainer::resetInstance();
        parent::tearDown();
    }

    public function testContainerRegisteredRouterReceivesContainer(): void
    {
        $container = DependencyContainer::getInstance();
        (new ServiceProvider())->register($container);

        $router = $container->get(Router::class);

        $property = new \ReflectionProperty($router, 'container');
        $property->setAccessible(true);

        $this->assertSame($container, $property->getValue($router));
    }

    public function testPluginLookupFailureFailsClosedWhenContainerExists(): void
    {
        $container = DependencyContainer::getInstance();
        $router = new Router($container);

        $context = $this->createMock(Context::class);
        $context->method('getDomainId')->willReturn(1);

        $method = new \ReflectionMethod($router, 'getEnabledPluginNames');
        $method->setAccessible(true);

        $this->assertSame([], $method->invoke($router, $context));
    }

    public function testPackageLookupFailureFailsClosedWhenContainerExists(): void
    {
        $container = DependencyContainer::getInstance();
        $router = new Router($container);

        $context = $this->createMock(Context::class);
        $context->method('getDomainId')->willReturn(1);

        $method = new \ReflectionMethod($router, 'getEnabledPackageNames');
        $method->setAccessible(true);

        $this->assertSame([], $method->invoke($router, $context));
    }

    public function testPluginRouteIsFilteredAfterProviderFailure(): void
    {
        $container = DependencyContainer::getInstance();
        $service = $this->createMock(ExtensionService::class);
        $service->method('getEnabledPlugins')->with(1)->willReturn(['Healthy', 'Broken']);
        $container->set(ExtensionService::class, $service);

        $diagnostics = new ExtensionLoadDiagnostics();
        $diagnostics->record('plugin', 'Broken', 'boot', new \RuntimeException('broken boot'));
        $container->set(ExtensionLoadDiagnostics::class, $diagnostics);

        $context = $this->createMock(Context::class);
        $context->method('getDomainId')->willReturn(1);
        $method = new \ReflectionMethod(new Router($container), 'getEnabledPluginNames');
        $method->setAccessible(true);

        $this->assertSame(['Healthy'], $method->invoke(new Router($container), $context));
    }

    public function testConflictingExtensionRoutesAreDiscardedAtomically(): void
    {
        [$router, $collector, $diagnostics] = $this->routeHarness();
        $registered = [];

        $collector->addRoute('GET', '/core', $this->handler('core'));
        $registered[] = ['method' => 'GET', 'route' => '/core', 'handler' => $this->handler('core')];

        $this->registerRoutes(
            $router,
            $collector,
            $registered,
            static function (PrefixedRouteCollector $routes): void {
                $routes->addRoute('GET', '/safe', self::handler('safe'));
                $routes->addRawRoute('GET', '/core', self::handler('conflict'));
            },
            'broken',
            'Broken'
        );

        $dispatcher = new GroupCountBasedDispatcher($collector->getData());
        $this->assertSame(Dispatcher::FOUND, $dispatcher->dispatch('GET', '/core')[0]);
        $this->assertSame(
            Dispatcher::NOT_FOUND,
            $dispatcher->dispatch('GET', '/broken/safe')[0],
            '충돌 전에 버퍼에 담긴 라우트도 실제 수집기에 남아서는 안 된다'
        );
        $this->assertCount(1, $registered);
        $this->assertTrue($diagnostics->hasFailures());
        $this->assertSame('routes', $diagnostics->all()[0]['stage']);
        $this->assertSame('Broken', $diagnostics->all()[0]['name']);
    }

    public function testSamePathWithDifferentMethodIsAllowed(): void
    {
        [$router, $collector, $diagnostics] = $this->routeHarness();
        $registered = [];

        $collector->addRoute('GET', '/shared', $this->handler('get'));
        $registered[] = ['method' => 'GET', 'route' => '/shared', 'handler' => $this->handler('get')];

        $this->registerRoutes(
            $router,
            $collector,
            $registered,
            static function (PrefixedRouteCollector $routes): void {
                $routes->addRawRoute('POST', '/shared', self::handler('post'));
            },
            'writer',
            'Writer'
        );

        $dispatcher = new GroupCountBasedDispatcher($collector->getData());
        $this->assertSame(Dispatcher::FOUND, $dispatcher->dispatch('GET', '/shared')[0]);
        $this->assertSame(Dispatcher::FOUND, $dispatcher->dispatch('POST', '/shared')[0]);
        $this->assertFalse($diagnostics->hasFailures());
        $this->assertCount(2, $registered);
    }

    public function testReservedCoreAdminNamespaceIsNotShadowed(): void
    {
        [$router, $collector, $diagnostics] = $this->routeHarness();
        $registered = [];

        $this->registerRoutes(
            $router,
            $collector,
            $registered,
            static function (PrefixedRouteCollector $routes): void {
                $routes->addRoute('GET', '/admin', self::handler('shadow'));
            },
            'guide',
            'Guide'
        );

        $dispatcher = new GroupCountBasedDispatcher($collector->getData());
        $this->assertSame(Dispatcher::NOT_FOUND, $dispatcher->dispatch('GET', '/admin/guide')[0]);
        $this->assertTrue($diagnostics->hasFailures());
        $this->assertStringContainsString('reserved Core admin namespace', $diagnostics->all()[0]['message']);
    }

    public function testThrowingExtensionCallbackLeavesNoPartialRoutes(): void
    {
        [$router, $collector, $diagnostics] = $this->routeHarness();
        $registered = [];

        $this->registerRoutes(
            $router,
            $collector,
            $registered,
            static function (PrefixedRouteCollector $routes): void {
                $routes->addRoute('GET', '/before-error', self::handler('partial'));
                throw new \RuntimeException('broken routes callback');
            },
            'broken',
            'Broken'
        );

        $dispatcher = new GroupCountBasedDispatcher($collector->getData());
        $this->assertSame(Dispatcher::NOT_FOUND, $dispatcher->dispatch('GET', '/broken/before-error')[0]);
        $this->assertTrue($diagnostics->hasFailures());
        $this->assertSame([], $registered);
    }

    /** @return array{0: Router, 1: RouteCollector, 2: ExtensionLoadDiagnostics} */
    private function routeHarness(): array
    {
        $container = DependencyContainer::getInstance();
        $diagnostics = new ExtensionLoadDiagnostics();
        $container->set(ExtensionLoadDiagnostics::class, $diagnostics);

        return [
            new Router($container),
            new RouteCollector(new Std(), new GroupCountBasedDataGenerator()),
            $diagnostics,
        ];
    }

    /**
     * @param array<int, array{method: string|string[], route: string, handler: mixed}> $registered
     */
    private function registerRoutes(
        Router $router,
        RouteCollector $collector,
        array &$registered,
        callable $callback,
        string $prefix,
        string $name
    ): void {
        $method = new \ReflectionMethod($router, 'registerExtensionRoutes');
        $method->setAccessible(true);
        $args = [$collector, &$registered, $callback, $prefix, 'plugin', $name];
        $method->invokeArgs($router, $args);
    }

    /** @return array{controller: string, method: string} */
    private static function handler(string $method): array
    {
        return ['controller' => self::class, 'method' => $method];
    }
}
