<?php
declare(strict_types=1);

use Mublo\Core\App\PrefixedRouteCollector;
use Mublo\Core\Middleware\AdminMiddleware;
use Mublo\Plugin\PayApp\Controller\Admin\SettingsController;
use Mublo\Plugin\PayApp\Controller\Front\CallbackController;

return function (PrefixedRouteCollector $r): void {

    // Admin: /admin/pay-app/...
    // 도메인별 자체 키 모델 — 각 사이트 관리자가 자기 도메인의 결제 설정을 관리한다.
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

    // Front: /pay-app/callback/feedback (PayApp 서버→서버 웹훅)
    $r->addRoute('POST', '/callback/feedback', [
        'controller' => CallbackController::class,
        'method'     => 'feedback',
    ]);

    // Front: /pay-app/callback/return — 결제창(팝업)이 결제 후 돌아오는 지점.
    // 페이앱이 GET 으로 보내지만 규격 변화에 대비해 POST 도 받는다.
    foreach (['GET', 'POST'] as $method) {
        $r->addRoute($method, '/callback/return', [
            'controller' => CallbackController::class,
            'method'     => 'returnUrl',
        ]);
    }
};
