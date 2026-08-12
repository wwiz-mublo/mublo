<?php

namespace Tests\Unit\Service\Extension;

use PHPUnit\Framework\TestCase;
use Mublo\Service\Extension\ExtensionDirectoryProbe;

class ExtensionDirectoryProbeTest extends TestCase
{
    public function testWritableDirectoriesReportNoReasonOrGuidance(): void
    {
        $probe = new ExtensionDirectoryProbe(
            sys_get_temp_dir(),
            sys_get_temp_dir(),
            static fn (string $path): bool => true
        );

        $result = $probe->check();

        $this->assertTrue($result['plugin']['writable']);
        $this->assertTrue($result['package']['writable']);
        $this->assertSame('plugins/', $result['plugin']['directory']);
        $this->assertSame('packages/', $result['package']['directory']);
        $this->assertSame('', $result['plugin']['reason']);
        $this->assertSame('', $result['plugin']['guidance']);
    }

    public function testUnwritableDirectoryReportsReasonAndGuidance(): void
    {
        $probe = new ExtensionDirectoryProbe(
            sys_get_temp_dir(),
            sys_get_temp_dir(),
            static fn (string $path): bool => false
        );

        $plugin = $probe->check()['plugin'];

        $this->assertFalse($plugin['writable']);
        $this->assertSame('실제 파일 생성/삭제 불가', $plugin['reason']);
        $this->assertStringContainsString('FTP', $plugin['guidance']);
        // 코드가 실행되는 디렉토리라 웹 유저에게 상시 쓰기를 여는 707 은 해법으로 제시하지 않는다
        $this->assertStringNotContainsString('707로 설정', $plugin['guidance']);
    }

    public function testMissingDirectoryIsReportedAsSuchNotAsPermission(): void
    {
        $missing = sys_get_temp_dir() . '/mublo-absent-' . bin2hex(random_bytes(5));

        $plugin = (new ExtensionDirectoryProbe($missing, sys_get_temp_dir()))->check()['plugin'];

        $this->assertFalse($plugin['writable']);
        $this->assertSame('디렉토리가 존재하지 않음', $plugin['reason']);
    }

    public function testRealProbeLeavesNoDiagnosticFile(): void
    {
        $directory = sys_get_temp_dir() . '/mublo-ext-probe-' . bin2hex(random_bytes(5));
        mkdir($directory, 0700);

        try {
            $plugin = (new ExtensionDirectoryProbe($directory, $directory))->check()['plugin'];

            $this->assertTrue($plugin['writable']);
            $this->assertSame([], glob($directory . '/.mublo-write-test-*') ?: []);
        } finally {
            @rmdir($directory);
        }
    }
}
