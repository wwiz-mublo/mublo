<?php
declare(strict_types=1);

use Mublo\Core\App\PrefixedRouteCollector;
use Mublo\Core\Middleware\AdminMiddleware;
use Mublo\Plugin\SendonSms\Controller\Admin\SendonSmsController;

return function (PrefixedRouteCollector $r): void {
    $r->addRoute('GET', '/admin', [
        'controller' => SendonSmsController::class,
        'method' => 'index',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('GET', '/admin/settings', [
        'controller' => SendonSmsController::class,
        'method' => 'settings',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/settings/save', [
        'controller' => SendonSmsController::class,
        'method' => 'saveSettings',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('GET', '/admin/settings/sendon-balance', [
        'controller' => SendonSmsController::class,
        'method' => 'sendonBalance',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('GET', '/admin/channels', [
        'controller' => SendonSmsController::class,
        'method' => 'channels',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('GET', '/admin/channels/senders', [
        'controller' => SendonSmsController::class,
        'method' => 'fetchSenders',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/channels/save', [
        'controller' => SendonSmsController::class,
        'method' => 'saveChannel',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/channels/delete', [
        'controller' => SendonSmsController::class,
        'method' => 'deleteChannel',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('GET', '/admin/channels/{id}/templates', [
        'controller' => SendonSmsController::class,
        'method' => 'templatesByChannel',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/templates/save', [
        'controller' => SendonSmsController::class,
        'method' => 'saveTemplate',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/templates/delete', [
        'controller' => SendonSmsController::class,
        'method' => 'deleteTemplate',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/send', [
        'controller' => SendonSmsController::class,
        'method' => 'send',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('GET', '/admin/history', [
        'controller' => SendonSmsController::class,
        'method' => 'history',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/install', [
        'controller' => SendonSmsController::class,
        'method' => 'install',
        'middleware' => [AdminMiddleware::class],
    ]);
};
