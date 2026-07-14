<?php

namespace Tests\Unit\Service\System;

use Mublo\Service\System\InstallIdProvider;
use PHPUnit\Framework\TestCase;

class InstallIdProviderTest extends TestCase
{
    /**
     * 실제 storage/ 를 건드리지 않도록 임시 디렉토리를 주입한다.
     * (테스트 부트스트랩의 MUBLO_STORAGE_PATH 는 운영 storage 를 가리킨다)
     */
    private string $storagePath;

    protected function setUp(): void
    {
        $this->storagePath = sys_get_temp_dir() . '/mublo-install-id-' . bin2hex(random_bytes(6));
        mkdir($this->storagePath, 0777, true);
    }

    protected function tearDown(): void
    {
        $file = $this->storagePath . '/install-id';
        if (is_file($file)) {
            unlink($file);
        }
        if (is_dir($this->storagePath)) {
            rmdir($this->storagePath);
        }
    }

    public function testHashIsStableAcrossInstances(): void
    {
        $first = $this->makeProvider()->getHash();
        $second = $this->makeProvider()->getHash();

        $this->assertNotNull($first);
        $this->assertSame($first, $second, '한 번 만든 설치 식별자는 유지되어야 한다');
    }

    public function testHashIsNotTheRawUuidOnDisk(): void
    {
        $hash = $this->makeProvider()->getHash();
        $stored = trim(file_get_contents($this->storagePath . '/install-id'));

        $this->assertNotSame($stored, $hash, '밖으로는 해시만 노출한다');
        $this->assertSame(hash('sha256', $stored), $hash);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $stored, 'UUID v4 형태여야 한다');
    }

    public function testDistinctInstallsProduceDistinctHashes(): void
    {
        $first = $this->makeProvider()->getHash();
        unlink($this->storagePath . '/install-id');
        $second = $this->makeProvider()->getHash();

        $this->assertNotSame($first, $second, '식별자는 랜덤이라 설치마다 달라야 한다');
    }

    public function testMatchesOnlyForCurrentInstall(): void
    {
        $provider = $this->makeProvider();
        $hash = $provider->getHash();

        $this->assertTrue($provider->matches($hash));
        $this->assertFalse($provider->matches('other-install-hash'));
        $this->assertFalse($provider->matches(null), '블록 킷에 source_install이 없으면 같은 설치로 볼 수 없다');
        $this->assertFalse($provider->matches(''));
    }

    public function testMissingStorageDegradesToNullInsteadOfThrowing(): void
    {
        $provider = new InstallIdProvider($this->storagePath . '/does-not-exist');

        $this->assertNull(@$provider->getHash(), '저장할 수 없으면 블록 킷은 source_install 없이 진행한다');
    }

    private function makeProvider(): InstallIdProvider
    {
        return new InstallIdProvider($this->storagePath);
    }
}
