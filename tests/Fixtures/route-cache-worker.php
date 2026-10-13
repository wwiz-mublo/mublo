<?php
// 프로세스 간 경쟁 테스트 전용. 실제 Router 오류 처리기를 사용한다.
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use FastRoute\RouteCollector;
use Mublo\Core\App\RouteCache;
use Mublo\Core\Error\ErrorHandler;

$handler = (new ReflectionClass(ErrorHandler::class))->newInstanceWithoutConstructor();
set_error_handler([$handler, 'handleError']);
[$script, $directory, $mode, $domain, $signature, $counter] = $argv;
fwrite(STDOUT, "ready\n");
fgets(STDIN);
try {
    $cache = new RouteCache($directory);
    if ($mode === 'clear' || $mode === 'clear-all') {
        fwrite(STDOUT, "clearing\n");
        $result = $mode === 'clear' ? $cache->clear($domain) : $cache->clearAll();
    } else {
        $dispatcher = $cache->getDispatcher($domain, $signature, static function (RouteCollector $r) use ($mode, $counter): void {
            file_put_contents($counter, "build\n", FILE_APPEND | LOCK_EX);
            if ($mode === 'hold') {
                fwrite(STDOUT, "building\n");
                fgets(STDIN);
            } else {
                usleep(20000);
            }
            $r->addRoute('GET', '/', 'home');
            $r->addRoute('GET', '/items/{id:\d+}', 'item');
        });
        $result = [$dispatcher->dispatch('GET', '/'), $dispatcher->dispatch('GET', '/items/7')];
    }
    fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR) . "\n");
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . "\n");
    exit(1);
}
