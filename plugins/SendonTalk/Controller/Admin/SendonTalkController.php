<?php

namespace Mublo\Plugin\SendonTalk\Controller\Admin;

use Mublo\Core\Response\ViewResponse;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Context\Context;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Core\Extension\MigrationRunner;
use Mublo\Contract\Notification\CollectNotificationVariablesEvent;
use Mublo\Contract\Notification\NotificationTemplateContextInterface;
use Mublo\Plugin\SendonTalk\Service\SendonTalkService;

class SendonTalkController
{
    private const PLUGIN_NAME = 'SendonTalk';
    private const VIEW_PATH = MUBLO_PLUGIN_PATH . '/SendonTalk/views/Admin/';

    public function __construct(
        private SendonTalkService $service,
        private MigrationRunner $migrationRunner,
        private ?EventDispatcher $eventDispatcher = null,
        private ?NotificationTemplateContextInterface $uiHelper = null
    ) {}

    // ═══ 설정 ═══

    public function settings(array $params, Context $context): ViewResponse
    {
        $installView = $this->renderInstallViewIfNeeded('연동 설정');
        if ($installView) return $installView;

        return ViewResponse::absoluteView(self::VIEW_PATH . 'Settings')->withData([
            'pageTitle' => '연동 설정',
            'config' => $this->service->getConfig($context->getDomainId() ?? 1),
        ]);
    }

    public function saveSettings(array $params, Context $context): JsonResponse
    {
        if ($this->hasPendingMigrations()) return JsonResponse::error('플러그인 설치를 먼저 진행해주세요.');

        $formData = $context->getRequest()->input('formData') ?? [];
        $result = $this->service->saveConfig($context->getDomainId() ?? 1, $formData);
        return $result->isSuccess()
            ? JsonResponse::success(null, $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    // ═══ 채널/템플릿 (마스터-디테일) ═══

    public function channels(array $params, Context $context): ViewResponse
    {
        $installView = $this->renderInstallViewIfNeeded('채널/템플릿 관리');
        if ($installView) return $installView;

        $domainId = $context->getDomainId() ?? 1;

        // 변수 그룹 + 미리보기 샘플값 — NotificationTemplateUiHelper (코어 공용)
        $variableGroups = [];
        $companySamples = [];
        $shopSample = ['shop_name' => '', 'customer_tel' => '', 'customer_time' => '', 'domain' => ''];
        if ($this->uiHelper !== null) {
            $variableGroups = $this->uiHelper->collectVariableGroups($domainId);
            $companySamples = $this->uiHelper->getCompanySampleValues($domainId);
            $shopSample     = $this->uiHelper->loadShopSample($domainId);
        } elseif ($this->eventDispatcher) {
            // 구 와이어링 fallback: 이벤트만으로 그룹 구성
            try {
                $event = $this->eventDispatcher->dispatch(new CollectNotificationVariablesEvent());
                foreach ($event->getSources() as $source) {
                    $vars = [];
                    foreach ($source['variables'] as $key => $desc) {
                        $vars['#{' . $key . '}'] = (string) $desc;
                    }
                    $variableGroups[(string) $source['label']] = $vars;
                }
            } catch (\Throwable) {}
        }

        return ViewResponse::absoluteView(self::VIEW_PATH . 'Channels')->withData([
            'pageTitle'      => '채널/템플릿 관리',
            'channels'       => $this->service->getChannels($domainId),
            'config'         => $this->service->getConfig($domainId),
            'variableGroups' => $variableGroups,
            'companySamples' => $companySamples,
            'shopSample'     => $shopSample,
        ]);
    }

    // -- 채널 API --

    public function fetchProfiles(array $params, Context $context): JsonResponse
    {
        $result = $this->service->fetchSendProfiles($context->getDomainId() ?? 1);
        return $result->isSuccess()
            ? JsonResponse::success($result->getData(), $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    public function saveChannel(array $params, Context $context): JsonResponse
    {
        $request  = $context->getRequest();
        $formData = $request->input('formData') ?? $request->json() ?? [];
        $result = $this->service->saveChannel($context->getDomainId() ?? 1, $formData);
        return $result->isSuccess()
            ? JsonResponse::success($result->getData(), $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    public function deleteChannel(array $params, Context $context): JsonResponse
    {
        $channelId = (int) ($context->getRequest()->json('channel_id') ?? 0);
        if ($channelId <= 0) return JsonResponse::error('채널 ID가 필요합니다.');

        $result = $this->service->deleteChannel($context->getDomainId() ?? 1, $channelId);
        return $result->isSuccess()
            ? JsonResponse::success(null, $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    // -- 템플릿 API --

    public function templatesByChannel(array $params, Context $context): JsonResponse
    {
        $channelId = (int) ($context->getRequest()->query('channel_id') ?? 0);
        if ($channelId <= 0) return JsonResponse::error('채널 ID가 필요합니다.');

        $templates = $this->service->getTemplatesByChannel($context->getDomainId() ?? 1, $channelId);
        return JsonResponse::success(['templates' => $templates]);
    }

    public function saveTemplate(array $params, Context $context): JsonResponse
    {
        $formData = $context->getRequest()->input('formData') ?? [];
        $result = $this->service->saveTemplate($context->getDomainId() ?? 1, $formData);
        return $result->isSuccess()
            ? JsonResponse::success($result->getData(), $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    public function deleteTemplate(array $params, Context $context): JsonResponse
    {
        $templateId = (int) ($context->getRequest()->json('template_id') ?? 0);
        if ($templateId <= 0) return JsonResponse::error('템플릿 ID가 필요합니다.');

        $result = $this->service->deleteTemplate($context->getDomainId() ?? 1, $templateId);
        return $result->isSuccess()
            ? JsonResponse::success(null, $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    // -- 카카오 심사 --

    public function registerKakao(array $params, Context $context): JsonResponse
    {
        $templateId = (int) ($context->getRequest()->json('template_id') ?? 0);
        $result = $this->service->registerKakaoTemplate($context->getDomainId() ?? 1, $templateId);
        return $result->isSuccess()
            ? JsonResponse::success($result->getData(), $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    public function checkKakaoStatus(array $params, Context $context): JsonResponse
    {
        $request = $context->getRequest();
        $templateId = (int) ($request->json('template_id') ?? $request->query('template_id') ?? 0);
        $result = $this->service->checkKakaoTemplateStatus($context->getDomainId() ?? 1, $templateId);
        return $result->isSuccess()
            ? JsonResponse::success($result->getData(), $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    public function syncTemplates(array $params, Context $context): JsonResponse
    {
        $result = $this->service->syncKakaoTemplates($context->getDomainId() ?? 1);
        return $result->isSuccess()
            ? JsonResponse::success(null, $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    // ═══ 발송 이력 ═══

    public function history(array $params, Context $context): ViewResponse
    {
        $installView = $this->renderInstallViewIfNeeded('발송 이력');
        if ($installView) return $installView;

        $domainId = $context->getDomainId() ?? 1;
        $page = max(1, (int) ($context->getRequest()->query('page', 1)));
        $result = $this->service->getLogs($domainId, $page);

        return ViewResponse::absoluteView(self::VIEW_PATH . 'History')->withData([
            'pageTitle'  => '발송 이력',
            'logs'       => $result['items'],
            'pagination' => $result['pagination'],
        ]);
    }

    // ═══ 설치 ═══

    public function install(array $params, Context $context): JsonResponse
    {
        $result = $this->migrationRunner->run('plugin', self::PLUGIN_NAME, $this->getMigrationPath());
        return $result['success']
            ? JsonResponse::success(['redirect' => '/admin/sendon-talk/settings'], '설치가 완료되었습니다.')
            : JsonResponse::error('설치 실패: ' . ($result['error'] ?? ''));
    }

    // ═══ Private ═══

    private function getMigrationPath(): string { return MUBLO_PLUGIN_PATH . '/SendonTalk/database/migrations'; }

    private function hasPendingMigrations(): bool
    {
        return !empty($this->migrationRunner->getStatus('plugin', self::PLUGIN_NAME, $this->getMigrationPath())['pending']);
    }

    private function renderInstallViewIfNeeded(string $pageTitle): ?ViewResponse
    {
        $status = $this->migrationRunner->getStatus('plugin', self::PLUGIN_NAME, $this->getMigrationPath());
        if (empty($status['pending'])) return null;

        return ViewResponse::absoluteView(self::VIEW_PATH . 'Install')->withData([
            'pageTitle' => $pageTitle,
            'pending'   => $status['pending'],
        ]);
    }

}
