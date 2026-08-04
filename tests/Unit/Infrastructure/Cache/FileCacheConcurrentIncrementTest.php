<?php

namespace Tests\Unit\Infrastructure\Cache;

use PHPUnit\Framework\TestCase;

/**
 * FileCache::increment() 의 원자성을 실제 동시 프로세스로 검증한다.
 *
 * 예전 구현은 get() 후 set() 이라, 동시에 들어온 요청이 같은 값을 읽고 같은 값을
 * 써서 증가분이 유실됐다. RateLimiter 가 이 위에 서 있으므로 유실은 곧 제한 우회다.
 * 한 프로세스 안의 순차 호출로는 드러나지 않는 성질이라 프로세스를 여럿 띄운다.
 */
class FileCacheConcurrentIncrementTest extends TestCase
{
    private string $cacheDir;
    private string $workDir;

    protected function setUp(): void
    {
        parent::setUp();

        $base = sys_get_temp_dir() . '/mublo_cache_conc_' . bin2hex(random_bytes(6));

        // 캐시 디렉터리와 작업 파일을 분리한다.
        //
        // FileCache 의 확률적 정리는 캐시 트리의 모든 .php 를 include 해 만료를
        // 판정하고, 배열이 아니면 지운다. 실행 스크립트를 캐시 디렉터리 안에 두면
        // 그 대상이 되어 테스트 도중 사라진다 — 아직 시작하지 못한 자식들이 통째로
        // 실패하고, 개수가 프로세스 단위로 어긋난다(CI 에서 실제로 발생했다).
        $this->cacheDir = $base . '/cache';
        $this->workDir = $base . '/work';
        mkdir($this->cacheDir, 0755, true);
        mkdir($this->workDir, 0755, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->removeDirectory(dirname($this->cacheDir));
    }

    public function testConcurrentIncrementsDoNotLoseCounts(): void
    {
        $processes = 8;
        $perProcess = 25;

        // 자식이 실패 횟수를 남긴다. 이게 없으면 개수만 어긋난 채 원인을 알 수 없다 —
        // 잠금을 못 잡아 increment 가 false 를 돌려준 것인지, 잠금은 잡았는데 집계가
        // 어긋난 것인지는 전혀 다른 문제다.
        $runner = $this->workDir . '/runner.php';
        file_put_contents($runner, <<<'PHP'
        <?php
        define('MUBLO_ROOT_PATH', $argv[3]);
        require $argv[3] . '/vendor/autoload.php';

        $cache = new Mublo\Infrastructure\Cache\FileCache($argv[1], 3600);

        $failed = 0;
        for ($i = 0; $i < (int) $argv[2]; $i++) {
            if ($cache->increment('shared-counter', 1, 3600) === false) {
                $failed++;
            }
        }

        // 캐시 디렉터리($argv[1]) 가 아니라 작업 디렉터리($argv[4]) 에 남긴다.
        file_put_contents($argv[4] . '/failed_' . getmypid(), (string) $failed);
        PHP);

        // 자식 출력은 파이프가 아니라 파일로 받는다. 파이프로 받으면 자식이 경고를
        // 쏟아낼 때 버퍼가 차서 부모·자식이 서로를 기다리는 교착이 난다.
        $handles = [];
        for ($i = 0; $i < $processes; $i++) {
            $command = escapeshellarg(PHP_BINARY)
                . ' ' . escapeshellarg($runner)
                . ' ' . escapeshellarg($this->cacheDir)
                . ' ' . escapeshellarg((string) $perProcess)
                . ' ' . escapeshellarg(MUBLO_ROOT_PATH)
                . ' ' . escapeshellarg($this->workDir);

            $log = $this->workDir . '/child' . $i . '.log';
            $handle = proc_open(
                $command,
                [1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']],
                $pipes
            );

            if (is_resource($handle)) {
                $handles[] = $handle;
            }
        }

        $this->assertCount($processes, $handles, '자식 프로세스를 띄우지 못했다');

        foreach ($handles as $handle) {
            proc_close($handle);
        }

        $cache = new \Mublo\Infrastructure\Cache\FileCache($this->cacheDir, 3600);

        // 잠금을 잡지 못해 increment 가 포기한 횟수. 0 이 아니면 유실이 아니라
        // 잠금 획득 자체가 실패한 것이므로 원인이 다르다.
        $declined = 0;
        foreach (glob($this->workDir . '/failed_*') ?: [] as $file) {
            $declined += (int) file_get_contents($file);
        }

        $this->assertSame(0, $declined, "increment 가 {$declined}회 잠금을 잡지 못했다" . $this->childOutput());

        // 증가분이 하나도 유실되지 않아야 한다.
        $this->assertSame(
            $processes * $perProcess,
            $cache->get('shared-counter'),
            '동시 증가에서 카운트가 유실됐다' . $this->childOutput()
        );
    }

    /** 실패 메시지에 자식 프로세스가 남긴 경고·오류를 붙인다. */
    private function childOutput(): string
    {
        $lines = [];
        foreach (glob($this->workDir . '/child*.log') ?: [] as $log) {
            $content = trim((string) file_get_contents($log));
            if ($content !== '') {
                $lines[] = basename($log) . ': ' . $content;
            }
        }

        return $lines === [] ? '' : "\n자식 출력:\n" . implode("\n", $lines);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($dir);
    }
}
