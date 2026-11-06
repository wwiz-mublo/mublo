<?php
declare(strict_types=1);

use Mublo\Core\App\PrefixedRouteCollector;
use Mublo\Core\Middleware\AdminMiddleware;
use Mublo\Plugin\Innopay\Controller\Admin\LogController;
use Mublo\Plugin\Innopay\Controller\Admin\SettingsController;
use Mublo\Plugin\Innopay\Controller\Front\CallbackController;

return function (PrefixedRouteCollector $r): void {

    // Admin: /admin/innopay/...
    $adminMiddleware = [AdminMiddleware::class];

    $r->addRoute('GET', '/admin/settings', [
        'controller' => SettingsController::class,
        'method'     => 'index',
        'middleware' => $adminMiddleware,
    ]);

    $r->addRoute('POST', '/admin/settings', [
        'controller' => SettingsController::class,
        'method'     => 'save',
        'middleware' => $adminMiddleware,
    ]);

    $r->addRoute('POST', '/admin/migrate', [
        'controller' => SettingsController::class,
        'method'     => 'runMigration',
        'middleware' => $adminMiddleware,
    ]);

    $r->addRoute('GET', '/admin/logs', [
        'controller' => LogController::class,
        'method'     => 'index',
        'middleware' => $adminMiddleware,
    ]);

    // Front: /innopay/callback/return — 결제창 인증이 끝나면 이노페이 SDK 가 최상위 창을
    // GET 으로 이리 보낸다(PC 레이어·모바일 전체화면 공통). 규격 변화에 대비해 POST 도 받는다.
    foreach (['GET', 'POST'] as $method) {
        $r->addRoute($method, '/callback/return', [
            'controller' => CallbackController::class,
            'method'     => 'returnUrl',
        ]);
    }

    // Front: /innopay/callback/log — 결제창 열림·닫힘·스크립트 오류를 브라우저가 알린다.
    $r->addRoute('POST', '/callback/log', [
        'controller' => CallbackController::class,
        'method'     => 'clientLog',
    ]);
};
