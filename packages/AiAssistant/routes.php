<?php
declare(strict_types=1);

use Mublo\Core\App\PrefixedRouteCollector;
use Mublo\Core\Middleware\AdminMiddleware;
use Mublo\Core\Middleware\AuthMiddleware;
use Mublo\Packages\AiAssistant\Controller\Admin\DashboardController;
use Mublo\Packages\AiAssistant\Controller\Admin\OperationsController;
use Mublo\Packages\AiAssistant\Controller\Admin\PlatformController;
use Mublo\Packages\AiAssistant\Controller\Front\WorkspaceController;
use Mublo\Packages\AiAssistant\Controller\Api\AuthController;
use Mublo\Packages\AiAssistant\Controller\Api\AnalysisController;
use Mublo\Packages\AiAssistant\Controller\Api\CustomerSyncController;
use Mublo\Packages\AiAssistant\Controller\Api\DeviceController;
use Mublo\Packages\AiAssistant\Controller\Api\InteractionController;
use Mublo\Packages\AiAssistant\Controller\Api\MessagingPolicyController;
use Mublo\Packages\AiAssistant\Controller\Api\ScheduleController;
use Mublo\Packages\AiAssistant\Controller\Api\SubscriptionController;
use Mublo\Packages\AiAssistant\Controller\Api\WorkerController;

return function (PrefixedRouteCollector $r): void {
    $prefix = '/mublo-ai/api/v1';

    $r->addRawRoute('GET', '/workspace', [
        'controller' => WorkspaceController::class,
        'method' => 'index',
        'middleware' => [AuthMiddleware::class],
    ]);
    $r->addRawRoute('GET', '/workspace/{section}', [
        'controller' => WorkspaceController::class,
        'method' => 'index',
        'middleware' => [AuthMiddleware::class],
    ]);

    $r->addRawRoute('GET', '/admin/mublo-ai', [
        'controller' => DashboardController::class,
        'method' => 'index',
        'middleware' => [AdminMiddleware::class],
    ]);
    $r->addRawRoute('GET', '/admin/mublo-ai/dashboard', [
        'controller' => DashboardController::class,
        'method' => 'index',
        'middleware' => [AdminMiddleware::class],
    ]);
    $r->addRawRoute('GET', '/admin/mublo-ai/platform', [
        'controller' => PlatformController::class,
        'method' => 'index',
        'middleware' => [AdminMiddleware::class],
    ]);
    $r->addRawRoute('GET', '/admin/mublo-ai/platform/{section}', [
        'controller' => PlatformController::class,
        'method' => 'index',
        'middleware' => [AdminMiddleware::class],
    ]);
    $r->addRawRoute('GET', '/admin/mublo-ai/{section}', [
        'controller' => OperationsController::class,
        'method' => 'index',
        'middleware' => [AdminMiddleware::class],
    ]);

    $r->addRawRoute('POST', $prefix . '/auth/login', [
        'controller' => AuthController::class,
        'method' => 'login',
    ]);
    $r->addRawRoute('POST', $prefix . '/auth/refresh', [
        'controller' => AuthController::class,
        'method' => 'refresh',
    ]);
    $r->addRawRoute('GET', $prefix . '/auth/me', [
        'controller' => AuthController::class,
        'method' => 'me',
    ]);
    $r->addRawRoute('POST', $prefix . '/auth/logout', [
        'controller' => AuthController::class,
        'method' => 'logout',
    ]);
    $r->addRawRoute('POST', $prefix . '/devices/enroll', [
        'controller' => DeviceController::class,
        'method' => 'enroll',
    ]);
    $r->addRawRoute('POST', $prefix . '/devices/{device_id}/heartbeat', [
        'controller' => DeviceController::class,
        'method' => 'heartbeat',
    ]);
    $r->addRawRoute('GET', $prefix . '/subscription', [
        'controller' => SubscriptionController::class,
        'method' => 'current',
    ]);
    $r->addRawRoute('GET', $prefix . '/sync/customers/bootstrap', [
        'controller' => CustomerSyncController::class,
        'method' => 'bootstrap',
    ]);
    $r->addRawRoute('GET', $prefix . '/sync/customers/delta', [
        'controller' => CustomerSyncController::class,
        'method' => 'delta',
    ]);
    $r->addRawRoute('POST', $prefix . '/sync/customers/push', [
        'controller' => CustomerSyncController::class,
        'method' => 'push',
    ]);
    $r->addRawRoute('GET', $prefix . '/crypto/worker-key', [
        'controller' => InteractionController::class,
        'method' => 'workerKey',
    ]);
    $r->addRawRoute('POST', $prefix . '/interactions', [
        'controller' => InteractionController::class,
        'method' => 'upload',
    ]);
    $r->addRawRoute('POST', $prefix . '/analysis-consents', [
        'controller' => AnalysisController::class,
        'method' => 'consent',
    ]);
    $r->addRawRoute('POST', $prefix . '/analysis-batches', [
        'controller' => AnalysisController::class,
        'method' => 'createBatch',
    ]);
    $r->addRawRoute('GET', $prefix . '/analysis-batches/{batch_id}', [
        'controller' => AnalysisController::class,
        'method' => 'batch',
    ]);
    $r->addRawRoute('GET', $prefix . '/analysis-runs/{run_id}', [
        'controller' => AnalysisController::class,
        'method' => 'run',
    ]);
    $r->addRawRoute('POST', $prefix . '/analysis-runs/{run_id}/retry', [
        'controller' => AnalysisController::class,
        'method' => 'retry',
    ]);
    $r->addRawRoute('PUT', $prefix . '/customer-phones/{customer_phone_id}/permissions/{channel}/{purpose}', [
        'controller' => MessagingPolicyController::class,
        'method' => 'putPermission',
    ]);
    $r->addRawRoute('POST', $prefix . '/messaging/eligibility/check', [
        'controller' => MessagingPolicyController::class,
        'method' => 'eligibility',
    ]);
    $r->addRawRoute('POST', $prefix . '/messaging/suppressions/events', [
        'controller' => MessagingPolicyController::class,
        'method' => 'suppressionEvent',
    ]);
    $r->addRawRoute('POST', $prefix . '/messaging/campaigns/{campaign_id}/recipient-snapshot', [
        'controller' => MessagingPolicyController::class,
        'method' => 'campaignSnapshot',
    ]);
    $r->addRawRoute('PUT', $prefix . '/messaging/campaigns/{campaign_id}/dispatch-policy', [
        'controller' => MessagingPolicyController::class,
        'method' => 'putCampaignPolicy',
    ]);
    $r->addRawRoute('POST', $prefix . '/messaging/campaigns/{campaign_id}/dispatch-preflight', [
        'controller' => MessagingPolicyController::class,
        'method' => 'dispatchPreflight',
    ]);
    $r->addRawRoute('POST', $prefix . '/schedules', [
        'controller' => ScheduleController::class,
        'method' => 'create',
    ]);
    $r->addRawRoute('POST', $prefix . '/schedules/{schedule_id}/cancel', [
        'controller' => ScheduleController::class,
        'method' => 'cancel',
    ]);
    $r->addRawRoute('GET', $prefix . '/schedules/{schedule_id}/dispatch', [
        'controller' => ScheduleController::class,
        'method' => 'dispatch',
    ]);
    $r->addRawRoute('POST', $prefix . '/schedules/{schedule_id}/status', [
        'controller' => ScheduleController::class,
        'method' => 'acknowledge',
    ]);
    $r->addRawRoute('GET', $prefix . '/customers/{customer_id}/analysis', [
        'controller' => InteractionController::class,
        'method' => 'latestAnalysis',
    ]);
    $r->addRawRoute('POST', $prefix . '/worker/jobs/lease', [
        'controller' => WorkerController::class,
        'method' => 'lease',
    ]);
    $r->addRawRoute('POST', $prefix . '/worker/preflight', [
        'controller' => WorkerController::class,
        'method' => 'preflight',
    ]);
    $r->addRawRoute('POST', $prefix . '/worker/heartbeat', [
        'controller' => WorkerController::class,
        'method' => 'heartbeat',
    ]);
    $r->addRawRoute('POST', $prefix . '/worker/jobs/{job_id}/renew', [
        'controller' => WorkerController::class,
        'method' => 'renew',
    ]);
    $r->addRawRoute('POST', $prefix . '/worker/jobs/{job_id}/complete', [
        'controller' => WorkerController::class,
        'method' => 'complete',
    ]);
    $r->addRawRoute('POST', $prefix . '/worker/jobs/{job_id}/fail', [
        'controller' => WorkerController::class,
        'method' => 'fail',
    ]);
};
