<?php
declare(strict_types=1);

use Mublo\Core\App\PrefixedRouteCollector;
use Mublo\Core\Middleware\AdminMiddleware;
use Mublo\Plugin\VisitorStats\Controller\VisitorStatsController;

return function (PrefixedRouteCollector $r): void {

    // 관리자 화면
    $r->addRoute('GET', '/admin/dashboard', [
        'controller' => VisitorStatsController::class,
        'method'     => 'dashboard',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('GET', '/admin/realtime', [
        'controller' => VisitorStatsController::class,
        'method'     => 'realtime',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('GET', '/admin/pages', [
        'controller' => VisitorStatsController::class,
        'method'     => 'pages',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('GET', '/admin/referrers', [
        'controller' => VisitorStatsController::class,
        'method'     => 'referrers',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('GET', '/admin/environment', [
        'controller' => VisitorStatsController::class,
        'method'     => 'environment',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('GET', '/admin/campaigns', [
        'controller' => VisitorStatsController::class,
        'method'     => 'campaigns',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('GET', '/admin/conversions', [
        'controller' => VisitorStatsController::class,
        'method'     => 'conversions',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('GET', '/admin/conversion-stats', [
        'controller' => VisitorStatsController::class,
        'method'     => 'conversionStats',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('GET', '/admin/campaign-settings', [
        'controller' => VisitorStatsController::class,
        'method'     => 'campaignSettings',
        'middleware' => [AdminMiddleware::class],
    ]);

    // API
    $r->addRoute('POST', '/admin/api/summary', [
        'controller' => VisitorStatsController::class,
        'method'     => 'apiSummary',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/api/trend', [
        'controller' => VisitorStatsController::class,
        'method'     => 'apiTrend',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/api/hourly', [
        'controller' => VisitorStatsController::class,
        'method'     => 'apiHourly',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/api/realtime', [
        'controller' => VisitorStatsController::class,
        'method'     => 'apiRealtime',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/api/ip-logs', [
        'controller' => VisitorStatsController::class,
        'method'     => 'apiIpLogs',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/api/pages', [
        'controller' => VisitorStatsController::class,
        'method'     => 'apiPages',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/api/referrers', [
        'controller' => VisitorStatsController::class,
        'method'     => 'apiReferrers',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/api/environment', [
        'controller' => VisitorStatsController::class,
        'method'     => 'apiEnvironment',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/api/campaigns', [
        'controller' => VisitorStatsController::class,
        'method'     => 'apiCampaigns',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/api/campaign-trend', [
        'controller' => VisitorStatsController::class,
        'method'     => 'apiCampaignTrend',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/api/campaign-key/create', [
        'controller' => VisitorStatsController::class,
        'method'     => 'apiCampaignKeyCreate',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/api/campaign-key/update', [
        'controller' => VisitorStatsController::class,
        'method'     => 'apiCampaignKeyUpdate',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/api/campaign-key/delete', [
        'controller' => VisitorStatsController::class,
        'method'     => 'apiCampaignKeyDelete',
        'middleware' => [AdminMiddleware::class],
    ]);

    // 전환 API
    $r->addRoute('POST', '/admin/api/campaign-summary', [
        'controller' => VisitorStatsController::class,
        'method'     => 'apiCampaignSummary',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/api/conversion-stats', [
        'controller' => VisitorStatsController::class,
        'method'     => 'apiConversionStats',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/api/conversions', [
        'controller' => VisitorStatsController::class,
        'method'     => 'apiConversions',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/api/dashboard-conversions', [
        'controller' => VisitorStatsController::class,
        'method'     => 'apiDashboardConversions',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRoute('POST', '/admin/api/purge', [
        'controller' => VisitorStatsController::class,
        'method'     => 'apiPurge',
        'middleware' => [AdminMiddleware::class],
    ]);

    // 설치
    $r->addRoute('POST', '/admin/install', [
        'controller' => VisitorStatsController::class,
        'method'     => 'install',
        'middleware' => [AdminMiddleware::class],
    ]);
};
