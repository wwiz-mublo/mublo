<?php

namespace Tests\Unit\Infrastructure\Log;

use PHPUnit\Framework\TestCase;
use Mublo\Infrastructure\Log\Logger;

/**
 * LoggerMaskingTest
 *
 * 로그 context의 민감 정보(비밀번호/토큰 등) 마스킹 검증.
 * 실제 파일 기록 결과를 읽어 시크릿이 남지 않는지 확인한다.
 */
class LoggerMaskingTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/mublo-log-test-' . bin2hex(random_bytes(4));
        @mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        // 재귀 삭제
        if (is_dir($this->tmpDir)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tmpDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($this->tmpDir);
        }
        parent::tearDown();
    }

    private function logAndRead(array $context, string $message = 'test'): string
    {
        $logger = new Logger(0, 'app', \Mublo\Infrastructure\Log\LogLevel::DEBUG, $this->tmpDir);
        $logger->info($message, $context);
        return $logger->read()[0] ?? '';
    }

    public function testMasksSensitiveTopLevelKeys(): void
    {
        $line = $this->logAndRead([
            'user_id'  => 42,
            'password' => 'super-secret',
            'token'    => 'abc123',
            'api_key'  => 'k-999',
        ]);

        $this->assertStringContainsString('"user_id":42', $line);
        $this->assertStringNotContainsString('super-secret', $line);
        $this->assertStringNotContainsString('abc123', $line);
        $this->assertStringNotContainsString('k-999', $line);
        $this->assertStringContainsString('***MASKED***', $line);
    }

    public function testMasksNestedSensitiveKeys(): void
    {
        $line = $this->logAndRead([
            'request' => [
                'params' => [
                    'access_token' => 'tok-secret',
                    'email'        => 'a@b.com',
                ],
            ],
        ]);

        $this->assertStringNotContainsString('tok-secret', $line);
        $this->assertStringContainsString('a@b.com', $line);  // 비민감 값은 보존
    }

    public function testDoesNotOverMaskInnocuousKeys(): void
    {
        $line = $this->logAndRead([
            'cache_key' => 'menu:1',   // 'key' 단독은 마스킹 대상 아님
            'author'    => 'hong',     // 'auth' 단독은 마스킹 대상 아님
        ]);

        $this->assertStringContainsString('menu:1', $line);
        $this->assertStringContainsString('hong', $line);
        $this->assertStringNotContainsString('***MASKED***', $line);
    }

    public function testMaskedValueNotInterpolatedIntoMessage(): void
    {
        // 메시지 플레이스홀더로도 시크릿이 새지 않아야 한다
        $line = $this->logAndRead(['token' => 'leak-me'], 'auth {token}');
        $this->assertStringNotContainsString('leak-me', $line);
        $this->assertStringContainsString('***MASKED***', $line);
    }
}
