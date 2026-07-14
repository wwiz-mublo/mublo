<?php

namespace Tests\Unit\Infrastructure\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Mublo\Infrastructure\Storage\SecureFileService;

/**
 * moveFinal() 소스 경로 검증 회귀 테스트.
 *
 * 소스는 반드시 '호출자 도메인의 temp 디렉토리(temp/D{domainId}/) 바로 아래 파일'이어야 한다.
 * 이 가드가 없으면 사용자가 타 회원·타 도메인의 최종 파일 경로를 temp_path 로 넘겨
 * 그 파일을 자기 소유로 rename/편입할 수 있다(IDOR).
 */
#[CoversClass(SecureFileService::class)]
class SecureFileServiceMoveFinalTest extends TestCase
{
    private string $basePath;
    private SecureFileService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->basePath = sys_get_temp_dir() . '/mublo_securefile_' . bin2hex(random_bytes(6));
        mkdir($this->basePath, 0755, true);
        $this->service = new SecureFileService($this->basePath, 'test-secret-key');
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->basePath);
        parent::tearDown();
    }

    private function seedTempFile(string $relativePath): void
    {
        $full = $this->basePath . '/' . $relativePath;
        $dir = dirname($full);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($full, 'dummy');
    }

    #[Test]
    public function testMovesFileFromOwnDomainTemp(): void
    {
        $this->seedTempFile('temp/D1/abc123.pdf');

        $stored = $this->service->moveFinal('temp/D1/abc123.pdf', 1, 'member-fields', '10');

        $this->assertSame('D1/member-fields/10/abc123.pdf', $stored->relativePath);
        $this->assertFileExists($this->basePath . '/D1/member-fields/10/abc123.pdf');
        // 원본 temp 파일은 이동되어 사라진다
        $this->assertFileDoesNotExist($this->basePath . '/temp/D1/abc123.pdf');
    }

    #[Test]
    public function testRejectsOtherDomainTempSource(): void
    {
        // 도메인 1의 요청인데 소스는 도메인 2의 temp → 거부
        $this->seedTempFile('temp/D2/victim.pdf');

        $this->expectException(\InvalidArgumentException::class);
        $this->service->moveFinal('temp/D2/victim.pdf', 1, 'member-fields', '10');
    }

    #[Test]
    public function testRejectsFinalPathSource(): void
    {
        // temp 가 아닌 타 회원의 최종 파일 경로를 소스로 제출 → 거부(핵심 IDOR 시나리오)
        $this->seedTempFile('D1/member-fields/999/victim.pdf');

        $this->expectException(\InvalidArgumentException::class);
        $this->service->moveFinal('D1/member-fields/999/victim.pdf', 1, 'member-fields', '10');
    }

    #[Test]
    public function testRejectsNestedTempPath(): void
    {
        // temp/D1/ 아래 추가 하위 경로 → 거부(항상 flat 파일만 허용)
        $this->expectException(\InvalidArgumentException::class);
        $this->service->moveFinal('temp/D1/sub/deep.pdf', 1, 'member-fields', '10');
    }

    #[Test]
    public function testRejectsTraversalSource(): void
    {
        // .. 탈출은 기존 assertRelativePath 가 차단
        $this->expectException(\InvalidArgumentException::class);
        $this->service->moveFinal('temp/D1/../../etc/passwd', 1, 'member-fields', '10');
    }

    // ----- resolveOwnedTempPath: 아바타 public 경로도 moveFinal 과 동일한 IDOR 방어 -----

    #[Test]
    public function testResolveOwnedTempPathAcceptsOwnDomain(): void
    {
        $path = $this->service->resolveOwnedTempPath('temp/D1/abc123.jpg', 1);
        $this->assertStringEndsWith('/temp/D1/abc123.jpg', str_replace('\\', '/', $path));
    }

    #[Test]
    public function testResolveOwnedTempPathRejectsOtherDomain(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->resolveOwnedTempPath('temp/D2/victim.jpg', 1);
    }

    #[Test]
    public function testResolveOwnedTempPathRejectsFinalPath(): void
    {
        // 아바타 temp_path 로 타 회원 최종 secure 파일 경로 제출 → 거부(핵심 IDOR)
        $this->expectException(\InvalidArgumentException::class);
        $this->service->resolveOwnedTempPath('D1/member-fields/999/victim.jpg', 1);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
