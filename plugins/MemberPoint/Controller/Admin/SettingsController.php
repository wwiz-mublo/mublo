<?php
namespace Mublo\Plugin\MemberPoint\Controller\Admin;

use Mublo\Core\Response\ViewResponse;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Response\RedirectResponse;
use Mublo\Core\Context\Context;
use Mublo\Core\Extension\MigrationRunner;
use Mublo\Repository\Member\MemberLevelRepository;
use Mublo\Plugin\MemberPoint\Service\MemberPointConfigService;

class SettingsController
{
    private const PLUGIN_NAME = 'MemberPoint';
    private const VIEW_PATH   = MUBLO_PLUGIN_PATH . '/MemberPoint/views/Admin/Settings/';

    public function __construct(
        private MemberPointConfigService $configService,
        private MigrationRunner $migrationRunner,
        private MemberLevelRepository $levelRepository,
    ) {}

    public function index(array $params, Context $context): ViewResponse|RedirectResponse
    {
        $status = $this->migrationRunner->getStatus('plugin', self::PLUGIN_NAME, $this->getMigrationPath());
        if (!empty($status['pending'])) {
            return ViewResponse::absoluteView(self::VIEW_PATH . 'Install')
                ->withData([
                    'pageTitle' => '포인트 플러그인 설치',
                    'pending'   => $status['pending'],
                ]);
        }

        return RedirectResponse::to('/admin/member-point/member-settings');
    }

    /**
     * 회원포인트 설정 페이지
     *
     * GET /admin/member-point/member-settings
     */
    public function memberSettings(array $params, Context $context): ViewResponse
    {
        $status = $this->migrationRunner->getStatus('plugin', self::PLUGIN_NAME, $this->getMigrationPath());
        if (!empty($status['pending'])) {
            return ViewResponse::absoluteView(self::VIEW_PATH . 'Install')
                ->withData([
                    'pageTitle' => '포인트 플러그인 설치',
                    'pending'   => $status['pending'],
                ]);
        }

        $domainId = $context->getDomainId() ?? 1;
        $config   = $this->configService->getConfig($domainId);
        $levels   = array_map(fn($l) => $l->toArray(), $this->levelRepository->getAll());

        return ViewResponse::absoluteView(self::VIEW_PATH . 'MemberSettings')
            ->withData([
                'pageTitle'    => '회원포인트 설정',
                'settings'     => $config,
                'actionLabels' => MemberPointConfigService::getActionLabels(),
                'levels'       => $levels,
            ]);
    }

    /**
     * 회원포인트 설정 저장
     *
     * POST /admin/member-point/member-settings
     */
    public function saveMemberSettings(array $params, Context $context): JsonResponse
    {
        $request  = $context->getRequest();
        $formData = $request->input('formData') ?? [];
        $domainId = $context->getDomainId() ?? 1;

        $result = $this->configService->saveMember($domainId, $formData);

        return $result->isSuccess()
            ? JsonResponse::success(null, $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    // =========================================================================
    // 설치
    // =========================================================================

    public function install(array $params, Context $context): JsonResponse
    {
        $result = $this->migrationRunner->run('plugin', self::PLUGIN_NAME, $this->getMigrationPath());

        return $result['success']
            ? JsonResponse::success(null, '설치가 완료되었습니다.')
            : JsonResponse::error('설치 중 오류가 발생했습니다: ' . ($result['error'] ?? ''));
    }

    private function getMigrationPath(): string
    {
        return MUBLO_PLUGIN_PATH . '/MemberPoint/database/migrations';
    }
}
