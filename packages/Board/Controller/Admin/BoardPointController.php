<?php
declare(strict_types=1);
namespace Mublo\Packages\Board\Controller\Admin;

use Mublo\Core\Response\ViewResponse;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Context\Context;
use Mublo\Packages\Board\Service\BoardPointConfigService;

/**
 * Admin BoardPointController
 *
 * 게시판 포인트 설정 관리
 *
 * 라우트:
 * - GET  /admin/board/point                          → index (기본 설정)
 * - POST /admin/board/point                          → save (기본 설정 저장)
 * - GET  /admin/board/point/{scopeType}/{scopeId}    → scopeConfig (그룹/게시판별 설정 조회)
 * - POST /admin/board/point/{scopeType}/{scopeId}    → saveScopeConfig
 * - DELETE /admin/board/point/{scopeType}/{scopeId}  → deleteScopeConfig
 */
class BoardPointController
{
    public function __construct(
        private BoardPointConfigService $configService,
    ) {}

    /**
     * 포인트 설정 페이지
     */
    public function index(array $params, Context $context): ViewResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $config = $this->configService->getConfig($domainId);

        $scopes = $this->configService->getAdminScopes($domainId);

        $groupConfigs = $this->configService->getAllScopeConfigs($domainId, 'group');
        $boardConfigs = $this->configService->getAllScopeConfigs($domainId, 'board');

        return ViewResponse::absoluteView(dirname(__DIR__, 2) . '/views/Admin/Point/Settings')
            ->withData([
                'pageTitle'      => '게시판 포인트 설정',
                'settings'       => $config,
                'actionLabels'   => BoardPointConfigService::getActionLabels(),
                'earnActions'    => BoardPointConfigService::getEarnActions(),
                'consumeActions' => BoardPointConfigService::getConsumeActions(),
                'groups'         => $scopes['groups'],
                'boards'         => $scopes['boards'],
                'groupConfigs'   => $groupConfigs,
                'boardConfigs'   => $boardConfigs,
            ]);
    }

    /**
     * 기본 설정 저장
     */
    public function save(array $params, Context $context): JsonResponse
    {
        $request = $context->getRequest();
        $formData = $request->input('formData') ?? [];
        $domainId = $context->getDomainId() ?? 1;

        $result = $this->configService->save($domainId, $formData);

        return $result->isSuccess()
            ? JsonResponse::success(null, $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    /**
     * 스코프별 설정 조회 (AJAX)
     */
    public function scopeConfig(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $scopeType = $params['scopeType'] ?? '';
        $scopeId = (int) ($params['scopeId'] ?? 0);

        if (!in_array($scopeType, ['group', 'board'], true) || $scopeId <= 0) {
            return JsonResponse::error('잘못된 요청입니다.');
        }
        if (!$this->configService->hasScope($domainId, $scopeType, $scopeId)) {
            return JsonResponse::error('현재 도메인의 게시판 또는 그룹을 찾을 수 없습니다.', null, 404);
        }

        $config = $this->configService->getScopeConfig($domainId, $scopeType, $scopeId);
        $defaults = $this->configService->getConfig($domainId);

        $data = [
            'config' => $config,
            'defaults' => $defaults,
        ];

        if ($scopeType === 'board') {
            $data['enabledReactions'] = $this->configService->getEnabledReactions($domainId, $scopeId) ?? [];
        }

        return JsonResponse::success($data);
    }

    /**
     * 스코프별 설정 저장
     */
    public function saveScopeConfig(array $params, Context $context): JsonResponse
    {
        $request = $context->getRequest();
        $formData = $request->input('formData') ?? [];
        $domainId = $context->getDomainId() ?? 1;
        $scopeType = $params['scopeType'] ?? '';
        $scopeId = (int) ($params['scopeId'] ?? 0);

        if (!in_array($scopeType, ['group', 'board'], true) || $scopeId <= 0) {
            return JsonResponse::error('잘못된 요청입니다.');
        }

        $result = $this->configService->saveScopeConfig($domainId, $scopeType, $scopeId, $formData);

        return $result->isSuccess()
            ? JsonResponse::success(null, $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    /**
     * 스코프별 설정 삭제 (기본값 복원)
     */
    public function deleteScopeConfig(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $scopeType = $params['scopeType'] ?? '';
        $scopeId = (int) ($params['scopeId'] ?? 0);

        if (!in_array($scopeType, ['group', 'board'], true) || $scopeId <= 0) {
            return JsonResponse::error('잘못된 요청입니다.');
        }

        $result = $this->configService->deleteScopeConfig($domainId, $scopeType, $scopeId);

        return $result->isSuccess()
            ? JsonResponse::success(null, $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }
}
