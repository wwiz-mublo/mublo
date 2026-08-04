<?php

namespace Tests\Unit\Core\App;

use PHPUnit\Framework\TestCase;
use Mublo\Core\App\Router;
use Mublo\Core\Http\Request;
use Mublo\Core\Context\Context;
use Mublo\Entity\Domain\Domain;

/**
 * RouterCachePurgeTest
 *
 * 활성 확장/routes.php 시그니처가 바뀌면 새 시그니처 캐시가 생성되는데,
 * 이전 시그니처 파일은 더는 참조되지 않아 영영 안 지워진다(디스크·inode 누적).
 * purgeSupersededCacheFiles 가 같은 도메인의 '유지 대상 외' 형제만 정리함을 고정한다.
 */
class RouterCachePurgeTest extends TestCase
{
    private string $cacheDir;

    public static function setUpBeforeClass(): void
    {
        if (!defined('MUBLO_SRC_PATH')) {
            define('MUBLO_SRC_PATH', sys_get_temp_dir() . '/Mublo_test/src');
        }
        if (!defined('MUBLO_STORAGE_PATH')) {
            define('MUBLO_STORAGE_PATH', sys_get_temp_dir() . '/Mublo_test/storage');
        }
    }

    protected function setUp(): void
    {
        $this->cacheDir = MUBLO_STORAGE_PATH . '/cache/routes';
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
        $this->cleanupTestFiles();
    }

    protected function tearDown(): void
    {
        $this->cleanupTestFiles();
    }

    private function cleanupTestFiles(): void
    {
        foreach (glob($this->cacheDir . '/purge-test.local.*.cache.php') ?: [] as $f) {
            @unlink($f);
        }
        foreach (glob($this->cacheDir . '/other-domain.local.*.cache.php') ?: [] as $f) {
            @unlink($f);
        }
        foreach (glob($this->cacheDir . '/purge-test.local.au.*.cache.php') ?: [] as $f) {
            @unlink($f);
        }
    }

    private function touch(string $name): string
    {
        $path = $this->cacheDir . '/' . $name;
        file_put_contents($path, '<?php return [];');
        return $path;
    }

    private function invokePurge(Router $router, string $domain, string $keepFile): void
    {
        $ref = new \ReflectionClass($router);

        $domainProp = $ref->getProperty('currentDomain');
        $domainProp->setAccessible(true);
        $domainProp->setValue($router, $domain);

        $method = $ref->getMethod('purgeSupersededCacheFiles');
        $method->setAccessible(true);
        $method->invoke($router, $keepFile);
    }

    public function testPurgeRemovesOtherSignaturesButKeepsCurrent(): void
    {
        $keep = $this->touch('purge-test.local.a1b2c3d4e5f6.cache.php');
        $stale = $this->touch('purge-test.local.f6e5d4c3b2a1.cache.php');

        $this->invokePurge(new Router(), 'purge-test.local', $keep);

        $this->assertFileExists($keep, '현재 시그니처 파일은 유지되어야 한다');
        $this->assertFileDoesNotExist($stale, '구 시그니처 파일은 제거되어야 한다');
    }

    public function testPurgeDoesNotTouchOtherDomains(): void
    {
        $keep = $this->touch('purge-test.local.a1b2c3d4e5f6.cache.php');
        $otherDomain = $this->touch('other-domain.local.abcabcabcabc.cache.php');

        $this->invokePurge(new Router(), 'purge-test.local', $keep);

        $this->assertFileExists($keep);
        $this->assertFileExists($otherDomain, '다른 도메인의 캐시는 건드리지 않아야 한다');
    }

    public function testCacheKeyUsesCanonicalResolvedDomainNotRawHost(): void
    {
        // raw Host(demo.example.local)가 서브도메인 폴백으로 정식 도메인(example.local)에
        // 해석되면, 캐시 파일 키는 raw Host 가 아니라 정식 도메인이어야 한다. 아니면 임의
        // 서브도메인마다 별도 캐시 파일이 생겨 디스크·inode 고갈 DoS 가 열린다.
        $_ENV['APP_DEBUG'] = 'true'; // 개발 모드(캐시 미사용)여도 currentDomain 세팅은 동일

        $domain = $this->createMock(Domain::class);
        $domain->method('getDomain')->willReturn('example.local');

        $request = $this->createConfiguredMock(Request::class, [
            'getMethod' => 'GET',
            'getPath' => '/board/list',
        ]);
        $context = $this->createMock(Context::class);
        $context->method('getDomain')->willReturn('demo.example.local'); // raw Host
        $context->method('getDomainInfo')->willReturn($domain);
        $context->method('getDomainId')->willReturn(7);
        $context->method('isAdmin')->willReturn(false);

        $router = new Router();
        $router->dispatch($request, $context);

        $ref = new \ReflectionProperty($router, 'currentDomain');
        $ref->setAccessible(true);
        $this->assertSame('example.local', $ref->getValue($router));
    }

    public function testPurgeDoesNotTouchDottedSuperstringDomain(): void
    {
        // glob '{domain}.*.cache.php' 는 '*'가 점을 포함해 정식 슈퍼스트링 도메인
        // (purge-test.local.au)까지 매칭한다. 12-hex 앵커링으로 걸러야 한다.
        $keep = $this->touch('purge-test.local.a1b2c3d4e5f6.cache.php');
        $superstring = $this->touch('purge-test.local.au.a1b2c3d4e5f6.cache.php');

        $this->invokePurge(new Router(), 'purge-test.local', $keep);

        $this->assertFileExists($keep);
        $this->assertFileExists($superstring, '점을 포함한 슈퍼스트링 도메인 캐시는 건드리지 않아야 한다');
    }
}
