<?php

use Mublo\Core\App\PrefixedRouteCollector;
use Mublo\Core\Middleware\AdminMiddleware;
use Mublo\Plugin\Widget\Controller\WidgetController;

return function (PrefixedRouteCollector $r): void {
    // 관리자: 목록 (설정 + 아이템 통합)
    $r->addRoute('GET', '/admin/list', [
        'controller' => WidgetController::class,
        'method' => 'index',
        'middleware' => [AdminMiddleware::class],
    ]);

    // 설정 저장
    $r->addRoute('POST', '/admin/config/save', [
        'controller' => WidgetController::class,
        'method' => 'saveConfig',
        'middleware' => [AdminMiddleware::class],
    ]);

    // 아이템 저장 (생성/수정)
    $r->addRoute('POST', '/admin/store', [
        'controller' => WidgetController::class,
        'method' => 'store',
        'middleware' => [AdminMiddleware::class],
    ]);

    // 아이템 삭제
    $r->addRoute('POST', '/admin/{id}/delete', [
        'controller' => WidgetController::class,
        'method' => 'delete',
        'middleware' => [AdminMiddleware::class],
    ]);

    // 일괄 삭제
    $r->addRoute('POST', '/admin/listDelete', [
        'controller' => WidgetController::class,
        'method' => 'listDelete',
        'middleware' => [AdminMiddleware::class],
    ]);

    // 정렬
    $r->addRoute('POST', '/admin/sort', [
        'controller' => WidgetController::class,
        'method' => 'sort',
        'middleware' => [AdminMiddleware::class],
    ]);

    // 설치
    $r->addRoute('POST', '/admin/install', [
        'controller' => WidgetController::class,
        'method' => 'install',
        'middleware' => [AdminMiddleware::class],
    ]);

    // 프론트 API: 활성 위젯 목록
    $r->addRoute('GET', '/api/active', [
        'controller' => WidgetController::class,
        'method' => 'activeWidgets',
    ]);
};
