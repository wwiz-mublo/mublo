<?php

namespace Tests\Feature\Wiring;

use Mublo\Core\App\Dispatcher;
use Mublo\Core\Container\DependencyContainer;
use Mublo\Core\Context\Context;
use Mublo\Core\Extension\ExtensionManager;
use Mublo\Core\Http\Request;
use Mublo\Core\Provider\ServiceProvider;
use Mublo\Infrastructure\Database\Database;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * 컨트롤러 배선 스모크. DB 를 요구하지 않는다.
 *
 *  1) 모든 컨트롤러가 실제 생성 경로로 만들어지는가 — 컨테이너에 등록돼 있으면
 *     그 팩토리로, 아니면 Dispatcher 의 생성자 DI 로. 팩토리가 생성자와 어긋나면
 *     여기서 ArgumentCountError 가 난다.
 *
 *  2) 모든 액션의 파라미터를 Dispatcher 가 조립할 수 있는가 — 라우트 파라미터는
 *     문자열로 들어오므로, Request/Context 가 아닌 클래스 타입인데 기본값도
 *     nullable 도 아닌 파라미터는 어떤 요청으로도 채울 수 없다.
 */
class ControllerWiringSmokeTest extends TestCase
{
    /** 컨테이너는 정적 싱글턴이다. 다른 테스트로 새지 않게 매번 새로 만든다. */
    protected function setUp(): void
    {
        DependencyContainer::resetInstance();
    }

    protected function tearDown(): void
    {
        DependencyContainer::resetInstance();

        parent::tearDown();
    }

    public function testEveryControllerCanBeConstructedThroughItsRealPath(): void
    {
        $container = $this->bootContainer();
        $dispatcher = new ExposedDispatcher($container);

        $failures = [];
        foreach ($this->controllerClasses() as $class) {
            try {
                $container->has($class)
                    ? $container->get($class)
                    : $dispatcher->createControllerForTest($class);
            } catch (\Throwable $e) {
                $failures[] = sprintf('%s — %s: %s', $class, $e::class, $e->getMessage());
            }
        }

        $this->assertSame([], $failures, "생성할 수 없는 컨트롤러:\n" . implode("\n", $failures));
    }

    public function testEveryActionParameterCanBeResolvedByTheDispatcher(): void
    {
        $unresolvable = [];

        foreach ($this->controllerClasses() as $class) {
            $reflection = new ReflectionClass($class);

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->isStatic() || $method->isConstructor() || $method->getDeclaringClass()->getName() !== $class) {
                    continue;
                }

                foreach ($method->getParameters() as $param) {
                    if ($this->isResolvable($param)) {
                        continue;
                    }

                    $unresolvable[] = sprintf(
                        '%s::%s($%s) — %s 타입은 라우트 파라미터로 채울 수 없고 기본값도 없다',
                        $class,
                        $method->getName(),
                        $param->getName(),
                        (string) $param->getType()
                    );
                }
            }
        }

        $this->assertSame([], $unresolvable, "조립 불가 액션 파라미터:\n" . implode("\n", $unresolvable));
    }

    /**
     * Dispatcher::invokeAction() 의 규칙을 그대로 옮긴 판정.
     *
     * 라우트 파라미터는 URL 에서 온 문자열이므로 클래스 타입에 넣을 수 없다.
     * 따라서 Request/Context 가 아닌 클래스 타입은 기본값이나 nullable 이 있어야만
     * 해결된다 — 그렇지 않으면 어떤 요청이 와도 RuntimeException 이다.
     */
    private function isResolvable(ReflectionParameter $param): bool
    {
        $type = $param->getType();

        if ($param->isDefaultValueAvailable() || $param->allowsNull()) {
            return true;
        }

        if ($type === null) {
            // 타입이 없으면 라우트 파라미터 문자열을 그대로 받는다.
            return true;
        }

        if ($type instanceof ReflectionNamedType) {
            if ($type->isBuiltin()) {
                // 스칼라·배열은 라우트 파라미터나 'params' 로 채워진다.
                return true;
            }

            return $type->getName() === Request::class || $type->getName() === Context::class;
        }

        // union·intersection 은 Dispatcher 가 Request/Context 주입 대상으로 보지 않고
        // 이름 기반 분기로 흘려보낸다. 즉 라우트 파라미터 문자열이 들어오므로,
        // 스칼라를 받을 수 있는 조합이어야 한다(int|string 은 되고, Foo|Bar 는 안 된다).
        foreach ($type instanceof \ReflectionUnionType ? $type->getTypes() : [] as $member) {
            if ($member instanceof ReflectionNamedType && $member->isBuiltin()) {
                return true;
            }
        }

        return false;
    }

    private function bootContainer(): DependencyContainer
    {
        $container = DependencyContainer::getInstance();
        (new ServiceProvider())->register($container);

        // Application 이 요청마다 넣어 주는 런타임 객체.
        $container->set(Context::class, new Context(new Request('GET', '/')));
        $container->singleton(ExtensionManager::class, fn () => new ExtensionManager($container, null));

        // 검사 대상은 "꽂히는가" 이지 DB 연결이 아니다. 기본 Database 는
        // config/database.php 를 읽는데 그 파일은 설치기가 만들고 저장소에 없다.
        $container->set(Database::class, new Database($this->createMock(PDO::class)));

        foreach ($this->providerClasses() as $providerClass) {
            (new $providerClass())->register($container);
        }

        return $container;
    }

    /**
     * boot() 은 Context 와 DB 를 요구하므로 부르지 않는다. 컨트롤러 팩토리는
     * register() 에서 등록된다.
     *
     * @return list<class-string>
     */
    private function providerClasses(): array
    {
        $providers = [];

        foreach ([MUBLO_PACKAGE_PATH, MUBLO_PLUGIN_PATH] as $root) {
            $isPackage = $root === MUBLO_PACKAGE_PATH;

            foreach ((array) glob($root . '/*', GLOB_ONLYDIR) as $dir) {
                $name = basename((string) $dir);
                if ($isPackage && !in_array($name, self::TARGET_PACKAGES, true)) {
                    continue;
                }

                $namespace = $isPackage ? 'Mublo\\Packages\\' : 'Mublo\\Plugin\\';
                $class = $namespace . $name . '\\' . $name . 'Provider';
                if (class_exists($class)) {
                    $providers[] = $class;
                }
            }
        }

        return $providers;
    }

    /** @return list<class-string> */
    private function controllerClasses(): array
    {
        $classes = [];

        $roots = [MUBLO_SRC_PATH . '/Controller' => 'Mublo\\Controller\\'];
        foreach ([MUBLO_PACKAGE_PATH => 'Mublo\\Packages\\', MUBLO_PLUGIN_PATH => 'Mublo\\Plugin\\'] as $root => $namespace) {
            $isPackage = $root === MUBLO_PACKAGE_PATH;

            foreach ((array) glob($root . '/*', GLOB_ONLYDIR) as $dir) {
                $name = basename((string) $dir);
                if ($isPackage && !in_array($name, self::TARGET_PACKAGES, true)) {
                    continue;
                }
                $roots[$dir . '/Controller'] = $namespace . $name . '\\Controller\\';
            }
        }

        foreach ($roots as $dir => $namespace) {
            if (!is_dir($dir)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($dir) + 1));
                $class = $namespace . str_replace('/', '\\', substr($relative, 0, -4));

                if (!class_exists($class)) {
                    continue;
                }

                $reflection = new ReflectionClass($class);
                if ($reflection->isAbstract() || $reflection->isInterface()) {
                    continue;
                }

                $classes[] = $class;
            }
        }

        sort($classes);

        return $classes;
    }

    /** 검사 대상 패키지. 플러그인은 전부 본다. */
    private const TARGET_PACKAGES = ['Board', 'Shop'];
}

/** createController() 가 protected 라 실제 경로를 태우기 위해 연다. */
final class ExposedDispatcher extends Dispatcher
{
    public function createControllerForTest(string $controllerClass): object
    {
        return $this->createController($controllerClass);
    }
}
