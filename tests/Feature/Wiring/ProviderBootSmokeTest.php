<?php

namespace Tests\Feature\Wiring;

use Mublo\Core\Block\BlockRegistry;
use Mublo\Core\Container\DependencyContainer;
use Mublo\Core\Context\Context;
use Mublo\Core\Extension\ExtensionManager;
use Mublo\Core\Extension\ExtensionProviderInterface;
use Mublo\Core\Http\Request;
use Mublo\Core\Provider\ServiceProvider;
use Mublo\Infrastructure\Database\Database;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Provider 배선 스모크. DB 를 요구하지 않는다.
 *
 *  1) 확장이 규약대로 Provider 를 갖고 ExtensionProviderInterface 를 구현하는가
 *  2) 모든 Provider 의 register() 가 서로 섞여 있어도 예외 없이 끝나는가
 *  3) register() 로 등록된 서비스가 실제로 생성되는가
 *
 * 3번이 핵심이다. 팩토리는 해석될 때까지 실행되지 않으므로 register() 통과가
 * 생성 성공을 뜻하지 않는다.
 *
 * boot() 는 Context·DB·도메인 설정을 요구하므로 대상이 아니다. 그 층은
 * Integration 이 맡는다.
 */
class ProviderBootSmokeTest extends TestCase
{
    /** 검사 대상 패키지. 플러그인은 전부 본다. */
    private const TARGET_PACKAGES = ['Board', 'Shop'];

    protected function setUp(): void
    {
        DependencyContainer::resetInstance();
        // 앞 테스트가 남긴 등록이 있으면 다음 register() 가 선점으로 건너뛴다.
        BlockRegistry::reset();
    }

    protected function tearDown(): void
    {
        DependencyContainer::resetInstance();
        BlockRegistry::reset();

        parent::tearDown();
    }

    public function testEveryExtensionHasAProviderImplementingTheContract(): void
    {
        $violations = [];

        foreach ($this->targetExtensions() as [$name, $providerClass]) {
            if (!class_exists($providerClass)) {
                $violations[] = "{$name}: Provider 클래스가 없다 ({$providerClass})";
                continue;
            }

            if (!is_subclass_of($providerClass, ExtensionProviderInterface::class)) {
                $violations[] = "{$name}: ExtensionProviderInterface 를 구현하지 않는다";
            }
        }

        $this->assertSame([], $violations, "Provider 규약 위반:\n" . implode("\n", $violations));
    }

    public function testEveryProviderRegistersWithoutError(): void
    {
        $container = $this->bootCore();
        $failures = [];

        foreach ($this->targetExtensions() as [$name, $providerClass]) {
            if (!class_exists($providerClass)) {
                continue;
            }

            try {
                (new $providerClass())->register($container);
            } catch (\Throwable $e) {
                $failures[] = sprintf('%s — %s: %s', $name, $e::class, $e->getMessage());
            }
        }

        $this->assertSame([], $failures, "register() 실패:\n" . implode("\n", $failures));
    }

    /**
     * 등록된 서비스가 실제로 만들어지는지 확인한다.
     *
     * 컨테이너는 등록된 id 목록을 노출하지 않으므로, 확장이 소유한 클래스 중
     * 컨테이너가 알고 있는 것을 모아 해석한다. 팩토리가 지연 실행이라
     * register() 통과만으로는 알 수 없는 층이다.
     */
    public function testRegisteredServicesCanBeResolved(): void
    {
        $container = $this->bootCore();

        foreach ($this->targetExtensions() as [$name, $providerClass]) {
            if (class_exists($providerClass)) {
                (new $providerClass())->register($container);
            }
        }

        $failures = [];
        foreach ($this->targetExtensions() as [$name, $providerClass]) {
            foreach ($this->classesOf($name, $providerClass) as $class) {
                if (!$container->has($class)) {
                    continue; // 이 확장이 컨테이너에 맡기지 않은 클래스
                }

                try {
                    $container->get($class);
                } catch (\Throwable $e) {
                    $failures[] = sprintf('%s — %s: %s', $class, $e::class, $e->getMessage());
                }
            }
        }

        $this->assertSame([], $failures, "등록됐으나 생성할 수 없는 서비스:\n" . implode("\n", $failures));
    }

    private function bootCore(): DependencyContainer
    {
        $container = DependencyContainer::getInstance();
        (new ServiceProvider())->register($container);

        // Application 이 요청마다 넣어 주는 런타임 객체.
        $container->set(Context::class, new Context(new Request('GET', '/')));
        $container->singleton(ExtensionManager::class, fn () => new ExtensionManager($container, null));

        // 검사 대상은 "꽂히는가" 이지 DB 연결이 아니다. 기본 Database 는
        // config/database.php 를 읽는데 그 파일은 설치기가 만들고 저장소에 없다.
        $container->set(Database::class, new Database($this->createMock(PDO::class)));

        return $container;
    }

    /**
     * manifest.json 이 있는 디렉토리를 확장으로 본다.
     *
     * @return list<array{0: string, 1: class-string}>
     */
    private function targetExtensions(): array
    {
        $extensions = [];

        foreach ([MUBLO_PACKAGE_PATH => 'Mublo\\Packages\\', MUBLO_PLUGIN_PATH => 'Mublo\\Plugin\\'] as $root => $namespace) {
            $isPackage = $root === MUBLO_PACKAGE_PATH;

            foreach ((array) glob($root . '/*/manifest.json') as $manifest) {
                $name = basename(dirname((string) $manifest));
                if ($isPackage && !in_array($name, self::TARGET_PACKAGES, true)) {
                    continue;
                }

                $extensions[] = [$name, $namespace . $name . '\\' . $name . 'Provider'];
            }
        }

        sort($extensions);

        return $extensions;
    }

    /**
     * 확장 디렉토리의 클래스를 모은다.
     *
     * @return list<class-string>
     */
    private function classesOf(string $name, string $providerClass): array
    {
        $namespace = str_contains($providerClass, '\\Packages\\') ? 'Mublo\\Packages\\' : 'Mublo\\Plugin\\';
        $root = str_contains($providerClass, '\\Packages\\') ? MUBLO_PACKAGE_PATH : MUBLO_PLUGIN_PATH;
        $dir = $root . '/' . $name;

        if (!is_dir($dir)) {
            return [];
        }

        $classes = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $path = str_replace('\\', '/', $file->getPathname());
            $relative = substr($path, strlen(str_replace('\\', '/', $dir)) + 1);

            // 절대경로가 아니라 확장 기준 상대경로의 세그먼트로 판단한다. 테스트
            // 부트스트랩의 MUBLO_ROOT_PATH 가 'tests/..' 라 절대경로에는 항상
            // '/tests/' 가 들어간다.
            $segments = explode('/', $relative);
            if (array_intersect($segments, ['tests', 'views', 'sample', 'database', 'assets'])) {
                continue;
            }

            // class_exists() 는 오토로더로 파일을 실행한다. 클래스 파일이 아닌 것을
            // 집으면 그 파일이 그대로 돌아간다.
            if (!preg_match('/^[A-Z][A-Za-z0-9]*$/', $file->getBasename('.php'))) {
                continue;
            }

            $class = $namespace . $name . '\\' . str_replace('/', '\\', substr($relative, 0, -4));

            if (!class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);
            if ($reflection->isAbstract() || $reflection->isInterface() || $reflection->isEnum()) {
                continue;
            }

            $classes[] = $class;
        }

        sort($classes);

        return $classes;
    }
}
