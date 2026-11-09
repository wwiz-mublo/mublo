<?php
declare(strict_types=1);

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

        // 비밀값은 화면에 내려보내지 않고 저장 여부만 알린다
        $secretSaved = [];
        foreach (PayAppConfigService::SENSITIVE_FIELDS as $field) {
            $secretSaved[$field] = (string) ($config[$field] ?? '') !== '';
            unset($config[$field]);
        }

        return ViewResponse::absoluteView(MUBLO_PLUGIN_PATH . '/PayApp/views/Admin/Settings/Index')
            ->withData([
                'title' => '페이앱 설정',
                'config' => $config,
                'secretSaved' => $secretSaved,
                'isConfigured' => $this->configService->isConfigured($domainId),
            ]);
    }

    public function save(Request $request, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $formData = $request->input('formData') ?? $request->json() ?? [];
        $current = $this->configService->getConfig($domainId);

        $config = [
            'userid' => trim((string) ($formData['userid'] ?? '')),
            'mode' => 'live', // 페이앱은 sandbox API가 없어 항상 운영 모드
            'feedback_ip_allowlist' => trim((string) ($formData['feedback_ip_allowlist'] ?? '')),
        ];

        // 비밀값은 화면에 다시 내려보내지 않는다 — 빈 칸으로 저장하면 기존 값을 유지한다
        foreach (PayAppConfigService::SENSITIVE_FIELDS as $field) {
            $submitted = trim((string) ($formData[$field] ?? ''));
            $config[$field] = $submitted !== '' ? $submitted : (string) ($current[$field] ?? '');
        }

        $this->configService->saveConfig($domainId, $config);

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
