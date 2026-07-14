<?php
/**
 * packages/Shop/tests/Unit/PackageImportsExistTest.php
 *
 * import 무결성 회귀 테스트 — Shop이 참조하는 모든 심볼이 실존하는가
 *
 * 배경: Action 핸들러들이 존재하지 않는 `Mublo\Infrastructure\Logging\Logger`를
 * import하고 정적 호출까지 하고 있었다(실제 클래스는 `...\Log\Logger`, 게다가
 * 인스턴스 메서드). 해당 코드 경로가 평소 안 밟혀 로드 시점엔 안 터졌지만,
 * FSM 액션이 발화하면 fatal이었다. 테스트가 핸들러를 커버하지 않아 놓쳤다.
 *
 * 이 테스트는 그 버그 "클래스"를 통째로 막는다:
 * - Shop 소스의 모든 `use Mublo\...;` 가 실제로 로드 가능한 심볼을 가리키는지 검증.
 * - 오타 네임스페이스·삭제된 클래스·유령 import가 있으면 즉시 실패한다.
 */

namespace Tests\Shop\Unit;

use Tests\Shop\TestCase;

class PackageImportsExistTest extends TestCase
{
    /**
     * Shop 패키지 소스 전체를 스캔해 `use Mublo\...;` 대상이 실존하는지 확인.
     */
    public function testAllMubloImportsResolveToRealSymbols(): void
    {
        $root = dirname(__DIR__, 2); // packages/Shop
        $broken = [];

        foreach ($this->phpFiles($root) as $file) {
            // 테스트 코드 자신은 제외
            if (str_contains($file, DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR)) {
                continue;
            }
            $src = file_get_contents($file);
            if ($src === false) {
                continue;
            }

            // `use Mublo\...;` (function/const use 는 제외) 추출
            if (!preg_match_all('/^\s*use\s+(Mublo\\\\[A-Za-z0-9_\\\\]+)\s*;/m', $src, $m)) {
                continue;
            }

            foreach ($m[1] as $fqcn) {
                if (class_exists($fqcn) || interface_exists($fqcn) || trait_exists($fqcn) || enum_exists($fqcn)) {
                    continue;
                }
                $broken[] = sprintf('%s  →  %s', $fqcn, str_replace($root, 'packages/Shop', $file));
            }
        }

        $this->assertSame(
            [],
            $broken,
            "존재하지 않는 심볼을 import하는 Shop 파일이 있다 (오타 네임스페이스/삭제된 클래스/유령 import):\n"
            . implode("\n", $broken)
        );
    }

    /**
     * 정적 `Logger::` 호출 금지 — 코어 Logger는 인스턴스(DI) API다.
     * (정적 파사드가 없으므로 정적 호출은 반드시 fatal이 된다.)
     */
    public function testNoStaticLoggerCalls(): void
    {
        $root = dirname(__DIR__, 2);
        $offenders = [];

        foreach ($this->phpFiles($root) as $file) {
            if (str_contains($file, DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR)) {
                continue;
            }
            $src = file_get_contents($file);
            if ($src === false) {
                continue;
            }
            // Logger::method( 형태의 정적 호출 (Logger::class 참조는 허용)
            if (preg_match('/\bLogger::(?!class\b)[a-zA-Z]+\s*\(/', $src)) {
                $offenders[] = str_replace($root, 'packages/Shop', $file);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "정적 Logger:: 호출이 있다 — 코어 Logger는 인스턴스 API다. \$this->logger?->... 를 사용하라:\n"
            . implode("\n", $offenders)
        );
    }

    /**
     * @return iterable<string>
     */
    private function phpFiles(string $dir): iterable
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                yield $f->getPathname();
            }
        }
    }
}
