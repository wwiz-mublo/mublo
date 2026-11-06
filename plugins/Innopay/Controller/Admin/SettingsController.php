<?php
declare(strict_types=1);

namespace Mublo\Plugin\Innopay\Controller\Admin;

use Mublo\Core\Context\Context;
use Mublo\Core\Extension\MigrationRunner;
use Mublo\Core\Http\Request;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Response\ViewResponse;
use Mublo\Infrastructure\Database\Database;
use Mublo\Plugin\Innopay\Service\InnopayConfigService;

class SettingsController
{
    private const VIEW_PATH = MUBLO_PLUGIN_PATH . '/Innopay/views/Admin/';

    private MigrationRunner $migrationRunner;

    public function __construct(private InnopayConfigService $configService, Database $db)
    {
        $this->migrationRunner = new MigrationRunner($db);
    }

    public function index(Request $request, Context $context): ViewResponse
    {
        $domainId = $context->getDomainId() ?? 1;

        $installView = self::installViewIfNeeded($this->migrationRunner, '이노페이 설정');
        if ($installView !== null) {
            return $installView;
        }

        $config = $this->configService->getConfig($domainId);

        return ViewResponse::absoluteView(self::VIEW_PATH . 'Settings/Index')
            ->withData([
                'title' => '이노페이 설정',
                'config' => $config,
                // 비밀값은 화면에 다시 내보내지 않는다 — 등록 여부만 알린다
                'hasMerchantKey' => $config['merchant_key'] !== '',
                'hasCancelPwd' => $config['cancel_pwd'] !== '',
                'isConfigured' => $this->configService->isConfigured($domainId),
                'payMethods' => InnopayConfigService::PAY_METHODS,
            ]);
    }

    public function save(Request $request, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $formData = $request->input('formData') ?? $request->json() ?? [];

        $email = trim((string) ($formData['default_buyer_email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return JsonResponse::error('기본 구매자 이메일 형식이 올바르지 않습니다.');
        }
        $logoUrl = trim((string) ($formData['logo_url'] ?? ''));
        if ($logoUrl !== '' && (!preg_match('#^https?://#i', $logoUrl) || strlen($logoUrl) > 80)) {
            return JsonResponse::error('로고 URL 은 http(s) 주소로 80자 이내여야 합니다.');
        }

        $this->configService->saveConfig($domainId, [
            'mid' => trim((string) ($formData['mid'] ?? '')),
            'merchant_key' => trim((string) ($formData['merchant_key'] ?? '')),
            'cancel_pwd' => trim((string) ($formData['cancel_pwd'] ?? '')),
            'pay_method' => (string) ($formData['pay_method'] ?? 'CARD'),
            'default_buyer_email' => $email,
            'logo_url' => $logoUrl,
            'auto_cancel' => !empty($formData['auto_cancel']),
        ]);

        return JsonResponse::success(null, '설정이 저장되었습니다.');
    }

    public function runMigration(Request $request, Context $context): JsonResponse
    {
        $result = $this->migrationRunner->run('plugin', 'Innopay', MUBLO_PLUGIN_PATH . '/Innopay/database/migrations');
        if (isset($result['success']) && !$result['success']) {
            return JsonResponse::error('설치 실패: ' . ($result['error'] ?? '알 수 없는 오류'));
        }
        return JsonResponse::success(['executed' => $result['executed'] ?? $result], '설치가 완료되었습니다.');
    }

    /**
     * 테이블이 아직 없으면 설치 안내 화면을 돌려준다.
     */
    public static function installViewIfNeeded(MigrationRunner $runner, string $title): ?ViewResponse
    {
        $status = $runner->getStatus('plugin', 'Innopay', MUBLO_PLUGIN_PATH . '/Innopay/database/migrations');
        if (empty($status['pending'])) {
            return null;
        }

        return ViewResponse::absoluteView(self::VIEW_PATH . 'Install')
            ->withData(['title' => $title, 'pending' => $status['pending']]);
    }
}
