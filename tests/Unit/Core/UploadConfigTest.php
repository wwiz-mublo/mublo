<?php

namespace Tests\Unit\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 업로드 상한이 config/upload.php 로 정해지는 계약을 고정한다.
 *
 * 이 파일은 운영자가 직접 열어 고치는 것을 전제하므로, 없거나 망가졌을 때
 * "제한 없음" 으로 열리면 안 된다. 부재·오타는 모두 기본값으로 수렴해야 한다.
 *
 * MUBLO_CONFIG_PATH 는 프로세스당 한 번만 정의되므로 자식 프로세스에서 확인한다
 * (ConfigFileTest 와 같은 방식).
 */
class UploadConfigTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/mublo_upload_' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        foreach (glob($this->dir . '/*') ?: [] as $item) {
            is_dir($item) ? @rmdir($item) : @unlink($item);
        }
        @rmdir($this->dir);
    }

    /**
     * @return array{0: int, 1: string} 종료 코드와 표준 출력
     */
    private function runChild(string $body): array
    {
        $runner = $this->dir . '/runner.php';
        file_put_contents($runner, "<?php\n"
            . "define('MUBLO_CONFIG_PATH', \$argv[1]);\n"
            . "define('MUBLO_STORAGE_PATH', \$argv[1]);\n"
            . "define('MUBLO_ROOT_PATH', \$argv[3]);\n"
            . "define('MUBLO_PUBLIC_PATH', \$argv[3] . '/public');\n"
            . "define('MUBLO_PUBLIC_STORAGE_PATH', \$argv[3] . '/public/storage');\n"
            // bootstrap.php 가 정의하는 상수 집합을 그대로 맞춘다 —
            // EnvironmentChecker 가 확장 디렉토리 쓰기 여부까지 보므로 여기에도 필요하다
            . "define('MUBLO_PLUGIN_PATH', \$argv[3] . '/plugins');\n"
            . "define('MUBLO_PACKAGE_PATH', \$argv[3] . '/packages');\n"
            . "require \$argv[2];\n"
            . "use Mublo\\Core\\ConfigFile;\n"
            . "use Mublo\\Core\\UploadConfig;\n"
            . $body . "\n");

        $command = escapeshellarg(PHP_BINARY)
            . ' ' . escapeshellarg($runner)
            . ' ' . escapeshellarg($this->dir)
            . ' ' . escapeshellarg(MUBLO_ROOT_PATH . '/vendor/autoload.php')
            . ' ' . escapeshellarg(MUBLO_ROOT_PATH)
            . ' 2>&1';

        exec($command, $lines, $status);

        return [$status, trim(implode("\n", $lines))];
    }

    private function writeConfig(string $php): void
    {
        file_put_contents($this->dir . '/upload.php', $php);
    }

    public function testMissingFileFallsBackToDefaultsInsteadOfUnlimited(): void
    {
        [$status, $out] = $this->runChild(
            'echo UploadConfig::editorImageMaxBytes(false), ",", UploadConfig::editorImageMaxBytes(true);'
        );

        $this->assertSame(0, $status, $out);
        $this->assertSame((20 * 1024 * 1024) . ',' . (5 * 1024 * 1024), $out);
    }

    public function testOperatorValuesAreApplied(): void
    {
        $this->writeConfig("<?php\nreturn ['editor_image' => ['max_size_mb' => 50, 'guest_max_size_mb' => 2]];\n");

        [$status, $out] = $this->runChild(
            'echo UploadConfig::editorImageMaxBytes(false), ",", UploadConfig::editorImageMaxBytes(true);'
        );

        $this->assertSame(0, $status, $out);
        $this->assertSame((50 * 1024 * 1024) . ',' . (2 * 1024 * 1024), $out);
    }

    public function testGuestLimitIsIndependentOfMemberLimit(): void
    {
        // 회원 한도만 올린 설정에서 비회원이 함께 올라가면, 무인증 경로가 조용히 열린다.
        $this->writeConfig("<?php\nreturn ['editor_image' => ['max_size_mb' => 100]];\n");

        [$status, $out] = $this->runChild('echo UploadConfig::editorImageMaxBytes(true);');

        $this->assertSame(0, $status, $out);
        $this->assertSame((string) (5 * 1024 * 1024), $out);
    }

    public function testFractionalMegabytesAreAllowed(): void
    {
        $this->writeConfig("<?php\nreturn ['editor_image' => ['guest_max_size_mb' => 0.5]];\n");

        [$status, $out] = $this->runChild('echo UploadConfig::editorImageMaxBytes(true);');

        $this->assertSame(0, $status, $out);
        $this->assertSame((string) (512 * 1024), $out);
    }

    #[DataProvider('unusableValues')]
    public function testUnusableValueFallsBackToDefault(string $literal): void
    {
        $this->writeConfig("<?php\nreturn ['editor_image' => ['max_size_mb' => {$literal}]];\n");

        [$status, $out] = $this->runChild('echo UploadConfig::editorImageMaxBytes(false);');

        $this->assertSame(0, $status, $out);
        $this->assertSame((string) (20 * 1024 * 1024), $out, "쓸 수 없는 값 {$literal} 이 기본값으로 수렴하지 않았다");
    }

    public static function unusableValues(): array
    {
        return [
            '빈 문자열' => ["''"],
            '숫자가 아닌 문자열' => ["'스무'"],
            '0' => ['0'],
            '음수' => ['-5'],
            'null' => ['null'],
            '배열' => ['[20]'],
        ];
    }

    public function testMalformedSectionFallsBackToDefault(): void
    {
        // editor_image 자체가 배열이 아닌 경우 (운영자가 구조를 뭉갠 상황)
        $this->writeConfig("<?php\nreturn ['editor_image' => 20];\n");

        [$status, $out] = $this->runChild('echo UploadConfig::editorImageMaxBytes(false);');

        $this->assertSame(0, $status, $out);
        $this->assertSame((string) (20 * 1024 * 1024), $out);
    }

    public function testInstallerGeneratesReadableConfigMatchingCodeDefaults(): void
    {
        [$status, $out] = $this->runChild(
            '$ok = (new \\Mublo\\Core\\Install\\Installer())->generateUploadConfig();'
            . 'ConfigFile::reset();'
            . 'echo $ok ? "1" : "0", ",", UploadConfig::editorImageMaxBytes(false),'
            . '",", UploadConfig::editorImageMaxBytes(true);'
        );

        $this->assertSame(0, $status, $out);
        $this->assertSame('1,' . (20 * 1024 * 1024) . ',' . (5 * 1024 * 1024), $out);
        $this->assertFileExists($this->dir . '/upload.php');
    }

    public function testInstallerDoesNotOverwriteExistingOperatorConfig(): void
    {
        $this->writeConfig("<?php\nreturn ['editor_image' => ['max_size_mb' => 7]];\n");
        $before = hash_file('sha256', $this->dir . '/upload.php');

        [$status, $out] = $this->runChild(
            '(new \\Mublo\\Core\\Install\\Installer())->generateUploadConfig();'
            . 'ConfigFile::reset();'
            . 'echo UploadConfig::editorImageMaxBytes(false);'
        );

        $this->assertSame(0, $status, $out);
        $this->assertSame((string) (7 * 1024 * 1024), $out);
        $this->assertSame($before, hash_file('sha256', $this->dir . '/upload.php'));
    }
}
