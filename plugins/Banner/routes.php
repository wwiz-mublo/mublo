<?php
/**
 * Banner Plugin Routes
 *
 * PrefixedRouteCollector를 통해 자동으로 접두사가 적용됩니다.
 *
 * URL 규칙:
 * - Admin: /admin/{plugin_name}/... → /admin/banner/...
 */

use Mublo\Core\App\PrefixedRouteCollector;
use Mublo\Core\Middleware\AdminMiddleware;
use Mublo\Plugin\Banner\Controller\BannerController;

return function (PrefixedRouteCollector $r): void {

    // 배너 목록
    $r->addRoute('GET', '/admin/list', [
        'controller' => BannerController::class,
        'method'     => 'index',
        'middleware' => [AdminMiddleware::class],
    ]);

    // 배너 생성 폼
    $r->addRoute('GET', '/admin/create', [
        'controller' => BannerController::class,
        'method'     => 'create',
        'middleware' => [AdminMiddleware::class],
    ]);

    // 배너 수정 폼
    $r->addRoute('GET', '/admin/{id:\d+}/edit', [
        'controller' => BannerController::class,
        'method'     => 'edit',
        'middleware' => [AdminMiddleware::class],
    ]);

    // 배너 저장 (생성/수정)
    $r->addRoute('POST', '/admin/store', [
        'controller' => BannerController::class,
        'method'     => 'store',
        'middleware' => [AdminMiddleware::class],
    ]);

    // 배너 일괄 삭제
    $r->addRoute('POST', '/admin/listDelete', [
        'controller' => BannerController::class,
        'method'     => 'listDelete',
        'middleware' => [AdminMiddleware::class],
    ]);

    // 배너 삭제
    $r->addRoute('POST', '/admin/{id:\d+}/delete', [
        'controller' => BannerController::class,
        'method'     => 'delete',
        'middleware' => [AdminMiddleware::class],
    ]);

    // 순서 변경 (AJAX)
    $r->addRoute('POST', '/admin/sort', [
        'controller' => BannerController::class,
        'method'     => 'sort',
        'middleware' => [AdminMiddleware::class],
    ]);

    // 블록 에디터용 배너 목록 (AJAX)
    $r->addRoute(['GET', 'POST'], '/admin/block-items', [
        'controller' => BannerController::class,
        'method'     => 'blockItems',
        'middleware' => [AdminMiddleware::class],
    ]);

    // 플러그인 설치 (마이그레이션 실행)
    $r->addRoute('POST', '/admin/install', [
        'controller' => BannerController::class,
        'method'     => 'install',
        'middleware' => [AdminMiddleware::class],
    ]);
};
