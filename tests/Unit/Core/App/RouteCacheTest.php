<?php

namespace Tests\Unit\Core\App;

use FastRoute\BadRouteException;
use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use Mublo\Core\App\RouteCache;
use Mublo\Core\Error\ErrorHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RouteCacheTest extends TestCase
{
    private string $root;
    private string $directory;
    private RouteCache $cache;
    private const DOMAIN = 'cache-test.local:9360';
    private const SIGNATURE = '123456abcdef';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/mublo-route-race-' . bin2hex(random_bytes(8));
        mkdir($this->root);
        $this->directory = $this->root . '/routes';
        $this->cache = new RouteCache($this->directory);
        $handler = (new \ReflectionClass(ErrorHandler::class))->newInstanceWithoutConstructor();
        set_error_handler([$handler, 'handleError']);
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    private function build(string $signature = self::SIGNATURE, string $domain = self::DOMAIN): Dispatcher
    {
        return $this->cache->getDispatcher($domain, $signature, static function (RouteCollector $r): void {
            $r->addRoute('GET', '/', 'home');
            $r->addRoute('GET', '/items/{id:\d+}', 'item');
        });
    }

    private function path(): string
    {
        return $this->cache->filePath(self::DOMAIN, self::SIGNATURE);
    }

    private function assertRoutes(Dispatcher $dispatcher): void
    {
        $this->assertSame([Dispatcher::FOUND, 'home', []], $dispatcher->dispatch('GET', '/'));
        $this->assertSame([Dispatcher::FOUND, 'item', ['id' => '7']], $dispatcher->dispatch('GET', '/items/7'));
        $this->assertSame([Dispatcher::NOT_FOUND], $dispatcher->dispatch('GET', '/missing'));
    }

    public function testFreshCacheAvoidsDefinitionsAndPreservesStaticAndVariableRoutes(): void
    {
        $this->assertRoutes($this->build());
        $this->assertRoutes($this->cache->getDispatcher(self::DOMAIN, self::SIGNATURE, function (): void {
            $this->fail('유효한 캐시에서 라우트를 다시 구성하면 안 된다');
        }));
    }

    public function testExpiredFileRemainsUntilReplacementIsReady(): void
    {
        $this->build();
        $old = file_get_contents($this->path());
        touch($this->path(), time() - 3601);
        $dispatcher = $this->cache->getDispatcher(self::DOMAIN, self::SIGNATURE, function (RouteCollector $r) use ($old): void {
            $this->assertSame($old, file_get_contents($this->path()));
            $r->addRoute('GET', '/', 'new-home');
        });
        $this->assertSame('new-home', $dispatcher->dispatch('GET', '/')[1]);
        $this->assertGreaterThan(time() - 10, filemtime($this->path()));
        $this->assertSame([], glob($this->directory . '/.route-*'));
        $cached = $this->cache->getDispatcher(self::DOMAIN, self::SIGNATURE, function (): void {
            $this->fail('교체된 캐시가 다음 요청에서도 사용되어야 한다');
        });
        $this->assertSame('new-home', $cached->dispatch('GET', '/')[1]);
    }

    #[DataProvider('brokenCaches')]
    public function testBrokenCacheIsRebuilt(string $contents): void
    {
        $this->expectOutputString('');
        mkdir($this->directory);
        file_put_contents($this->path(), $contents);
        $this->assertRoutes($this->build());
        $this->assertRoutes($this->build());
    }

    public static function brokenCaches(): array
    {
        return [['<?php return ['], [''], ['<?ph'], ['<?php return false;'], ['<?php return [];'], ['<?php return [42, []];'], ['<?php return [[], ["GET" => [false]]];']];
    }

    public function testCacheDirectoryFailureStillDispatchesFreshRoutes(): void
    {
        file_put_contents($this->directory, 'not a directory');
        $this->assertRoutes($this->build());
    }

    public function testWriteFailureKeepsOldSignatureAndRemovesTemporaryFile(): void
    {
        $oldSignature = 'abcdef123456';
        $this->build($oldSignature);
        mkdir($this->path()); // 파일로 교체할 수 없는 저장 경로
        $this->assertRoutes($this->build());
        $this->assertFileExists($this->cache->filePath(self::DOMAIN, $oldSignature));
        $this->assertSame([], glob($this->directory . '/.route-*'));
    }

    public function testNewSignatureNeverFallsBackToOldRoutes(): void
    {
        $this->build();
        $dispatcher = $this->cache->getDispatcher(self::DOMAIN, 'abcdef123456', static function (RouteCollector $r): void {
            $r->addRoute('GET', '/new', 'new');
        });
        $this->assertSame([Dispatcher::NOT_FOUND], $dispatcher->dispatch('GET', '/'));
        $this->assertSame('new', $dispatcher->dispatch('GET', '/new')[1]);
        $this->assertFileDoesNotExist($this->path());
    }

    public function testDefinitionFailurePropagatesAndReleasesLock(): void
    {
        try {
            $this->cache->getDispatcher(self::DOMAIN, self::SIGNATURE, static function (RouteCollector $r): void {
                $r->addRoute('GET', '/', 'one');
                $r->addRoute('GET', '/', 'two');
            });
            $this->fail('중복 라우트 오류가 감춰졌다');
        } catch (BadRouteException) {
            $this->assertFileDoesNotExist($this->path());
        }
        $this->assertRoutes($this->build());
    }

    public function testDomainClearPreservesOtherDomainsAndLockInode(): void
    {
        $this->build();
        $this->build(self::SIGNATURE, self::DOMAIN . '.au');
        $lock = $this->directory . '/cache-test.local_9360.lock';
        $inode = fileinode($lock);
        $this->assertTrue($this->cache->clear(self::DOMAIN));
        $this->assertTrue($this->cache->clear(self::DOMAIN));
        $this->assertFileDoesNotExist($this->path());
        $this->assertFileExists($this->cache->filePath(self::DOMAIN . '.au', self::SIGNATURE));
        $this->assertSame($inode, fileinode($lock));
        $this->assertSame(1, $this->cache->clearAll());
        $this->assertSame(0, $this->cache->clearAll());
        $this->assertFileExists($lock);
    }

    #[DataProvider('cacheStates')]
    public function testConcurrentRequestsBuildOnlyOnce(bool $expired): void
    {
        if ($expired) {
            $this->build();
            touch($this->path(), time() - 3601);
        }
        $workers = [];
        try {
            for ($i = 0; $i < 6; $i++) {
                $workers[] = $this->worker('get');
            }
            foreach ($workers as $worker) {
                fwrite($worker['pipes'][0], "go\n");
            }
            foreach ($workers as &$worker) {
                $result = json_decode($this->line($worker['pipes'][1]), true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame([[Dispatcher::FOUND, 'home', []], [Dispatcher::FOUND, 'item', ['id' => '7']]], $result);
                $this->finish($worker);
            }
            unset($worker);
            $this->assertSame("build\n", file_get_contents($this->root . '/builds'));
            $this->assertRoutes($this->build());
        } finally {
            foreach ($workers as &$worker) {
                $this->stop($worker);
            }
        }
    }

    public static function cacheStates(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('clearModes')]
    public function testClearWaitsForInFlightGeneration(string $mode): void
    {
        $builder = $this->worker('hold');
        $clearer = null;
        try {
            fwrite($builder['pipes'][0], "go\n");
            $this->assertSame('building', $this->line($builder['pipes'][1]));
            $clearer = $this->worker($mode);
            fwrite($clearer['pipes'][0], "go\n");
            $this->assertSame('clearing', $this->line($clearer['pipes'][1]));
            $read = [$clearer['pipes'][1]];
            $write = $except = [];
            $this->assertSame(0, stream_select($read, $write, $except, 0, 100000), '삭제가 생성 잠금을 기다려야 한다');
            fwrite($builder['pipes'][0], "finish\n");
            $this->line($builder['pipes'][1]);
            $this->finish($builder);
            $result = json_decode($this->line($clearer['pipes'][1]), true);
            $this->assertSame($mode === 'clear' ? true : 1, $result);
            $this->finish($clearer);
            $this->assertFileDoesNotExist($this->path());
            $this->assertRoutes($this->build());
        } finally {
            $this->stop($builder);
            if ($clearer !== null) {
                $this->stop($clearer);
            }
        }
    }

    public static function clearModes(): array
    {
        return [['clear'], ['clear-all']];
    }

    public function testDeletionWaitsUntilCacheReadHasFinished(): void
    {
        $this->build();
        $data = include $this->path();
        file_put_contents($this->path(), '<?php fwrite(STDOUT, "reading\\n"); fgets(STDIN); return '
            . var_export($data, true) . ';');
        $reader = $this->worker('get');
        $clearer = null;
        try {
            fwrite($reader['pipes'][0], "go\n");
            $this->assertSame('reading', $this->line($reader['pipes'][1]));
            $clearer = $this->worker('clear');
            fwrite($clearer['pipes'][0], "go\n");
            $this->assertSame('clearing', $this->line($clearer['pipes'][1]));
            $read = [$clearer['pipes'][1]];
            $write = $except = [];
            $this->assertSame(0, stream_select($read, $write, $except, 0, 100000));
            fwrite($reader['pipes'][0], "finish\n");
            $result = json_decode($this->line($reader['pipes'][1]), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('home', $result[0][1]);
            $this->finish($reader);
            $this->assertSame('true', $this->line($clearer['pipes'][1]));
            $this->finish($clearer);
            $this->assertFileDoesNotExist($this->path());
            $this->assertFileDoesNotExist($this->root . '/builds');
        } finally {
            $this->stop($reader);
            if ($clearer !== null) $this->stop($clearer);
        }
    }

    private function worker(string $mode): array
    {
        $process = proc_open([
            PHP_BINARY, dirname(__DIR__, 3) . '/Fixtures/route-cache-worker.php',
            $this->directory, $mode, self::DOMAIN, self::SIGNATURE, $this->root . '/builds',
        ], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        $worker = ['process' => $process, 'pipes' => $pipes];
        try {
            $this->assertSame('ready', $this->line($pipes[1]));
            return $worker;
        } catch (\Throwable $e) {
            $this->stop($worker);
            throw $e;
        }
    }

    private function line($stream): string
    {
        stream_set_timeout($stream, 10);
        $line = fgets($stream);
        $this->assertNotFalse($line, '자식 프로세스 응답 시간 초과 또는 조기 종료');
        return trim($line);
    }

    private function finish(array &$worker): void
    {
        fclose($worker['pipes'][0]);
        fclose($worker['pipes'][1]);
        $error = stream_get_contents($worker['pipes'][2]);
        fclose($worker['pipes'][2]);
        $exit = proc_close($worker['process']);
        $worker = [];
        $this->assertSame('', $error);
        $this->assertSame(0, $exit);
    }

    private function stop(array &$worker): void
    {
        if ($worker === []) {
            return;
        }
        proc_terminate($worker['process']);
        foreach ($worker['pipes'] as $pipe) {
            if (is_resource($pipe)) fclose($pipe);
        }
        proc_close($worker['process']);
        $worker = [];
    }
}
