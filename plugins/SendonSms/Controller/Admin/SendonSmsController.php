<?php
declare(strict_types=1);

namespace Mublo\Plugin\SendonSms\Controller\Admin;

use Mublo\Core\Context\Context;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Core\Extension\MigrationRunner;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Response\RedirectResponse;
use Mublo\Core\Response\ViewResponse;
use Mublo\Helper\Form\FormHelper;
use Mublo\Contract\Notification\CollectNotificationVariablesEvent;
use Mublo\Plugin\SendonSms\Service\SendonSmsService;

/**
 * SendonSmsController
 *
 * 알림 채널 관리자 표준 패턴의 센드온 SMS 구현
 * 도메인별 자체 API 인증, 센드온 직접 충전/관리
 */
class SendonSmsController
{
    private const PLUGIN_NAME = 'SendonSms';
    private const VIEW_PATH = MUBLO_PLUGIN_PATH . '/SendonSms/views/Admin/';

    public function __construct(
        private SendonSmsService $service,
        private MigrationRunner $migrationRunner,
        private ?EventDispatcher $eventDispatcher = null
    ) {
    }

    public function index(array $params, Context $context): RedirectResponse
    {
        return RedirectResponse::to('/admin/sendon-sms/settings');
    }

    // === 연동 설정 ===

    public function settings(array $params, Context $context): ViewResponse
    {
        $installView = $this->renderInstallViewIfNeeded('연동 설정');
        if ($installView !== null) {
            return $installView;
        }

        $domainId = $context->getDomainId() ?? 1;

        return ViewResponse::absoluteView(self::VIEW_PATH . 'Settings')->withData([
            'pageTitle' => '연동 설정',
            'config' => $this->service->getConfig($domainId),
        ]);
    }

    public function saveSettings(array $params, Context $context): JsonResponse
    {
        if ($this->hasPendingMigrations()) {
            return JsonResponse::error('플러그인 설치를 먼저 진행해주세요.');
        }

        $request = $context->getRequest();
        $domainId = $context->getDomainId() ?? 1;
        $formData = $request->input('formData') ?? [];
        $data = FormHelper::normalizeFormData($formData, $this->getConfigFormSchema());

        $clearFields = [];
        foreach (['api_id', 'api_password'] as $field) {
            if (!empty($formData['_clear_' . $field])) {
                $clearFields[] = $field;
            }
        }

        $result = $this->service->saveConfig($domainId, $data, $clearFields);

        return $result->isSuccess()
            ? JsonResponse::success(null, $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    public function sendonBalance(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $result = $this->service->getSendonBalance($domainId);

        return $result->isSuccess()
            ? JsonResponse::success($result->getData(), $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    // === 채널/템플릿 관리 ===

    public function channels(array $params, Context $context): ViewResponse
    {
        $installView = $this->renderInstallViewIfNeeded('채널/템플릿 관리');
        if ($installView !== null) {
            return $installView;
        }

        $domainId = $context->getDomainId() ?? 1;
        $result = $this->service->getChannels($domainId, 1, 50);

        $availableVariables = [];
        if ($this->eventDispatcher) {
            $event = $this->eventDispatcher->dispatch(new CollectNotificationVariablesEvent());
            $availableVariables = $event->getSources();
        }

        return ViewResponse::absoluteView(self::VIEW_PATH . 'Channels')->withData([
            'pageTitle' => '채널/템플릿 관리',
            'channels' => $result['items'],
            'pagination' => $result['pagination'],
            'availableVariables' => $availableVariables,
            'config' => $this->service->getConfig($domainId),
        ]);
    }

    public function fetchSenders(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $result = $this->service->fetchSenderNumbers($domainId);

        return $result->isSuccess()
            ? JsonResponse::success($result->getData(), $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    public function saveChannel(array $params, Context $context): JsonResponse
    {
        if ($this->hasPendingMigrations()) {
            return JsonResponse::error('플러그인 설치를 먼저 진행해주세요.');
        }

        $request = $context->getRequest();
        $domainId = $context->getDomainId() ?? 1;
        $formData = $request->input('formData') ?? [];
        $data = FormHelper::normalizeFormData($formData, $this->getChannelFormSchema());
        $data['variables'] = $formData['variables'] ?? '';

        $result = $this->service->saveChannel($domainId, $data);

        return $result->isSuccess()
            ? JsonResponse::success($result->getData(), $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    public function deleteChannel(array $params, Context $context): JsonResponse
    {
        if ($this->hasPendingMigrations()) {
            return JsonResponse::error('플러그인 설치를 먼저 진행해주세요.');
        }

        $request = $context->getRequest();
        $domainId = $context->getDomainId() ?? 1;
        $channelId = (int) ($request->json('channel_id') ?? 0);

        if ($channelId <= 0) {
            return JsonResponse::error('유효한 채널 ID가 필요합니다.');
        }

        $result = $this->service->deleteChannel($domainId, $channelId);

        return $result->isSuccess()
            ? JsonResponse::success(null, $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    public function templatesByChannel(array $params, Context $context): JsonResponse
    {
        if ($this->hasPendingMigrations()) {
            return JsonResponse::error('플러그인 설치를 먼저 진행해주세요.');
        }

        $domainId = $context->getDomainId() ?? 1;
        $channelId = (int) ($params['id'] ?? 0);

        if ($channelId <= 0) {
            return JsonResponse::error('유효한 채널 ID가 필요합니다.');
        }

        $request = $context->getRequest();
        $page = max(1, (int) ($request->get('page') ?? 1));
        $result = $this->service->getTemplatesByChannel($domainId, $channelId, $page);

        return JsonResponse::success($result);
    }

    public function saveTemplate(array $params, Context $context): JsonResponse
    {
        if ($this->hasPendingMigrations()) {
            return JsonResponse::error('플러그인 설치를 먼저 진행해주세요.');
        }

        $request = $context->getRequest();
        $domainId = $context->getDomainId() ?? 1;
        $formData = $request->input('formData') ?? [];
        $data = FormHelper::normalizeFormData($formData, $this->getTemplateFormSchema());
        $data['variable_schema'] = $formData['variable_schema'] ?? '';

        $result = $this->service->saveTemplate($domainId, $data);

        return $result->isSuccess()
            ? JsonResponse::success($result->getData(), $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    public function deleteTemplate(array $params, Context $context): JsonResponse
    {
        if ($this->hasPendingMigrations()) {
            return JsonResponse::error('플러그인 설치를 먼저 진행해주세요.');
        }

        $request = $context->getRequest();
        $domainId = $context->getDomainId() ?? 1;
        $templateId = (int) ($request->json('template_id') ?? 0);

        if ($templateId <= 0) {
            return JsonResponse::error('유효한 템플릿 ID가 필요합니다.');
        }

        $result = $this->service->deleteTemplate($domainId, $templateId);

        return $result->isSuccess()
            ? JsonResponse::success(null, $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    public function send(array $params, Context $context): JsonResponse
    {
        if ($this->hasPendingMigrations()) {
            return JsonResponse::error('플러그인 설치를 먼저 진행해주세요.');
        }

        $request = $context->getRequest();
        $domainId = $context->getDomainId() ?? 1;
        $templateCode = trim((string) ($request->json('template_code') ?? ''));
        $recipient = trim((string) ($request->json('recipient') ?? ''));
        $fieldValues = $request->json('field_values') ?? [];
        $reservedAt = trim((string) ($request->json('reserved_at') ?? ''));

        if ($templateCode === '') {
            return JsonResponse::error('템플릿 코드는 필수입니다.');
        }

        if ($recipient === '') {
            return JsonResponse::error('수신자 번호는 필수입니다.');
        }

        $result = $this->service->send(
            $domainId,
            $templateCode,
            $recipient,
            is_array($fieldValues) ? $fieldValues : [],
            $reservedAt !== '' ? $reservedAt : null
        );

        return $result->isSuccess()
            ? JsonResponse::success($result->getData(), $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    public function history(array $params, Context $context): ViewResponse
    {
        $installView = $this->renderInstallViewIfNeeded('발송 이력');
        if ($installView !== null) {
            return $installView;
        }

        $request = $context->getRequest();
        $domainId = $context->getDomainId() ?? 1;
        $page = max(1, (int) ($request->get('page') ?? 1));
        $result = $this->service->getLogs($domainId, $page);

        return ViewResponse::absoluteView(self::VIEW_PATH . 'History')->withData([
            'pageTitle' => '발송 이력',
            'logs' => $result['items'],
            'pagination' => $result['pagination'],
        ]);
    }

    public function install(array $params, Context $context): JsonResponse
    {
        $result = $this->migrationRunner->run('plugin', self::PLUGIN_NAME, $this->getMigrationPath());

        if ($result['success']) {
            return JsonResponse::success([
                'redirect' => '/admin/sendon-sms/settings',
                'executed' => $result['executed'],
            ], 'SMS 플러그인 설치가 완료되었습니다.');
        }

        return JsonResponse::error('설치 실패: ' . ($result['error'] ?? '알 수 없는 오류'));
    }

    // === Form Schema ===

    private function getConfigFormSchema(): array
    {
        return [
            'boolean' => ['test_mode', 'is_active'],
        ];
    }

    private function getChannelFormSchema(): array
    {
        return [
            'numeric' => ['channel_id'],
            'boolean' => ['is_active'],
        ];
    }

    private function getTemplateFormSchema(): array
    {
        return [
            'numeric' => ['template_id', 'channel_id'],
            'boolean' => ['is_active'],
            'enum' => [
                'message_type' => ['values' => ['SMS', 'LMS', 'MMS'], 'default' => 'SMS'],
            ],
        ];
    }

    // === Private Helpers ===

    private function renderInstallViewIfNeeded(string $pageTitle): ?ViewResponse
    {
        $status = $this->migrationRunner->getStatus('plugin', self::PLUGIN_NAME, $this->getMigrationPath());
        if (empty($status['pending'])) {
            return null;
        }

        return ViewResponse::absoluteView(self::VIEW_PATH . 'Install')->withData([
            'pageTitle' => $pageTitle,
            'pending' => $status['pending'],
        ]);
    }

    private function hasPendingMigrations(): bool
    {
        $status = $this->migrationRunner->getStatus('plugin', self::PLUGIN_NAME, $this->getMigrationPath());
        return !empty($status['pending']);
    }

    private function getMigrationPath(): string
    {
        return MUBLO_PLUGIN_PATH . '/SendonSms/database/migrations';
    }
}
