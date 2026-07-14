<?php

namespace Mublo\Plugin\EmailNotify\Controller\Admin;

use Mublo\Contract\Notification\CollectNotificationVariablesEvent;
use Mublo\Core\Context\Context;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Core\Extension\MigrationRunner;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Response\RedirectResponse;
use Mublo\Core\Response\ViewResponse;
use Mublo\Helper\Form\FormHelper;
use Mublo\Plugin\EmailNotify\Service\EmailNotifyService;
use Mublo\Infrastructure\Storage\UploadPolicy;

/**
 * EmailNotifyController
 *
 * 이메일 알림 플러그인 관리자 — 발신 설정 / 템플릿 관리 / 발송 이력.
 * SendonSmsController와 동일 패턴이나 채널/발신번호/잔액 개념이 없어 단순하다.
 */
class EmailNotifyController
{
    private const PLUGIN_NAME = 'EmailNotify';
    private const VIEW_PATH = MUBLO_PLUGIN_PATH . '/EmailNotify/views/Admin/';

    public function __construct(
        private EmailNotifyService $service,
        private MigrationRunner $migrationRunner,
        private ?EventDispatcher $eventDispatcher = null
    ) {
    }

    public function index(array $params, Context $context): RedirectResponse
    {
        return RedirectResponse::to('/admin/email-notify/settings');
    }

    // === 발신 설정 ===

    public function settings(array $params, Context $context): ViewResponse
    {
        $installView = $this->renderInstallViewIfNeeded('발신 설정');
        if ($installView !== null) {
            return $installView;
        }

        $domainId = $context->getDomainId() ?? 1;

        return ViewResponse::absoluteView(self::VIEW_PATH . 'Settings')->withData([
            'pageTitle' => '발신 설정',
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

        $result = $this->service->saveConfig($domainId, $data);

        return $result->isSuccess()
            ? JsonResponse::success(null, $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    // === 템플릿 관리 ===

    public function templates(array $params, Context $context): ViewResponse
    {
        $installView = $this->renderInstallViewIfNeeded('템플릿 관리');
        if ($installView !== null) {
            return $installView;
        }

        $request = $context->getRequest();
        $domainId = $context->getDomainId() ?? 1;
        $page = max(1, (int) ($request->get('page') ?? 1));
        $result = $this->service->getTemplates($domainId, $page);

        return ViewResponse::absoluteView(self::VIEW_PATH . 'Templates')->withData([
            'pageTitle' => '템플릿 관리',
            'templates' => $result['items'],
            'pagination' => $result['pagination'],
        ]);
    }

    public function templateForm(array $params, Context $context): ViewResponse|RedirectResponse
    {
        $installView = $this->renderInstallViewIfNeeded('템플릿 관리');
        if ($installView !== null) {
            return $installView;
        }

        $domainId = $context->getDomainId() ?? 1;
        $templateId = (int) ($params['id'] ?? 0);
        $template = null;

        if ($templateId > 0) {
            $template = $this->service->getTemplate($domainId, $templateId);
            if ($template === null) {
                return RedirectResponse::to('/admin/email-notify/templates');
            }
        }

        // 공통(사이트) 변수 그룹을 먼저 노출하고, 그 뒤에 패키지가 광고한 변수들.
        // 이 그룹의 값은 발송 시 EmailNotifyService가 도메인 설정에서 주입한다.
        $availableVariables = ['site' => [
            'label' => '사이트',
            'variables' => EmailNotifyService::SITE_VARIABLE_LABELS,
        ]];

        if ($this->eventDispatcher) {
            $event = $this->eventDispatcher->dispatch(new CollectNotificationVariablesEvent());
            $availableVariables += $event->getSources();
        }

        return ViewResponse::absoluteView(self::VIEW_PATH . 'TemplateForm')->withData([
            'pageTitle' => $templateId > 0 ? '템플릿 수정' : '템플릿 등록',
            'mode' => $templateId > 0 ? 'edit' : 'create',
            'template' => $template,
            'availableVariables' => $availableVariables,
        ]);
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

    public function sendTest(array $params, Context $context): JsonResponse
    {
        if ($this->hasPendingMigrations()) {
            return JsonResponse::error('플러그인 설치를 먼저 진행해주세요.');
        }

        $request = $context->getRequest();
        $domainId = $context->getDomainId() ?? 1;
        $templateCode = trim((string) ($request->json('template_code') ?? ''));
        $recipient = trim((string) ($request->json('recipient') ?? ''));
        $fieldValues = $request->json('field_values') ?? [];

        if ($templateCode === '') {
            return JsonResponse::error('템플릿 코드는 필수입니다.');
        }

        if ($recipient === '') {
            return JsonResponse::error('수신 이메일은 필수입니다.');
        }

        $result = $this->service->send(
            $domainId,
            $templateCode,
            $recipient,
            is_array($fieldValues) ? $fieldValues : []
        );

        return $result->isSuccess()
            ? JsonResponse::success(null, $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    // === 이미지 라이브러리 (도메인 공용) ===

    private const IMAGE_MAX_SIZE = 5 * 1024 * 1024;
    // 메일 이미지는 이미지 타입만 허용(코어 allowlist 안에서 좁히기). MIME 일치는 UploadPolicy가 담당.
    private const IMAGE_EXTS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    public function uploadImage(array $params, Context $context): JsonResponse
    {
        if ($this->hasPendingMigrations()) {
            return JsonResponse::error('플러그인 설치를 먼저 진행해주세요.');
        }
        if (!class_exists('finfo')) {
            return JsonResponse::error('서버에 fileinfo 확장이 필요합니다.', null, 500);
        }

        $request = $context->getRequest();
        $domainId = $context->getDomainId() ?? 1;

        if (!$request->hasFile('file')) {
            return JsonResponse::error('파일이 업로드되지 않았습니다.', null, 400);
        }

        $file = $request->getRawFile('file');
        if (!is_array($file) || is_array($file['name'] ?? null)) {
            return JsonResponse::error('단일 파일만 업로드할 수 있습니다.', null, 400);
        }
        if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return JsonResponse::error('파일 업로드에 실패했습니다.', null, 400);
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > self::IMAGE_MAX_SIZE) {
            return JsonResponse::error('파일 크기가 허용 범위를 초과했습니다. (최대 5MB)', null, 400);
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            return JsonResponse::error('업로드 파일을 찾을 수 없습니다.', null, 400);
        }

        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmpName);
        $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));

        // 이미지-only 좁히기 + 코어 UploadPolicy로 확장자-실제MIME 일치 강제(위조 차단, fail-closed)
        if (!in_array($ext, self::IMAGE_EXTS, true) || !UploadPolicy::matches($ext, $mime)) {
            return JsonResponse::error('허용되지 않은 파일 형식입니다.', null, 400);
        }

        [$dir, $urlBase] = $this->imageStorage($domainId);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return JsonResponse::error('업로드 디렉토리를 생성할 수 없습니다.', null, 500);
        }

        $newName = date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        if (!@move_uploaded_file($tmpName, $dir . $newName)) {
            return JsonResponse::error('파일 저장에 실패했습니다.', null, 500);
        }

        // 본문엔 상대경로로 저장한다(도메인 변경에 안전). 발송 시 현재 도메인으로 절대화됨.
        return JsonResponse::success(['url' => $urlBase . $newName, 'filename' => $newName], '업로드되었습니다.');
    }

    public function listImages(array $params, Context $context): JsonResponse
    {
        $request = $context->getRequest();
        $domainId = $context->getDomainId() ?? 1;
        [$dir, $urlBase] = $this->imageStorage($domainId);

        $items = [];
        if (is_dir($dir)) {
            foreach (scandir($dir) ?: [] as $f) {
                if ($f === '.' || $f === '..') {
                    continue;
                }
                if (!in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), self::IMAGE_EXTS, true)) {
                    continue;
                }
                $items[] = [
                    'filename' => $f,
                    'url'      => $urlBase . $f,
                    'size'     => @filesize($dir . $f) ?: 0,
                    'modified' => @filemtime($dir . $f) ?: 0,
                ];
            }
            usort($items, fn($a, $b) => $b['modified'] <=> $a['modified']);
        }

        return JsonResponse::success(['items' => $items]);
    }

    public function deleteImage(array $params, Context $context): JsonResponse
    {
        $request = $context->getRequest();
        $domainId = $context->getDomainId() ?? 1;

        // basename으로 경로 조작 차단
        $filename = basename((string) ($request->json('filename') ?? ''));
        if ($filename === '' || !in_array(strtolower(pathinfo($filename, PATHINFO_EXTENSION)), self::IMAGE_EXTS, true)) {
            return JsonResponse::error('유효한 파일명이 필요합니다.');
        }

        // 사용처 검사: 강제삭제가 아니고 어느 템플릿이 쓰고 있으면 삭제하지 않고 알린다.
        $force = !empty($request->json('force'));
        if (!$force) {
            $used = $this->service->findTemplatesUsingImage($domainId, $filename);
            if (!empty($used)) {
                return JsonResponse::success([
                    'inUse'     => true,
                    'templates' => array_map(fn($t) => $t['name'] !== '' ? $t['name'] : $t['code'], $used),
                ], '사용 중인 이미지입니다.');
            }
        }

        [$dir] = $this->imageStorage($domainId);
        $path = $dir . $filename;
        if (is_file($path)) {
            @unlink($path);
        }

        return JsonResponse::success(['inUse' => false], '삭제되었습니다.');
    }

    private function imageStorage(int $domainId): array
    {
        $base = defined('MUBLO_PUBLIC_STORAGE_PATH')
            ? MUBLO_PUBLIC_STORAGE_PATH
            : (($_SERVER['DOCUMENT_ROOT'] ?? '') . '/storage');

        $dir = rtrim($base, '/\\') . '/D' . $domainId . '/email-notify/';
        $urlBase = '/storage/D' . $domainId . '/email-notify/';

        return [$dir, $urlBase];
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
                'redirect' => '/admin/email-notify/settings',
                'executed' => $result['executed'],
            ], '이메일 알림 플러그인 설치가 완료되었습니다.');
        }

        return JsonResponse::error('설치 실패: ' . ($result['error'] ?? '알 수 없는 오류'));
    }

    // === Form Schema ===

    private function getConfigFormSchema(): array
    {
        return [
            'boolean' => ['is_active'],
        ];
    }

    private function getTemplateFormSchema(): array
    {
        return [
            'numeric' => ['template_id'],
            'boolean' => ['is_active'],
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
        return MUBLO_PLUGIN_PATH . '/EmailNotify/database/migrations';
    }
}
