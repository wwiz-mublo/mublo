<?php
/**
 * SnsLogin Plugin Routes
 *
 * Front URL: /sns-login/...
 * Admin URL: /admin/sns-login/...
 */

use Mublo\Core\App\PrefixedRouteCollector;
use Mublo\Core\Middleware\AdminMiddleware;
use Mublo\Core\Middleware\AuthMiddleware;

return function (PrefixedRouteCollector $r): void {

    // =========================================================
    // Front Routes
    // =========================================================

    // OAuth2 시작 → 제공자 페이지로 리다이렉트
    $r->addRoute('GET', '/auth/{provider}', [
        'controller' => \Mublo\Plugin\SnsLogin\Controller\Front\SnsAuthController::class,
        'method'     => 'start',
    ]);

    // OAuth2 콜백 처리
    $r->addRoute('GET', '/callback/{provider}', [
        'controller' => \Mublo\Plugin\SnsLogin\Controller\Front\SnsAuthController::class,
        'method'     => 'callback',
    ]);

    // SNS 연결 해제
    $r->addRoute('POST', '/unlink', [
        'controller' => \Mublo\Plugin\SnsLogin\Controller\Front\SnsAuthController::class,
        'method'     => 'unlink',
        'middleware' => [AuthMiddleware::class],
    ]);

    // 신규 가입 프로필 완성 (auto_register=OFF 시)
    $r->addRoute('GET', '/profile/complete', [
        'controller' => \Mublo\Plugin\SnsLogin\Controller\Front\SnsProfileController::class,
        'method'     => 'form',
    ]);

    $r->addRoute('POST', '/profile/complete', [
        'controller' => \Mublo\Plugin\SnsLogin\Controller\Front\SnsProfileController::class,
        'method'     => 'store',
    ]);

    // =========================================================
    // Admin Routes
    // =========================================================

    $r->addRoute('GET', '/admin/settings', [
        'controller' => \Mublo\Plugin\SnsLogin\Controller\Admin\SettingsController::class,
        'method'     => 'index',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/settings', [
        'controller' => \Mublo\Plugin\SnsLogin\Controller\Admin\SettingsController::class,
        'method'     => 'save',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/install', [
        'controller' => \Mublo\Plugin\SnsLogin\Controller\Admin\SettingsController::class,
        'method'     => 'install',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('GET', '/admin/accounts', [
        'controller' => \Mublo\Plugin\SnsLogin\Controller\Admin\AccountsController::class,
        'method'     => 'index',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('DELETE', '/admin/accounts/{id}', [
        'controller' => \Mublo\Plugin\SnsLogin\Controller\Admin\AccountsController::class,
        'method'     => 'destroy',
        'middleware' => [AdminMiddleware::class],
    ]);
};
