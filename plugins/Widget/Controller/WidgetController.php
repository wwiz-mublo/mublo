<?php
declare(strict_types=1);

namespace Mublo\Plugin\Widget\Controller;

use Mublo\Core\Context\Context;
use Mublo\Core\Extension\MigrationRunner;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Response\ViewResponse;
use Mublo\Helper\Form\FormHelper;
use Mublo\Infrastructure\Storage\FileUploader;
use Mublo\Infrastructure\Storage\UploadedFile;
use Mublo\Plugin\Widget\Service\WidgetService;

class WidgetController
{
    private const PLUGIN_NAME = 'Widget';
    private const VIEW_PATH = MUBLO_PLUGIN_PATH . '/Widget/views/Admin/';

    public function __construct(
        private WidgetService $widgetService,
        private MigrationRunner $migrationRunner,
        private FileUploader $fileUploader
    ) {
    }

    /**
     * 위젯 관리 (설정 + 아이템 목록 통합 페이지)
     */
    public function index(array $params, Context $context): ViewResponse
    {
        $installView = $this->renderInstallViewIfNeeded('위젯 관리');
        if ($installView !== null) {
            return $installView;
        }

        $domainId = $context->getDomainId() ?? 1;
        $keyword  = trim((string) $context->getRequest()->query('keyword', ''));
        $config = $this->widgetService->getConfig($domainId);
        $result = $this->widgetService->getList($domainId, '', $keyword);

        $skinOptions = [
            'left' => \Mublo\Helper\Directory\DirectoryHelper::getSelectOptions('plugins/Widget/views/Front/skins/left'),
            'right' => \Mublo\Helper\Directory\DirectoryHelper::getSelectOptions('plugins/Widget/views/Front/skins/right'),
            'mobile' => \Mublo\Helper\Directory\DirectoryHelper::getSelectOptions('plugins/Widget/views/Front/skins/mobile'),
        ];

        return ViewResponse::absoluteView(self::VIEW_PATH . 'List')->withData([
            'pageTitle' => '위젯 관리',
            'config' => $config,
            'items' => $result['items'],
            'totalItems' => $result['totalItems'],
            'skinOptions' => $skinOptions,
            'search' => ['keyword' => $keyword],
        ]);
    }

    /**
     * 설정 저장
     */
    public function saveConfig(array $params, Context $context): JsonResponse
    {
        if ($this->hasPendingMigrations()) {
            return JsonResponse::error('플러그인 설치를 먼저 진행해주세요.');
        }

        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $formData = $request->input('formData') ?? [];

        $data = FormHelper::normalizeFormData($formData, [
            'boolean' => ['left_enabled', 'right_enabled', 'mobile_enabled'],
            'numeric' => ['left_width', 'right_width', 'mobile_width'],
        ]);
        $data['left_skin'] = $formData['left_skin'] ?? 'basic';
        $data['right_skin'] = $formData['right_skin'] ?? 'basic';
        $data['mobile_skin'] = $formData['mobile_skin'] ?? 'basic';
        $data['left_width'] = max(20, min(200, (int) ($data['left_width'] ?? 50)));
        $data['right_width'] = max(20, min(200, (int) ($data['right_width'] ?? 50)));
        $data['mobile_width'] = max(20, min(200, (int) ($data['mobile_width'] ?? 40)));

        $result = $this->widgetService->saveConfig($domainId, $data);

        return $result->isSuccess()
            ? JsonResponse::success(null, $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    /**
     * 아이템 저장 (생성/수정)
     */
    public function store(array $params, Context $context): JsonResponse
    {
        if ($this->hasPendingMigrations()) {
            return JsonResponse::error('플러그인 설치를 먼저 진행해주세요.');
        }

        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $formData = $request->input('formData') ?? [];

        $data = FormHelper::normalizeFormData($formData, $this->getFormSchema());

        // 아이콘 이미지 업로드
        $imageUrl = $this->processIconUpload($domainId);
        if ($imageUrl !== null) {
            $data['icon_image'] = $imageUrl;
        }

        $itemId = (int) ($data['item_id'] ?? 0);

        if ($itemId > 0) {
            $result = $this->widgetService->update($domainId, $itemId, $data);
        } else {
            $result = $this->widgetService->create($domainId, $data);
        }

        return $result->isSuccess()
            ? JsonResponse::success(['redirect' => '/admin/widget/list'], $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    public function delete(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $itemId = (int) ($params['id'] ?? 0);
        $result = $this->widgetService->delete($domainId, $itemId);

        return $result->isSuccess()
            ? JsonResponse::success(['redirect' => '/admin/widget/list'], $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    public function listDelete(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $ids = $request->input('chk') ?? [];

        if (empty($ids)) {
            return JsonResponse::error('삭제할 위젯을 선택해주세요.');
        }

        $deleted = 0;
        foreach ($ids as $id) {
            $result = $this->widgetService->delete($domainId, (int) $id);
            if ($result->isSuccess()) {
                $deleted++;
            }
        }

        return $deleted > 0
            ? JsonResponse::success(['redirect' => '/admin/widget/list'], "{$deleted}건이 삭제되었습니다.")
            : JsonResponse::error('삭제할 위젯이 없습니다.');
    }

    public function sort(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $items = $request->input('items') ?? $request->json('items') ?? [];

        if (empty($items)) {
            return JsonResponse::error('정렬할 항목이 없습니다.');
        }

        $result = $this->widgetService->updateOrder($domainId, $items);

        return $result->isSuccess()
            ? JsonResponse::success([], $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    /**
     * 프론트 API: 활성 위젯
     */
    public function activeWidgets(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $data = $this->widgetService->getActiveWidgets($domainId);

        return JsonResponse::success($data);
    }

    public function install(array $params, Context $context): JsonResponse
    {
        $result = $this->migrationRunner->run('plugin', self::PLUGIN_NAME, $this->getMigrationPath());

        if ($result['success']) {
            return JsonResponse::success(
                ['redirect' => '/admin/widget/list'],
                '위젯 플러그인이 설치되었습니다.'
            );
        }

        return JsonResponse::error('설치 실패: ' . ($result['error'] ?? '알 수 없는 오류'));
    }

    // === Private Helpers ===

    private function processIconUpload(int $domainId): ?string
    {
        $files = UploadedFile::fromGlobalNested('fileData', 'icon_image');
        if (empty($files)) {
            return null;
        }

        $file = is_array($files) ? $files[0] : $files;

        if ($file instanceof UploadedFile && $file->isValid()) {
            $uploadResult = $this->fileUploader->upload($file, $domainId, [
                'subdirectory' => 'widget',
                'allowed_extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'],
                'max_size' => 2 * 1024 * 1024,
            ]);

            if ($uploadResult->isSuccess()) {
                return '/storage/'
                    . $uploadResult->getRelativePath() . '/'
                    . $uploadResult->getStoredName();
            }
        }

        return null;
    }

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
        return MUBLO_PLUGIN_PATH . '/Widget/database/migrations';
    }

    private function getFormSchema(): array
    {
        return [
            'numeric' => ['item_id', 'sort_order'],
            'boolean' => ['is_active'],
            'enum' => [
                'position' => ['values' => ['left', 'right', 'mobile'], 'default' => 'right'],
                'item_type' => ['values' => ['link', 'tel'], 'default' => 'link'],
                'link_target' => ['values' => ['_self', '_blank'], 'default' => '_blank'],
            ],
        ];
    }
}
