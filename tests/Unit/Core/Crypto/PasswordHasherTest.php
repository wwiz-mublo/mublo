<?php

namespace Tests\Unit\Core\Crypto;

use Mublo\Core\Crypto\PasswordHasher;
use PHPUnit\Framework\TestCase;

/**
 * PasswordHasher 회귀 테스트
 *
 * 배경: 과거 각 서비스가 옵션 없는 password_hash(PASSWORD_BCRYPT) 를 직접 호출해
 * config/security.php 의 password.cost 가 무시됐다(PHP 8.2/8.3 기본 cost 10).
 * 이 테스트가 "설정이 실제로 소비되는지"를 보증한다.
 */
class PasswordHasherTest extends TestCase
{
    public function testHashHonorsConfiguredCost(): void
    {
        $hasher = new PasswordHasher(['algo' => PASSWORD_BCRYPT, 'cost' => 12]);

        $hash = $hasher->hash('secret123');

        // bcrypt 해시 문자열에 cost 가 각인된다: $2y$12$...
        $this->assertStringStartsWith('$2y$12$', $hash);
        $this->assertTrue(password_verify('secret123', $hash));
    }

    public function testOutOfRangeCostIsClamped(): void
    {
        // 너무 낮은 값은 크래킹 취약 → 하한으로 보정
        $this->assertSame(10, (new PasswordHasher(['cost' => 4]))->getCost());
        // 너무 높은 값은 로그인 마비(DoS) → 상한으로 보정
        $this->assertSame(15, (new PasswordHasher(['cost' => 31]))->getCost());
        // 정상 범위는 그대로
        $this->assertSame(12, (new PasswordHasher(['cost' => 12]))->getCost());
    }

    public function testDefaultCostWhenConfigMissing(): void
    {
        // config 부재(설치 전·테스트 환경)에도 설치기 기본값과 동일하게 동작
        $this->assertSame(12, (new PasswordHasher([]))->getCost());
    }

    public function testNeedsRehashDetectsOutdatedCost(): void
    {
        $hasher = new PasswordHasher(['algo' => PASSWORD_BCRYPT, 'cost' => 12]);

        // 과거 코드가 만들던 cost 10 해시 → 재해싱 필요
        $legacyHash = password_hash('secret123', PASSWORD_BCRYPT, ['cost' => 10]);
        $this->assertTrue($hasher->needsRehash($legacyHash));

        // 현재 설정으로 만든 해시 → 재해싱 불필요
        $this->assertFalse($hasher->needsRehash($hasher->hash('secret123')));
    }

    public function testConfigIsReadFromDiskWhenNotInjected(): void
    {
        $dir = $this->makeConfigDir();
        file_put_contents($dir . '/security.php', "<?php\nreturn ['password' => ['cost' => 14]];\n");

        [$status, $output] = $this->costInChildProcess($dir);

        $this->assertSame(0, $status, $output);
        $this->assertSame('14', $output);
    }

    public function testMissingConfigFallsBackToDefaultCost(): void
    {
        // 설치 전·테스트 환경. 파일 부재는 허용해야 한다.
        [$status, $output] = $this->costInChildProcess($this->makeConfigDir());

        $this->assertSame(0, $status, $output);
        $this->assertSame('12', $output);
    }

    public function testUnreadableConfigFailsLoudlyInsteadOfSilentlyWeakeningCost(): void
    {
        // 예전에는 @include 라 읽기 실패가 조용히 삼켜졌다. 그러면 cost 가 기본값으로
        // 내려앉고, 로그인마다 도는 needsRehash() 가 강한 해시를 약한 쪽으로 다시 쓴다.
        //
        // 권한 0000 은 윈도우에서 재현되지 않으므로, 이식 가능한 "읽을 수 없는 경로"로
        // security.php 라는 이름의 디렉터리를 쓴다. file_exists() 는 true 를 주지만
        // 내용을 읽을 수는 없다 — @include 는 false 를 반환하고 넘어갔다.
        $dir = $this->makeConfigDir();
        mkdir($dir . '/security.php');

        [$status] = $this->costInChildProcess($dir);

        $this->assertNotSame(0, $status, '읽을 수 없는 설정이 조용히 기본값으로 넘어갔다');
    }

    private function makeConfigDir(): string
    {
        $dir = sys_get_temp_dir() . '/mublo_pw_' . bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        return $dir;
    }

    /**
     * MUBLO_CONFIG_PATH 는 프로세스당 한 번만 정의되므로 자식 프로세스에서 확인한다.
     *
     * @return array{0: int, 1: string} 종료 코드와 표준 출력
     */
    private function costInChildProcess(string $configDir): array
    {
        $runner = $configDir . '/runner.php';
        file_put_contents($runner, <<<'PHP'
        <?php
        define('MUBLO_CONFIG_PATH', $argv[1]);
        require $argv[2];
        echo (new Mublo\Core\Crypto\PasswordHasher())->getCost();
        PHP);

        $command = escapeshellarg(PHP_BINARY)
            . ' ' . escapeshellarg($runner)
            . ' ' . escapeshellarg($configDir)
            . ' ' . escapeshellarg(MUBLO_ROOT_PATH . '/vendor/autoload.php')
            . ' 2>&1';

        exec($command, $lines, $status);

        return [$status, trim(implode("\n", $lines))];
    }
}
