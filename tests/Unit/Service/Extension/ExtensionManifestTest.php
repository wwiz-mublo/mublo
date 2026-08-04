<?php

namespace Tests\Unit\Service\Extension;

use Mublo\Infrastructure\Cache\DomainCache;
use Mublo\Infrastructure\Database\Database;
use Mublo\Repository\Domain\DomainRepository;
use Mublo\Service\Extension\ExtensionService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * manifest.json 스캔 계약
 *
 * 확장은 커뮤니티에서 zip/git 으로 배포된다. composer 해결자가 없으므로
 * 식별자 규칙과 정합성을 코어가 직접 지켜야 한다.
 */
class ExtensionManifestTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir() . '/mublo-ext-' . bin2hex(random_bytes(6));
        mkdir($this->basePath, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->basePath . '/*') as $dir) {
            @unlink($dir . '/manifest.json');
            @rmdir($dir);
        }
        @rmdir($this->basePath);
    }

    public function testVendorProducesNamespacedId(): void
    {
        $this->makeExtension('Banner', ['vendor' => 'mublo']);

        $manifest = $this->scan()['Banner'];

        $this->assertSame('mublo', $manifest['vendor']);
        $this->assertSame('mublo/Banner', $manifest['id']);
    }

    public function testMissingVendorFallsBackToBareName(): void
    {
        $this->makeExtension('Banner', []);

        $manifest = $this->scan()['Banner'];

        $this->assertSame('', $manifest['vendor']);
        $this->assertSame('Banner', $manifest['id'], '익명 배포는 이름만 갖는다');
    }

    public function testVendorIsNormalizedToLowercase(): void
    {
        $this->makeExtension('Banner', ['vendor' => '  Mublo  ']);

        $this->assertSame('mublo/Banner', $this->scan()['Banner']['id']);
    }

    /**
     * id 가 "vendor/name" 형태이므로 vendor 에 슬래시가 섞이면 식별자가 깨진다.
     */
    public function testMalformedVendorIsRejected(): void
    {
        foreach (['evil/../..', 'has space', 'sym*bol', '-leading', str_repeat('x', 40)] as $bad) {
            $this->makeExtension('Banner', ['vendor' => $bad]);

            $manifest = $this->scan()['Banner'];
            $this->assertSame('', $manifest['vendor'], "거부되어야 함: {$bad}");
            $this->assertSame('Banner', $manifest['id']);
        }
    }

    /**
     * 로딩은 디렉토리명으로 Provider 클래스를 조립한다. manifest 의 name 이
     * 디렉토리명을 덮어쓰면 조회만 어긋나 조용히 깨진다.
     */
    public function testDirectoryNameWinsOverManifestName(): void
    {
        $this->makeExtension('Banner', ['name' => 'SomethingElse']);

        $manifest = $this->scan()['Banner'];

        $this->assertSame('Banner', $manifest['name']);
    }

    /**
     * 깨진 manifest 하나가 전체 확장 목록을 죽이면 안 된다.
     * (커뮤니티 zip 배포에서는 반드시 일어난다)
     */
    public function testInvalidJsonSkipsOnlyThatExtension(): void
    {
        mkdir($this->basePath . '/Broken');
        file_put_contents($this->basePath . '/Broken/manifest.json', '{ not json');
        $this->makeExtension('Healthy', ['vendor' => 'mublo']);

        $manifests = $this->scan();

        $this->assertArrayNotHasKey('Broken', $manifests);
        $this->assertArrayHasKey('Healthy', $manifests, '멀쩡한 확장은 살아남아야 한다');
    }

    public function testScalarManifestIsSkipped(): void
    {
        mkdir($this->basePath . '/Scalar');
        file_put_contents($this->basePath . '/Scalar/manifest.json', '"just a string"');

        $this->assertArrayNotHasKey('Scalar', $this->scan());
    }

    /**
     * 로더는 디렉토리명으로 Provider FQCN 을 조립하므로, 규칙을 어기는 이름은
     * 목록에 노출하면 안 된다 (활성화 후 로드만 조용히 깨진다).
     * 규칙은 설치기(zip 업로드)와 동일한 NestedPlugin::isValidName 을 공유한다.
     */
    public function testInvalidDirectoryNameIsSkipped(): void
    {
        $this->makeExtension('my-plugin', []);
        $this->makeExtension('Healthy', []);

        $manifests = $this->scan();

        $this->assertArrayNotHasKey('my-plugin', $manifests, '하이픈 이름은 FQCN 조립이 불가하다');
        $this->assertArrayHasKey('Healthy', $manifests);
    }

    public function testMissingExtensionPathReturnsEmptyList(): void
    {
        $service = (new ReflectionClass(ExtensionService::class))->newInstanceWithoutConstructor();
        $method = (new ReflectionClass(ExtensionService::class))->getMethod('scanManifests');
        $method->setAccessible(true);

        $this->assertSame([], $method->invoke($service, $this->basePath . '/no-such-dir', 'plugin'));
    }

    public function testDefaultsAreNormalized(): void
    {
        $this->makeExtension('Bare', []);

        $manifest = $this->scan()['Bare'];

        $this->assertFalse($manifest['default']);
        $this->assertFalse($manifest['mandatory']);
        $this->assertSame([], $manifest['requires']);
        $this->assertSame('plugin', $manifest['type']);
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function makeExtension(string $name, array $manifest): void
    {
        $dir = $this->basePath . '/' . $name;
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($dir . '/manifest.json', json_encode($manifest));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function scan(): array
    {
        $service = (new ReflectionClass(ExtensionService::class))->newInstanceWithoutConstructor();

        $method = (new ReflectionClass(ExtensionService::class))->getMethod('scanManifests');
        $method->setAccessible(true);

        return $method->invoke($service, $this->basePath, 'plugin');
    }
}
