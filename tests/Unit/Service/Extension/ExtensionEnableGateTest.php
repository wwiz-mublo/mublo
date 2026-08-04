<?php

namespace Tests\Unit\Service\Extension;

use Mublo\Entity\Domain\Domain;
use Mublo\Infrastructure\Cache\DomainCache;
use Mublo\Infrastructure\Database\Database;
use Mublo\Repository\Domain\DomainRepository;
use Mublo\Core\App\Application;
use Mublo\Service\Extension\ExtensionCompatibility;
use Mublo\Service\Extension\ExtensionService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * 활성화 게이트
 *
 * 확장을 켜는 순간이 requires 를 강제할 수 있는 유일한 지점이다.
 * composer 해결자가 없으므로 여기서 막지 못하면 사용자는 백지 화면을 본다.
 */
class ExtensionEnableGateTest extends TestCase
{
    private string $pluginPath;
    private string $packagePath;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/mublo-gate-' . bin2hex(random_bytes(6));
        $this->pluginPath = $base . '/plugins';
        $this->packagePath = $base . '/packages';
        mkdir($this->pluginPath, 0777, true);
        mkdir($this->packagePath, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach ([$this->pluginPath, $this->packagePath] as $dir) {
            foreach (glob($dir . '/*') as $ext) {
                @unlink($ext . '/manifest.json');
                @unlink($ext . '/routes.php');
                @rmdir($ext);
            }
            @rmdir($dir);
        }
        @rmdir(dirname($this->pluginPath));
    }

    public function testCompatibleExtensionIsAllowed(): void
    {
        $this->makePlugin('Banner', ['core' => '>=1.0.0']);

        $result = $this->validate(['plugins' => ['Banner']]);

        $this->assertTrue($result['valid'], $result['message']);
    }

    public function testExtensionRequiringNewerCoreIsBlocked(): void
    {
        $this->makePlugin('FromTheFuture', ['core' => '>=99.0.0']);

        $result = $this->validate(['plugins' => ['FromTheFuture']]);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('FromTheFuture', $result['message']);
        $this->assertStringContainsString('코어 >=99.0.0', $result['message']);
        $this->assertStringContainsString(Application::VERSION, $result['message']);
    }

    public function testExtensionRequiringMissingPackageIsBlocked(): void
    {
        $this->makePlugin('ShopAddon', ['core' => '>=1.0.0', 'package:Shop' => '>=1.0.0']);

        $result = $this->validate(['plugins' => ['ShopAddon']]);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString("패키지 'Shop'", $result['message']);
    }

    /**
     * 같은 저장 요청으로 함께 켜지는 패키지는 "설치된 것"으로 본다.
     */
    public function testDependencyEnabledInSameRequestSatisfiesRequirement(): void
    {
        $this->makePlugin('ShopAddon', ['core' => '>=1.0.0', 'package:Shop' => '>=1.0.0']);
        $this->makePackage('Shop', ['core' => '>=1.0.0'], '1.2.0');

        $result = $this->validate(['plugins' => ['ShopAddon'], 'packages' => ['Shop']]);

        $this->assertTrue($result['valid'], $result['message']);
    }

    public function testDependencyVersionTooOldIsBlocked(): void
    {
        $this->makePlugin('ShopAddon', ['core' => '>=1.0.0', 'package:Shop' => '>=2.0.0']);
        $this->makePackage('Shop', ['core' => '>=1.0.0'], '1.0.0');

        $result = $this->validate(['plugins' => ['ShopAddon'], 'packages' => ['Shop']]);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString("패키지 'Shop' >=2.0.0", $result['message']);
    }

    public function testMissingManifestIsStillBlockedFirst(): void
    {
        $result = $this->validate(['plugins' => ['NotInstalled']]);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('manifest.json', $result['message']);
    }

    public function testExtensionWithoutRequiresIsAllowed(): void
    {
        $this->makePlugin('Bare', null);

        $this->assertTrue($this->validate(['plugins' => ['Bare']])['valid']);
    }

    public function testRouteConflictIsBlockedBeforeExtensionConfigIsSaved(): void
    {
        $this->makePlugin('GlobalPage', ['core' => '>=1.0.0']);
        $this->makePackage('GlobalShop', ['core' => '>=1.0.0']);
        $this->writeRawRoute($this->pluginPath . '/GlobalPage', '/shared-entry');
        $this->writeRawRoute($this->packagePath . '/GlobalShop', '/shared-entry');

        $result = $this->validate([
            'plugins' => ['GlobalPage'],
            'packages' => ['GlobalShop'],
        ]);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('GlobalShop', $result['message']);
        $this->assertStringContainsString('/shared-entry', $result['message']);
    }

    public function testSameRawPathWithDifferentHttpMethodsIsAllowed(): void
    {
        $this->makePlugin('Reader', ['core' => '>=1.0.0']);
        $this->makePackage('Writer', ['core' => '>=1.0.0']);
        $this->writeRawRoute($this->pluginPath . '/Reader', '/shared-entry', 'GET');
        $this->writeRawRoute($this->packagePath . '/Writer', '/shared-entry', 'POST');

        $result = $this->validate([
            'plugins' => ['Reader'],
            'packages' => ['Writer'],
        ]);

        $this->assertTrue($result['valid'], $result['message']);
    }

    public function testRouteValidationUsesMandatoryExtensionsRestoredIntoFinalConfig(): void
    {
        $this->makePlugin('MandatoryPage', ['core' => '>=1.0.0'], '1.0.0', ['mandatory' => true]);
        $this->makePackage('NewShop', ['core' => '>=1.0.0']);
        $this->writeRawRoute($this->pluginPath . '/MandatoryPage', '/shared-entry');
        $this->writeRawRoute($this->packagePath . '/NewShop', '/shared-entry');

        $repository = $this->createMock(DomainRepository::class);
        $repository->method('find')->with(1)->willReturn(new Domain(
            1,
            'route-gate.test',
            '',
            null,
            'active',
            1073741824,
            0,
            [],
            [],
            [],
            [],
            [
                'plugins' => ['MandatoryPage'],
                'packages' => [],
                'installed' => ['plugins' => ['MandatoryPage'], 'packages' => []],
            ]
        ));
        $repository->expects($this->never())->method('updateExtensionConfig');

        $service = new ExtensionService(
            $repository,
            $this->createMock(DomainCache::class),
            $this->createMock(Database::class),
            new ExtensionCompatibility()
        );
        $reflection = new ReflectionClass($service);
        foreach (['pluginPath' => $this->pluginPath, 'packagePath' => $this->packagePath] as $property => $value) {
            $p = $reflection->getProperty($property);
            $p->setAccessible(true);
            $p->setValue($service, $value);
        }

        // disabled 체크박스인 mandatory 플러그인은 요청에서 빠지고 서비스가 다시 주입한다.
        $result = $service->saveExtensionConfig(1, [
            'plugins' => [],
            'packages' => ['NewShop'],
        ]);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('NewShop', $result->getMessage());
        $this->assertStringContainsString('/shared-entry', $result->getMessage());
    }

    // ========================================
    // Helpers
    // ========================================

    /**
     * @param array<string, string>|null $requires
     */
    private function makePlugin(
        string $name,
        ?array $requires,
        string $version = '1.0.0',
        array $extra = []
    ): void
    {
        $this->writeManifest($this->pluginPath, $name, $requires, $version, $extra);
    }

    /**
     * @param array<string, string>|null $requires
     */
    private function makePackage(
        string $name,
        ?array $requires,
        string $version = '1.0.0',
        array $extra = []
    ): void
    {
        $this->writeManifest($this->packagePath, $name, $requires, $version, $extra);
    }

    /**
     * @param array<string, string>|null $requires
     */
    private function writeManifest(
        string $base,
        string $name,
        ?array $requires,
        string $version,
        array $extra = []
    ): void
    {
        mkdir($base . '/' . $name, 0777, true);

        $manifest = array_merge(['label' => $name, 'version' => $version], $extra);
        if ($requires !== null) {
            $manifest['requires'] = $requires;
        }

        file_put_contents($base . '/' . $name . '/manifest.json', json_encode($manifest));
    }

    private function writeRawRoute(string $directory, string $path, string $method = 'GET'): void
    {
        $source = <<<'PHP'
<?php
return static function (\Mublo\Core\App\PrefixedRouteCollector $routes): void {
    $routes->addRawRoute('__METHOD__', '__PATH__', [
        'controller' => 'TestController',
        'method' => 'index',
    ]);
};
PHP;

        file_put_contents(
            $directory . '/routes.php',
            str_replace(['__METHOD__', '__PATH__'], [$method, $path], $source)
        );
    }

    /**
     * @param array<string, mixed> $config
     * @return array{valid: bool, message: string}
     */
    private function validate(array $config): array
    {
        $reflection = new ReflectionClass(ExtensionService::class);
        $service = $reflection->newInstanceWithoutConstructor();

        foreach ([
            'compatibility' => new ExtensionCompatibility(),
            'pluginPath' => $this->pluginPath,
            'packagePath' => $this->packagePath,
        ] as $property => $value) {
            $p = $reflection->getProperty($property);
            $p->setAccessible(true);
            $p->setValue($service, $value);
        }

        $method = $reflection->getMethod('validateExtensionConfig');
        $method->setAccessible(true);

        return $method->invoke($service, $config);
    }
}
