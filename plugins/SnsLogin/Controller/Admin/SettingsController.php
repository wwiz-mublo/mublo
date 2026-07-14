<?php
namespace Mublo\Plugin\SnsLogin\Controller\Admin;

use Mublo\Core\Context\Context;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Core\Event\Member\MemberLevelListQueryEvent;
use Mublo\Core\Extension\MigrationRunner;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Response\ViewResponse;
use Mublo\Plugin\SnsLogin\Service\SnsLoginConfigService;

class SettingsController
{
    private const PLUGIN_NAME = 'SnsLogin';
    private const VIEW_PATH   = MUBLO_PLUGIN_PATH . '/SnsLogin/views/Admin/Settings/';

    public function __construct(
        private SnsLoginConfigService $configService,
        private MigrationRunner $migrationRunner,
        private EventDispatcher       $eventDispatcher,
    ) {}

    public function index(array $params, Context $context): ViewResponse
    {
        $status = $this->migrationRunner->getStatus('plugin', self::PLUGIN_NAME, $this->getMigrationPath());
        if (!empty($status['pending'])) {
            return ViewResponse::absoluteView(self::VIEW_PATH . 'Install')
                ->withData([
                    'pageTitle' => 'SNS 로그인 플러그인 설치',
                    'pending'   => $status['pending'],
                ]);
        }

        $domainId        = $context->getDomainId() ?? 1;
        $config          = $this->configService->getConfig($domainId);
        $callbackBaseUrl = $this->getCallbackBaseUrl($context);

        // 일반 회원 레벨만 조회 (is_admin=0, is_super=0)
        $levelEvent   = $this->eventDispatcher->dispatch(
            new MemberLevelListQueryEvent(['member_only' => true])
        );
        $levelOptions = $levelEvent->getOptionsForSelect();

        return ViewResponse::absoluteView(self::VIEW_PATH . 'Index')
            ->withData([
                'pageTitle'       => 'SNS 로그인 설정',
                'config'          => $config,
                'callbackBaseUrl' => $callbackBaseUrl,
                'levelOptions'    => $levelOptions,
            ]);
    }

    public function save(array $params, Context $context): JsonResponse
    {
        $request  = $context->getRequest();
        $formData = $request->input('formData') ?? [];
        $domainId = $context->getDomainId() ?? 1;

        $result = $this->configService->save($domainId, $formData);

        return $result->isSuccess()
            ? JsonResponse::success(null, $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    public function install(array $params, Context $context): JsonResponse
    {
        $result = $this->migrationRunner->run('plugin', self::PLUGIN_NAME, $this->getMigrationPath());

        return $result['success']
            ? JsonResponse::success(null, '설치가 완료되었습니다.')
            : JsonResponse::error('설치 중 오류가 발생했습니다: ' . ($result['error'] ?? ''));
    }

    private function getMigrationPath(): string
    {
        return MUBLO_PLUGIN_PATH . '/SnsLogin/database/migrations';
    }

    private function getCallbackBaseUrl(Context $context): string
    {
        $request = $context->getRequest();
        $scheme  = $request->isHttps() ? 'https' : 'http';
        $host    = $request->getHost();
        return "{$scheme}://{$host}";
    }
}
