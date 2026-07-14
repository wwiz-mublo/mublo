<?php
/**
 * BoardReport 라우트
 *
 * 접두사는 코어가 플러그인 이름에서 자동 생성한다:
 *   프론트  /board/report/...
 *   관리자  /admin/board/report/...
 */

use Mublo\Core\App\PrefixedRouteCollector;
use Mublo\Core\Middleware\AdminMiddleware;
use Mublo\Packages\Board\Plugins\BoardReport\Controller\Admin\BoardReportAdminController;
use Mublo\Packages\Board\Plugins\BoardReport\Controller\Front\BoardReportController;

return function (PrefixedRouteCollector $r): void {
    // 프론트 — 신고 접수
    $r->addRoute('GET', '/form', [
        'controller' => BoardReportController::class,
        'method'     => 'form',
    ]);
    $r->addRoute('POST', '/submit', [
        'controller' => BoardReportController::class,
        'method'     => 'submit',
    ]);

    // 관리자 — 신고 관리
    $r->addRoute('GET', '/admin/list', [
        'controller' => BoardReportAdminController::class,
        'method'     => 'list',
        'middleware' => [AdminMiddleware::class],
    ]);
    $r->addRoute('POST', '/admin/status', [
        'controller' => BoardReportAdminController::class,
        'method'     => 'status',
        'middleware' => [AdminMiddleware::class],
    ]);
    $r->addRoute('POST', '/admin/blind', [
        'controller' => BoardReportAdminController::class,
        'method'     => 'blind',
        'middleware' => [AdminMiddleware::class],
    ]);
    $r->addRoute('POST', '/admin/delete-article', [
        'controller' => BoardReportAdminController::class,
        'method'     => 'deleteArticle',
        'middleware' => [AdminMiddleware::class],
    ]);
    $r->addRoute('POST', '/admin/bulk', [
        'controller' => BoardReportAdminController::class,
        'method'     => 'bulk',
        'middleware' => [AdminMiddleware::class],
    ]);
};
