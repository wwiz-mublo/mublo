<?php
declare(strict_types=1);

use Mublo\Core\App\PrefixedRouteCollector;
use Mublo\Core\Middleware\AdminMiddleware;
use Mublo\Plugin\SendonTalk\Controller\Admin\SendonTalkController;

return function (PrefixedRouteCollector $r): void {
    $mw = [AdminMiddleware::class];
    $ctrl = SendonTalkController::class;

    // 설정
    $r->addRoute('GET',  '/admin/settings',      ['controller' => $ctrl, 'method' => 'settings',     'middleware' => $mw]);
    $r->addRoute('POST', '/admin/settings/save',  ['controller' => $ctrl, 'method' => 'saveSettings', 'middleware' => $mw]);

    // 채널/템플릿 (마스터-디테일)
    $r->addRoute('GET',  '/admin/channels',             ['controller' => $ctrl, 'method' => 'channels',          'middleware' => $mw]);
    $r->addRoute('GET',  '/admin/channels/fetch',       ['controller' => $ctrl, 'method' => 'fetchProfiles',     'middleware' => $mw]);
    $r->addRoute('POST', '/admin/channels/save',        ['controller' => $ctrl, 'method' => 'saveChannel',       'middleware' => $mw]);
    $r->addRoute('POST', '/admin/channels/delete',      ['controller' => $ctrl, 'method' => 'deleteChannel',     'middleware' => $mw]);
    $r->addRoute('GET',  '/admin/templates/list',       ['controller' => $ctrl, 'method' => 'templatesByChannel','middleware' => $mw]);
    $r->addRoute('POST', '/admin/templates/save',       ['controller' => $ctrl, 'method' => 'saveTemplate',      'middleware' => $mw]);
    $r->addRoute('POST', '/admin/templates/delete',     ['controller' => $ctrl, 'method' => 'deleteTemplate',    'middleware' => $mw]);

    // 카카오 심사
    $r->addRoute('POST', '/admin/templates/register-kakao',    ['controller' => $ctrl, 'method' => 'registerKakao',    'middleware' => $mw]);
    $r->addRoute('GET',  '/admin/templates/check-kakao-status',['controller' => $ctrl, 'method' => 'checkKakaoStatus', 'middleware' => $mw]);
    $r->addRoute('POST', '/admin/templates/sync',              ['controller' => $ctrl, 'method' => 'syncTemplates',    'middleware' => $mw]);

    // 발송 이력
    $r->addRoute('GET', '/admin/history', ['controller' => $ctrl, 'method' => 'history', 'middleware' => $mw]);

    // 설치
    $r->addRoute('POST', '/admin/install', ['controller' => $ctrl, 'method' => 'install', 'middleware' => $mw]);
};
