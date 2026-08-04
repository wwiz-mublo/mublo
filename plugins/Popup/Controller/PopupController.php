<?php
declare(strict_types=1);

namespace Mublo\Plugin\Popup\Controller;

use Mublo\Core\Context\Context;
use Mublo\Core\Extension\MigrationRunner;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Response\ViewResponse;
use Mublo\Helper\Form\FormHelper;
use Mublo\Plugin\Popup\Service\PopupService;

class PopupController
{
    private const PLUGIN_NAME = 'Popup';
    private const VIEW_PATH = MUBLO_PLUGIN_PATH . '/Popup/views/Admin/';

    public function __construct(
        private PopupService $popupService,
        private MigrationRunner $migrationRunner
    ) {
    }

    public function index(array $params, Context $context): ViewResponse
    {
        $installView = $this->renderInstallViewIfNeeded('팝업 관리');
        if ($installView !== null) {
            return $installView;
        }

        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $page = max(1, (int) $request->query('page', 1));
        $keyword = $request->query('keyword', '');

        $result = $this->popupService->getList($domainId, $page, 20, $keyword);

        $config = $this->popupService->getConfig($domainId);
        $skinOptions = \Mublo\Helper\Directory\DirectoryHelper::getSelectOptions(
            'plugins/Popup/views/Front/skins'
        );

        return ViewResponse::absoluteView(self::VIEW_PATH . 'List')->withData([
            'pageTitle' => '팝업 관리',
            'items' => $result['items'],
            'pagination' => $result['pagination'],
            'search' => ['keyword' => $keyword],
            'config' => $config,
            'skinOptions' => $skinOptions,
            'listQuery' => $this->buildListQuery($context),
        ]);
    }

    /** 목록의 현재 검색/페이징을 쿼리스트링으로 (목록 뷰가 return 파라미터로 실어보냄) */
    private function buildListQuery(Context $context): string
    {
        $request = $context->getRequest();
        $keyword = trim((string) $request->query('keyword', ''));
        $page    = (int) $request->query('page', 1);

        return http_build_query(array_filter([
            'keyword' => $keyword !== '' ? $keyword : null,
            'page'    => $page > 1 ? $page : null,
        ]));
    }

    /** return 파라미터를 open-redirect 방어하여 안전한 목록 복귀 URL로 (편집 → 목록 유지) */
    private function resolveListUrl(Context $context): string
    {
        $return = (string) ($context->getRequest()->get('return') ?? '');

        return ($return === '/admin/popup/list' || str_starts_with($return, '/admin/popup/list?'))
            ? $return
            : '/admin/popup/list';
    }

    public function saveConfig(array $params, Context $context): JsonResponse
    {
        if ($this->hasPendingMigrations()) {
            return JsonResponse::error('플러그인 설치를 먼저 진행해주세요.');
        }

        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $formData = $request->input('formData') ?? [];

        $result = $this->popupService->saveConfig($domainId, [
            'popup_skin' => $formData['popup_skin'] ?? 'basic',
        ]);

        return $result->isSuccess()
            ? JsonResponse::success(null, $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    public function create(array $params, Context $context): ViewResponse
    {
        return ViewResponse::absoluteView(self::VIEW_PATH . 'Form')->withData([
            'pageTitle' => '팝업 추가',
            'isEdit' => false,
            'popup' => [],
        ]);
    }

    public function edit(array $params, Context $context): ViewResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $popupId = (int) ($params['id'] ?? 0);
        $listUrl = $this->resolveListUrl($context);
        $popup = $this->popupService->getPopup($domainId, $popupId);

        if ($popup === null) {
            return ViewResponse::absoluteView(self::VIEW_PATH . 'Form')->withData([
                'pageTitle' => '팝업 수정',
                'isEdit' => true,
                'popup' => [],
                'error' => '팝업을 찾을 수 없습니다.',
                'listUrl' => $listUrl,
            ]);
        }

        return ViewResponse::absoluteView(self::VIEW_PATH . 'Form')->withData([
            'pageTitle' => '팝업 수정',
            'isEdit' => true,
            'popup' => $popup,
            'listUrl' => $listUrl,
        ]);
    }

    public function store(array $params, Context $context): JsonResponse
    {
        if ($this->hasPendingMigrations()) {
            return JsonResponse::error('플러그인 설치를 먼저 진행해주세요.');
        }

        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();

        $formData = $request->input('formData') ?? [];
        $data = FormHelper::normalizeFormData($formData, $this->getFormSchema());

        // HTML 콘텐츠 (에디터)
        $data['html_content'] = $formData['html_content'] ?? '';

        $popupId = (int) ($data['popup_id'] ?? 0);

        if ($popupId > 0) {
            $result = $this->popupService->update($domainId, $popupId, $data);
        } else {
            $result = $this->popupService->create($domainId, $data);
        }

        return $result->isSuccess()
            ? JsonResponse::success(['redirect' => '/admin/popup/list'], $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    public function delete(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $popupId = (int) ($params['id'] ?? 0);
        $result = $this->popupService->delete($domainId, $popupId);

        return $result->isSuccess()
            ? JsonResponse::success(['redirect' => '/admin/popup/list'], $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    public function listDelete(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $ids = $request->input('chk') ?? [];

        if (empty($ids)) {
            return JsonResponse::error('삭제할 팝업을 선택해주세요.');
        }

        $deleted = 0;
        foreach ($ids as $id) {
            $result = $this->popupService->delete($domainId, (int) $id);
            if ($result->isSuccess()) {
                $deleted++;
            }
        }

        return $deleted > 0
            ? JsonResponse::success(['redirect' => '/admin/popup/list'], "{$deleted}건이 삭제되었습니다.")
            : JsonResponse::error('삭제할 팝업이 없습니다.');
    }

    public function sort(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $items = $request->input('items') ?? $request->json('items') ?? [];

        if (empty($items)) {
            return JsonResponse::error('정렬할 항목이 없습니다.');
        }

        $result = $this->popupService->updateOrder($domainId, $items);

        return $result->isSuccess()
            ? JsonResponse::success([], $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    /**
     * 프론트 API: 활성 팝업 목록
     */
    public function activePopups(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;

        $popups = $this->popupService->getActivePopups($domainId);

        // 프론트에 필요한 필드만 반환 (html_content는 렌더링용이므로 포함)
        $items = array_map(function (array $popup) {
            return [
                'popup_id' => (int) $popup['popup_id'],
                'title' => $popup['title'] ?? '',
                'html_content' => $popup['html_content'] ?? '',
                'link_url' => $popup['link_url'] ?? null,
                'link_target' => $popup['link_target'] ?? '_self',
                'position' => $popup['position'] ?? 'center',
                'width' => (int) ($popup['width'] ?? 500),
                'height' => (int) ($popup['height'] ?? 0),
                'display_device' => $popup['display_device'] ?? 'all',
                'hide_duration' => (int) ($popup['hide_duration'] ?? 24),
            ];
        }, $popups);

        return JsonResponse::success(['popups' => $items]);
    }

    public function install(array $params, Context $context): JsonResponse
    {
        $result = $this->migrationRunner->run('plugin', self::PLUGIN_NAME, $this->getMigrationPath());

        if ($result['success']) {
            return JsonResponse::success(
                ['redirect' => '/admin/popup/list'],
                '팝업 플러그인이 설치되었습니다.'
            );
        }

        return JsonResponse::error('설치 실패: ' . ($result['error'] ?? '알 수 없는 오류'));
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
        return MUBLO_PLUGIN_PATH . '/Popup/database/migrations';
    }

    private function getFormSchema(): array
    {
        return [
            'numeric' => ['popup_id', 'width', 'height', 'sort_order', 'hide_duration'],
            'bool' => ['is_active'],
            'date' => ['start_date', 'end_date'],
            'enum' => [
                'link_target' => ['values' => ['_self', '_blank'], 'default' => '_self'],
                'position' => ['values' => ['center', 'top-left', 'top-right', 'bottom-left', 'bottom-right'], 'default' => 'center'],
                'display_device' => ['values' => ['all', 'pc', 'mo'], 'default' => 'all'],
            ],
        ];
    }
}
