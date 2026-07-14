<?php

use Mublo\Core\App\PrefixedRouteCollector;
use Mublo\Core\Middleware\AdminMiddleware;
use Mublo\Plugin\EmailNotify\Controller\Admin\EmailNotifyController;

return function (PrefixedRouteCollector $r): void {
    $r->addRoute('GET', '/admin', [
        'controller' => EmailNotifyController::class,
        'method' => 'index',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('GET', '/admin/settings', [
        'controller' => EmailNotifyController::class,
        'method' => 'settings',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/settings/save', [
        'controller' => EmailNotifyController::class,
        'method' => 'saveSettings',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('GET', '/admin/templates', [
        'controller' => EmailNotifyController::class,
        'method' => 'templates',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('GET', '/admin/templates/create', [
        'controller' => EmailNotifyController::class,
        'method' => 'templateForm',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('GET', '/admin/templates/edit/{id}', [
        'controller' => EmailNotifyController::class,
        'method' => 'templateForm',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/templates/save', [
        'controller' => EmailNotifyController::class,
        'method' => 'saveTemplate',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/templates/delete', [
        'controller' => EmailNotifyController::class,
        'method' => 'deleteTemplate',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/templates/test', [
        'controller' => EmailNotifyController::class,
        'method' => 'sendTest',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/images/upload', [
        'controller' => EmailNotifyController::class,
        'method' => 'uploadImage',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('GET', '/admin/images', [
        'controller' => EmailNotifyController::class,
        'method' => 'listImages',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/images/delete', [
        'controller' => EmailNotifyController::class,
        'method' => 'deleteImage',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('GET', '/admin/history', [
        'controller' => EmailNotifyController::class,
        'method' => 'history',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/install', [
        'controller' => EmailNotifyController::class,
        'method' => 'install',
        'middleware' => [AdminMiddleware::class],
    ]);
};
