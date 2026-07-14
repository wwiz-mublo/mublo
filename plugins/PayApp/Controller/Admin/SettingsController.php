<?php

namespace Mublo\Plugin\PayApp\Controller\Admin;

use Mublo\Core\Context\Context;
use Mublo\Core\Http\Request;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Response\ViewResponse;
use Mublo\Core\Extension\MigrationRunner;
use Mublo\Infrastructure\Database\Database;
use Mublo\Plugin\PayApp\Service\PayAppConfigService;

class SettingsController
{
    private PayAppConfigService $configService;
    private MigrationRunner $migrationRunner;

    public function __construct(PayAppConfigService $configService, Database $db)
    {
        $this->configService = $configService;
        $this->migrationRunner = new MigrationRunner($db);
    }

    public function index(Request $request, Context $context): ViewResponse
    {
        $domainId = $context->getDomainId() ?? 1;

        // 마이그레이션 상태 확인
        $migrationStatus = $this->migrationRunner->getStatus(
            'plugin', 'PayApp', MUBLO_PLUGIN_PATH . '/PayApp/database/migrations'
        );
        $hasPending = !empty($migrationStatus['pending']);

        if ($hasPending) {
            return ViewResponse::absoluteView(MUBLO_PLUGIN_PATH . '/PayApp/views/Admin/Settings/Install')
                ->withData([
                    'title' => '페이앱 설정',
                    'pending' => $migrationStatus['pending'],
                ]);
        }

        $config = $this->configService->getConfig($domainId);

        return ViewResponse::absoluteView(MUBLO_PLUGIN_PATH . '/PayApp/views/Admin/Settings/Index')
            ->withData([
                'title' => '페이앱 설정',
                'config' => $config,
                'isConfigured' => $this->configService->isConfigured($domainId),
            ]);
    }

    public function save(Request $request, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $formData = $request->input('formData') ?? $request->json() ?? [];

        $this->configService->saveConfig($domainId, [
            'userid' => trim($formData['userid'] ?? ''),
            'linkkey' => trim($formData['linkkey'] ?? ''),
            'linkval' => trim($formData['linkval'] ?? ''),
            'mode' => 'live', // 페이앱은 sandbox API가 없어 항상 운영 모드
            'feedback_ip_allowlist' => trim($formData['feedback_ip_allowlist'] ?? ''),
        ]);

        return JsonResponse::success(null, '설정이 저장되었습니다.');
    }

    public function runMigration(Request $request, Context $context): JsonResponse
    {
        $result = $this->migrationRunner->run(
            'plugin', 'PayApp', MUBLO_PLUGIN_PATH . '/PayApp/database/migrations'
        );
        return JsonResponse::success(['executed' => $result], '마이그레이션이 완료되었습니다.');
    }
}
