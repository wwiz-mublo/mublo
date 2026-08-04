<?php

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;

/**
 * ConfigFile 의 실패 계약을 고정한다.
 *
 * 예전에는 security.php 하나를 열한 곳에서 읽으면서 경로를 세 가지로 조립했고,
 * 실패 처리도 제각각이었다 — 어떤 곳은 치명적, 어떤 곳은 조용히 기본값.
 * 여기서 정하는 계약은 하나다: 부재는 허용, 읽을 수 없으면 드러낸다.
 *
 * MUBLO_CONFIG_PATH 는 프로세스당 한 번만 정의되므로 자식 프로세스에서 확인한다.
 */
class ConfigFileTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/mublo_cfg_' . bin2hex(random_bytes(6));
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
            . "require \$argv[2];\n"
            . "use Mublo\\Core\\ConfigFile;\n"
            . $body . "\n");

        $command = escapeshellarg(PHP_BINARY)
            . ' ' . escapeshellarg($runner)
            . ' ' . escapeshellarg($this->dir)
            . ' ' . escapeshellarg(MUBLO_ROOT_PATH . '/vendor/autoload.php')
            . ' 2>&1';

        exec($command, $lines, $status);

        return [$status, trim(implode("\n", $lines))];
    }

    public function testMissingFileYieldsEmptyArray(): void
    {
        [$status, $out] = $this->runChild('var_export(ConfigFile::load("security"));');

        $this->assertSame(0, $status, $out);
        $this->assertSame('array (
)', $out);
    }

    public function testExistingFileIsReturned(): void
    {
        file_put_contents($this->dir . '/security.php', "<?php\nreturn ['password' => ['cost' => 14]];\n");

        [$status, $out] = $this->runChild('echo ConfigFile::load("security")["password"]["cost"];');

        $this->assertSame(0, $status, $out);
        $this->assertSame('14', $out);
    }

    public function testUnreadablePathFailsLoudlyInsteadOfDefaulting(): void
    {
        // 권한 0000 은 윈도우에서 재현되지 않으므로, 이식 가능한 "읽을 수 없는 경로"로
        // security.php 라는 이름의 디렉터리를 쓴다. 조용히 빈 배열로 넘어가면 안 된다.
        mkdir($this->dir . '/security.php');

        [$status] = $this->runChild('ConfigFile::load("security"); echo "SURVIVED";');

        $this->assertNotSame(0, $status, '읽을 수 없는 설정이 조용히 기본값으로 넘어갔다');
    }

    public function testNonArrayReturnThrows(): void
    {
        file_put_contents($this->dir . '/security.php', "<?php\nreturn 'broken';\n");

        [$status, $out] = $this->runChild('ConfigFile::load("security"); echo "SURVIVED";');

        $this->assertNotSame(0, $status);
        $this->assertStringNotContainsString('SURVIVED', $out);
    }

    public function testFileIsReadOnlyOncePerRequest(): void
    {
        // 파일 안에서 카운터를 올린다. 두 번 읽히면 2가 된다.
        file_put_contents($this->dir . '/security.php', "<?php\n\$GLOBALS['n'] = (\$GLOBALS['n'] ?? 0) + 1;\nreturn ['n' => \$GLOBALS['n']];\n");

        [$status, $out] = $this->runChild(
            'ConfigFile::load("security"); ConfigFile::load("security");'
            . ' echo ConfigFile::load("security")["n"];'
        );

        $this->assertSame(0, $status, $out);
        $this->assertSame('1', $out, '요청당 한 번만 읽어야 한다');
    }

    public function testResetMakesNewlyWrittenConfigVisible(): void
    {
        // 설치기가 설정을 쓰기 전에 누군가 읽었다면 "없음" 이 캐시된다.
        // reset() 후에는 방금 쓴 값이 보여야 한다.
        [$status, $out] = $this->runChild(
            'ConfigFile::load("security");'
            . ' file_put_contents($argv[1] . "/security.php", "<?php return [\'k\' => \'v\'];");'
            . ' ConfigFile::reset();'
            . ' echo ConfigFile::load("security")["k"] ?? "MISSING";'
        );

        $this->assertSame(0, $status, $out);
        $this->assertSame('v', $out);
    }

    public function testExistsAgreesWithLoad(): void
    {
        file_put_contents($this->dir . '/mail.php', "<?php\nreturn ['driver' => 'smtp'];\n");

        [$status, $out] = $this->runChild(
            'echo ConfigFile::exists("mail") ? "Y" : "N";'
            . ' echo ConfigFile::exists("nope") ? "Y" : "N";'
        );

        $this->assertSame(0, $status, $out);
        $this->assertSame('YN', $out);
    }
}
