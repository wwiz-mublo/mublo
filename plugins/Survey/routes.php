<?php
declare(strict_types=1);

use Mublo\Core\App\PrefixedRouteCollector;
use Mublo\Core\Middleware\AdminMiddleware;
use Mublo\Plugin\Survey\Controller\Admin\SurveyAdminController;
use Mublo\Plugin\Survey\Controller\Front\SurveyController;

return function (PrefixedRouteCollector $r): void {

    // -------------------------------------------------------------------------
    // Admin 라우트  (접두사: /admin/survey/)
    // -------------------------------------------------------------------------
    $r->addRoute('GET', '/admin/surveys', [
        'controller' => SurveyAdminController::class,
        'method'     => 'index',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('GET', '/admin/surveys/create', [
        'controller' => SurveyAdminController::class,
        'method'     => 'create',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/surveys/store', [
        'controller' => SurveyAdminController::class,
        'method'     => 'store',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('GET', '/admin/surveys/{id:\d+}/edit', [
        'controller' => SurveyAdminController::class,
        'method'     => 'edit',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/surveys/{id:\d+}/update', [
        'controller' => SurveyAdminController::class,
        'method'     => 'update',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/surveys/{id:\d+}/status', [
        'controller' => SurveyAdminController::class,
        'method'     => 'changeStatus',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/surveys/{id:\d+}/order', [
        'controller' => SurveyAdminController::class,
        'method'     => 'updateOrder',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/surveys/{id:\d+}/delete', [
        'controller' => SurveyAdminController::class,
        'method'     => 'delete',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/surveys/list-delete', [
        'controller' => SurveyAdminController::class,
        'method'     => 'listDelete',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('GET', '/admin/surveys/{id:\d+}/result', [
        'controller' => SurveyAdminController::class,
        'method'     => 'result',
        'middleware' => [AdminMiddleware::class],
    ]);

    // 프론트 스킨 설정 저장
    $r->addRoute('PUT', '/admin/skin', [
        'controller' => SurveyAdminController::class,
        'method'     => 'saveSkin',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/install', [
        'controller' => SurveyAdminController::class,
        'method'     => 'install',
        'middleware' => [AdminMiddleware::class],
    ]);

    // -------------------------------------------------------------------------
    // Front 라우트  (접두사: /survey/)
    // -------------------------------------------------------------------------
    $r->addRoute('GET', '/{id:\d+}', [
        'controller' => SurveyController::class,
        'method'     => 'show',
    ]);

    $r->addRoute('POST', '/{id:\d+}/submit', [
        'controller' => SurveyController::class,
        'method'     => 'submit',
    ]);
};
