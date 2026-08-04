<?php
declare(strict_types=1);
namespace Mublo\Controller\Admin;

use Mublo\Core\Context\Context;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Response\ViewResponse;
use Mublo\Service\AI\DomainAiConfigService;
use Mublo\Service\AI\AiAssetService;
use Mublo\Service\Auth\AuthService;
use Mublo\Service\Migration\CoreMigrationService;

class AiSettingsController
{
    public function __construct(
        private DomainAiConfigService $configService,
        private AiAssetService $assetService,
        private AuthService $authService,
        private CoreMigrationService $migrationService,
    ) {
    }

    public function index(array $params, Context $context): ViewResponse|JsonResponse
    {
        if (!$this->authService->canOperateDomain()) {
            return JsonResponse::forbidden('AI 설정은 도메인 운영자 이상만 볼 수 있습니다.');
        }
        try {
            $this->ensureCoreMigrations();
        } catch (\Throwable) {
            return JsonResponse::serverError('AI 저장소를 준비하지 못했습니다. 시스템 마이그레이션을 확인해주세요.');
        }
        return ViewResponse::view('aisettings/index')->withData([
            'pageTitle' => 'AI 설정',
            'description' => '현재 도메인에서 사용할 AI 공급자와 API 키를 관리합니다.',
            'aiConfig' => $this->configService->publicConfig($context->getDomainId() ?? 1),
            'aiAssets' => $this->assetService->list($context->getDomainId() ?? 1),
            'activeCode' => '002_006',
        ]);
    }

    public function assetDetail(array $params, Context $context): JsonResponse
    {
        if (!$this->authService->canOperateDomain()) {
            return JsonResponse::forbidden('AI 자산은 도메인 운영자 이상만 볼 수 있습니다.');
        }
        try {
            $this->ensureCoreMigrations();
            $asset = $this->assetService->detail(
                $context->getDomainId() ?? 1,
                (int) $context->getRequest()->query('id', 0)
            );
            return $asset
                ? JsonResponse::success($asset)
                : JsonResponse::notFound('자산을 찾을 수 없습니다.');
        } catch (\Throwable) {
            return JsonResponse::serverError('AI 자산 정보를 불러오지 못했습니다.');
        }
    }

    public function update(array $params, Context $context): JsonResponse
    {
        if (!$this->authService->canOperateDomain()) {
            return JsonResponse::forbidden('AI 설정은 도메인 운영자 이상만 변경할 수 있습니다.');
        }

        try {
            $this->ensureCoreMigrations();
            $input = $context->getRequest()->input('formData') ?? [];
            $wasConfigured = $this->configService->publicConfig($context->getDomainId() ?? 1)['api_key_configured'];
            return JsonResponse::success(
                $this->configService->save($context->getDomainId() ?? 1, is_array($input) ? $input : []),
                $wasConfigured ? 'AI 설정이 수정되었습니다.' : 'AI 설정이 저장되었습니다.'
            );
        } catch (\InvalidArgumentException $e) {
            return JsonResponse::validationError([], $e->getMessage());
        } catch (\Throwable) {
            return JsonResponse::serverError('AI 설정을 저장하지 못했습니다.');
        }
    }

    public function removeKey(array $params, Context $context): JsonResponse
    {
        if (!$this->authService->canOperateDomain()) {
            return JsonResponse::forbidden('AI 설정은 도메인 운영자 이상만 변경할 수 있습니다.');
        }
        try {
            $this->ensureCoreMigrations();
            return JsonResponse::success(
                $this->configService->removeKey($context->getDomainId() ?? 1),
                'API 키를 삭제하고 AI 기능을 비활성화했습니다.'
            );
        } catch (\Throwable) {
            return JsonResponse::serverError('AI 저장소를 준비하지 못했습니다. 시스템 마이그레이션을 확인해주세요.');
        }
    }

    private function ensureCoreMigrations(): void
    {
        if ($this->migrationService->hasPending()) $this->migrationService->runPending();
    }
}
