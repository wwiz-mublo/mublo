<?php

namespace Tests\Unit\Core\Http;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * 클라이언트 IP 판정은 **한 곳에만** 있어야 한다 (2026-09-11).
 *
 * Request 객체가 없다는 이유로 `$_SERVER['REMOTE_ADDR']` 을 직접 읽던 자리가 셋
 * 있었다 — `SecureFileService`, `Application::logRequest`, `ErrorHandler`.
 *
 * 프록시 뒤에서는 그 값이 모든 요청에 같은 엣지 주소다. 로그라면 요청을 구분할 수
 * 없다는 뜻이고, `SecureFileService` 는 다운로드 링크를 그 IP 에 묶어 보호하므로
 * **묶은 것이 아무것도 아니게** 된다 — 링크가 새면 누구나 쓴다.
 *
 * 새 자리가 생겼을 때 조용히 빠지지 않도록 소스를 직접 본다.
 */
class ClientIpSingleSourceTest extends TestCase
{
    public function testNothingReadsRemoteAddrDirectly(): void
    {
        $root = dirname(__DIR__, 4);
        $offenders = [];

        foreach (['/src', '/packages'] as $dir) {
            if (!is_dir($root . $dir)) {
                continue;
            }
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root . $dir)
            );
            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $path = str_replace('\\', '/', $file->getPathname());
                // 판정이 사는 곳. 여기서만 REMOTE_ADDR 을 읽는다.
                if (str_ends_with($path, '/Core/Http/Request.php')) {
                    continue;
                }
                foreach (file($path) ?: [] as $index => $line) {
                    // 주석까지 세면 설명을 적을 때마다 걸린다. 실제 읽기만 본다.
                    if (preg_match('~^\s*(//|\*|#)~', $line)) {
                        continue;
                    }
                    if (preg_match('~\$_SERVER\s*\[\s*[\'"]REMOTE_ADDR~', $line)) {
                        $offenders[] = substr($path, strlen($root) + 1) . ':' . ($index + 1);
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "REMOTE_ADDR 을 직접 읽는 자리가 있습니다. Request::clientIpFromServer() 를 쓰세요:\n  "
            . implode("\n  ", $offenders)
        );
    }
}
