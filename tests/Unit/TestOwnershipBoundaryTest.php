<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * tests/Unit/TestOwnershipBoundaryTest.php
 *
 * 테스트 소유권 경계
 *
 * 루트 tests/ 는 코어 전용이고, 확장(Package/Plugin)의 테스트는 그 확장이
 * 소유한다. 확장은 떼었다 붙였다 하는 단위라, 확장을 지웠을 때 테스트도
 * 함께 떨어져 나가야 하기 때문이다.
 *
 * 이 경계는 사람 눈으로만 지켜지지 않는다. 실제로 확장 테스트를 이관한
 * 직후, 병렬로 진행되던 다른 작업이 옛 위치에 테스트를 새로 추가한 채로
 * 머지된 적이 있다. 새 위치든 옛 위치든 PHPUnit 이 둘 다 수집하므로
 * 테스트는 그대로 통과했고 아무 신호도 없었다.
 *
 * @see docs/dev-guide/testing.md
 */
class TestOwnershipBoundaryTest extends TestCase
{
    /**
     * 확장을 소재로 쓰지만 검증 대상은 코어 로직인 테스트.
     *
     * 이런 테스트는 확장을 지워도 남아야 하므로 코어 소유가 맞다.
     * 추가할 때는 "이 확장을 지우면 이 테스트도 지워져야 하나?" 에
     * 아니오라고 답할 수 있어야 한다.
     *
     * @var list<string> 루트 기준 상대 경로
     */
    private const CORE_OWNED_EXCEPTIONS = [
    ];

    /**
     * 확장 이름이 박힌 경로 리터럴을 쓰지만 코어 소유가 맞는 테스트.
     *
     * @var array<string, string> 루트 기준 상대 경로 => 사유
     */
    private const FIXTURE_PATH_EXCEPTIONS = [
        'tests/Unit/Tools/ExtensionApiPathTest.php'
            => '경로 정규화 도구의 입력 문자열 — 실파일을 열지 않는다',
        'tests/Unit/Tools/ExtensionApiBaselineTest.php'
            => '베이스라인 도구의 입력 문자열 — 실파일을 열지 않는다',
        'tests/Unit/Tools/EventPayloadScannerTest.php'
            => '이벤트 payload 도구의 입력 문자열 — 실파일을 열지 않는다',
        'tests/Unit/Tools/EventConsumerScannerTest.php'
            => '이벤트 소비자 도구의 입력 문자열 — 실파일을 열지 않는다',
        'tests/Unit/Core/Extension/NestedPluginTest.php'
            => '검증 대상은 코어 NestedPlugin, Board/BoardReport 는 실물 픽스처',
        'tests/Unit/Service/Extension/ExtensionLifecycleMigrationTest.php'
            => '검증 대상은 코어 ExtensionService, Board/BoardReport 는 실물 픽스처',
    ];

    public function testRootTestsDoNotReferenceExtensionClasses(): void
    {
        $violations = [];

        foreach ($this->rootTestFiles() as $relative => $path) {
            if (in_array($relative, self::CORE_OWNED_EXCEPTIONS, true)) {
                continue;
            }

            $source = (string) file_get_contents($path);
            if (preg_match_all('/^use (Mublo\\\\(?:Packages|Plugin)\\\\(\w+))/m', $source, $matches) === 0) {
                continue;
            }

            foreach (array_unique($matches[1]) as $index => $symbol) {
                $violations[] = sprintf(
                    "  %s\n    → %s 참조. %s 로 옮길 것.",
                    $relative,
                    $symbol,
                    $this->suggestDestination($matches[2][$index])
                );
            }
        }

        $this->assertSame(
            [],
            $violations,
            "루트 tests/ 는 코어 전용이다. 확장 테스트는 확장이 소유한다.\n"
            . implode("\n", $violations)
            . "\n\n  판정 기준과 이관 절차는 docs/dev-guide/testing.md 참고."
            . "\n  검증 대상이 코어 로직이라면 CORE_OWNED_EXCEPTIONS 에 등록한다."
        );
    }

    /**
     * 클래스를 import 하지 않고 확장 파일을 경로로 직접 읽는 테스트.
     *
     * 실제로 이렇게 샜다. 뷰 소스를 정규식으로 훑는 회귀 테스트가
     * 코어 tests/ 에 있으면서 packages/Board, packages/Shop 의 뷰
     * 9개를 문자열 경로로 읽고 있었다. use 문이 하나도 없으니
     * 위 검사는 통과했고, Board 의 변수명(boardSlug)을 아는 마커까지
     * 코어 테스트가 들고 있었다.
     *
     * 확장을 떼면 file_get_contents 가 false 를 반환해 깨진다 —
     * 즉 그 확장이 소유해야 할 테스트다.
     */
    public function testRootTestsDoNotReadExtensionFilesByPath(): void
    {
        $violations = [];

        foreach ($this->rootTestFiles() as $relative => $path) {
            if (isset(self::FIXTURE_PATH_EXCEPTIONS[$relative])) {
                continue;
            }

            $source = (string) file_get_contents($path);

            foreach ($this->extensionPathPatterns() as $pattern) {
                if (preg_match_all($pattern, $source, $matches) === 0) {
                    continue;
                }

                foreach (array_unique($matches[1]) as $extension) {
                    $violations[] = sprintf(
                        "  %s\n    → %s 경로를 직접 읽는다. %s 로 옮길 것.",
                        $relative,
                        $extension,
                        $this->suggestDestination($extension)
                    );
                }
            }
        }

        $violations = array_values(array_unique($violations));

        $this->assertSame(
            [],
            $violations,
            "루트 tests/ 는 코어 파일만 읽는다. 확장 파일을 훑는 테스트는\n"
            . "그 확장이 소유한다.\n"
            . implode("\n", $violations)
            . "\n\n  확장을 소재로만 쓰는 코어 테스트라면 FIXTURE_PATH_EXCEPTIONS 에"
            . "\n  사유와 함께 등록한다."
        );
    }

    /**
     * 참조가 없더라도 확장 이름을 딴 디렉터리 자체가 옛 배치의 흔적이다.
     */
    public function testRootTestsHaveNoExtensionShapedDirectories(): void
    {
        $forbidden = [];

        foreach (['Package', 'Packages', 'Plugin', 'Plugins'] as $name) {
            foreach (glob($this->rootPath() . '/tests/*/' . $name, GLOB_ONLYDIR) ?: [] as $dir) {
                $forbidden[] = 'tests/' . basename(dirname($dir)) . '/' . $name;
            }
        }

        $this->assertSame(
            [],
            $forbidden,
            "루트 tests/ 에 확장용 디렉터리를 두지 않는다. 확장 테스트는\n"
            . "packages/{Pkg}/tests, plugins/{Plugin}/tests 가 소유한다.\n  "
            . implode("\n  ", $forbidden)
        );
    }

    /**
     * 확장이 테스트를 가졌다면 부트스트랩도 함께 있어야 한다.
     *
     * 없으면 그 확장만 단독으로 돌릴 때 경로 상수와 오토로드가 없다.
     */
    public function testExtensionTestDirectoriesShipABootstrap(): void
    {
        $missing = [];

        foreach (['packages', 'plugins'] as $kind) {
            foreach (glob($this->rootPath() . '/' . $kind . '/*/tests', GLOB_ONLYDIR) ?: [] as $dir) {
                if (!is_file($dir . '/bootstrap.php')) {
                    $missing[] = $kind . '/' . basename(dirname($dir)) . '/tests/bootstrap.php';
                }
            }
        }

        $this->assertSame([], $missing, "확장 테스트 디렉터리에 bootstrap.php 가 없다:\n  " . implode("\n  ", $missing));
    }

    /**
     * 와일드카드 수집(`packages/*\/tests`)은 확장 이름을 모르므로 걸리지 않는다.
     * 대문자로 시작하는 실제 확장 이름만 잡는다.
     *
     * @return list<string>
     */
    private function extensionPathPatterns(): array
    {
        return [
            // '/packages/{Pkg}/views/...' 같은 경로 리터럴 (한 줄 안에서만)
            '#[\'"][^\'"\n]*(?:packages|plugins)/([A-Z][A-Za-z0-9]*)/#',
            // MUBLO_PACKAGE_PATH . '/{Pkg}/...'
            '#MUBLO_(?:PACKAGE|PLUGIN)_PATH\s*\.\s*[\'"]/([A-Z][A-Za-z0-9]*)#',
        ];
    }

    /**
     * @return array<string, string> 상대 경로 => 절대 경로
     */
    private function rootTestFiles(): array
    {
        $root = $this->rootPath();
        $files = [];

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/tests'));
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = str_replace('\\', '/', $file->getPathname());
            $files[str_replace(str_replace('\\', '/', $root) . '/', '', $path)] = $file->getPathname();
        }

        ksort($files);

        return $files;
    }

    private function suggestDestination(string $extension): string
    {
        return is_dir($this->rootPath() . '/packages/' . $extension)
            ? 'packages/' . $extension . '/tests/'
            : 'plugins/' . $extension . '/tests/';
    }

    private function rootPath(): string
    {
        return defined('MUBLO_ROOT_PATH') ? MUBLO_ROOT_PATH : dirname(__DIR__, 2);
    }
}
