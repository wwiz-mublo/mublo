<?php

use Mublo\Core\App\PrefixedRouteCollector;
use Mublo\Core\Middleware\AdminMiddleware;
use Mublo\Plugin\Popup\Controller\PopupController;

return function (PrefixedRouteCollector $r): void {
    // 관리자 목록
    $r->addRoute('GET', '/admin/list', [
        'controller' => PopupController::class,
        'method' => 'index',
        'middleware' => [AdminMiddleware::class],
    ]);

    // 설정 저장 (스킨)
    $r->addRoute('POST', '/admin/config/save', [
        'controller' => PopupController::class,
        'method' => 'saveConfig',
        'middleware' => [AdminMiddleware::class],
    ]);

    // 생성 폼
    $r->addRoute('GET', '/admin/create', [
        'controller' => PopupController::class,
        'method' => 'create',
        'middleware' => [AdminMiddleware::class],
    ]);

    // 수정 폼
    $r->addRoute('GET', '/admin/{id}/edit', [
        'controller' => PopupController::class,
        'method' => 'edit',
        'middleware' => [AdminMiddleware::class],
    ]);

    // 저장 (생성/수정)
    $r->addRoute('POST', '/admin/store', [
        'controller' => PopupController::class,
        'method' => 'store',
        'middleware' => [AdminMiddleware::class],
    ]);

    // 삭제
    $r->addRoute('POST', '/admin/{id}/delete', [
        'controller' => PopupController::class,
        'method' => 'delete',
        'middleware' => [AdminMiddleware::class],
    ]);

    // 일괄 삭제
    $r->addRoute('POST', '/admin/listDelete', [
        'controller' => PopupController::class,
        'method' => 'listDelete',
        'middleware' => [AdminMiddleware::class],
    ]);

    // 정렬
    $r->addRoute('POST', '/admin/sort', [
        'controller' => PopupController::class,
        'method' => 'sort',
        'middleware' => [AdminMiddleware::class],
    ]);

    // 설치
    $r->addRoute('POST', '/admin/install', [
        'controller' => PopupController::class,
        'method' => 'install',
        'middleware' => [AdminMiddleware::class],
    ]);

    // === 프론트 API ===
    // 활성 팝업 목록 (프론트에서 AJAX 호출)
    $r->addRoute('GET', '/api/active', [
        'controller' => PopupController::class,
        'method' => 'activePopups',
    ]);
};
