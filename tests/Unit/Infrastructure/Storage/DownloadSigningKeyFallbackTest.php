<?php

namespace Tests\Unit\Infrastructure\Storage;

use Mublo\Core\ConfigFile;
use Mublo\Infrastructure\Storage\SecureFileService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/**
 * 파일 다운로드 토큰 서명 키의 위치 이동 — 기존 설치 호환.
 *
 * 배경: 이 키는 CSRF 와 아무 관계가 없는데 security.csrf.token_key 자리에 있었고,
 * 설치 화면도 'CSRF 토큰 키'로 표시했다. "CSRF 키니까 바꿔도 되겠지" 하고
 * 재생성하면 발급된 다운로드 링크가 전부 죽는 자리였다.
 *
 * security.file.download_signing_key 로 옮기되, 옛 위치를 폴백으로 읽어
 * 기존 설치의 다운로드 링크가 살아 있게 한다.
 */
final class DownloadSigningKeyFallbackTest extends TestCase
{
    protected function tearDown(): void
    {
        ConfigFile::reset();
        parent::tearDown();
    }

    /**
     * ConfigFile 의 정적 캐시에 설정을 직접 넣어, 실제 loadSecretKey() 를 태운다.
     * 로직을 테스트에 복제하면 구현을 지워도 통과하는 무의미한 테스트가 된다.
     *
     * @param array<string,mixed> $security
     */
    private function resolveWithConfig(array $security): string
    {
        $loaded = new ReflectionProperty(ConfigFile::class, 'loaded');
        $loaded->setValue(null, ['security' => $security]);

        // 생성자에서 키를 확정하므로, 설정을 심은 뒤에 만든다
        $service = new SecureFileService();

        $method = new ReflectionMethod($service, 'loadSecretKey');

        return $method->invoke($service);
    }

    public function testNewLocationWins(): void
    {
        $key = $this->resolveWithConfig([
            'file' => ['download_signing_key' => 'new-key'],
            'csrf' => ['token_key' => 'legacy-key'],
        ]);

        $this->assertSame('new-key', $key);
    }

    public function testLegacyLocationKeepsExistingInstallsWorking(): void
    {
        // 이름을 바꾸기 전에 설치된 사이트 — 값을 옮기지 않아도 링크가 살아야 한다
        $key = $this->resolveWithConfig(['csrf' => ['token_key' => 'legacy-key']]);

        $this->assertSame('legacy-key', $key);
    }

    public function testEmptyNewKeyDoesNotShadowTheLegacyOne(): void
    {
        // 설치기가 새 항목을 빈 값으로 써둔 경우까지 폴백이 살아야 한다
        $key = $this->resolveWithConfig([
            'file' => ['download_signing_key' => ''],
            'csrf' => ['token_key' => 'legacy-key'],
        ]);

        $this->assertSame('legacy-key', $key);
    }

    public function testFallsBackToARandomKeyWhenNothingIsConfigured(): void
    {
        // 설치 전에는 config 가 없다. 죽지 않고 동작하되, 재시작하면 기존 토큰이
        // 무효가 된다 — 기존 계약 그대로다.
        $key = $this->resolveWithConfig([]);

        $this->assertSame(64, strlen($key), '랜덤 폴백은 hex 64자여야 한다');
    }
}
