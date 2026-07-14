<?php

namespace Mublo\Controller\Admin;

use Mublo\Core\Response\ViewResponse;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Context\Context;
use Mublo\Core\Dashboard\DashboardWidgetRegistry;
use Mublo\Core\Dashboard\DashboardLayoutManager;
use Mublo\Core\Dashboard\SlotGridArranger;
use Mublo\Service\Auth\AuthService;
use Mublo\Infrastructure\Log\Logger;

class DashboardController
{
    private AuthService $authService;
    private DashboardWidgetRegistry $registry;
    private DashboardLayoutManager $layoutManager;
    private SlotGridArranger $arranger;
    private ?Logger $logger;

    public function __construct(
        AuthService $authService,
        DashboardWidgetRegistry $registry,
        DashboardLayoutManager $layoutManager,
        SlotGridArranger $arranger,
        ?Logger $logger = null
    ) {
        $this->authService = $authService;
        $this->registry = $registry;
        $this->layoutManager = $layoutManager;
        $this->arranger = $arranger;
        $this->logger = $logger;
    }

    /**
     * 대시보드 메인
     *
     * GET /admin
     * GET /admin/dashboard
     */
    public function index(array $params, Context $context): ViewResponse
    {
        $user = $this->authService->user();
        $domainId = $context->getDomainId() ?? 1;
        $userId = (int) ($user['member_id'] ?? 0);

        // 1. Registry에서 전체 위젯 수집
        $allWidgets = $this->registry->all();

        // 2. 권한 필터링
        $widgets = array_filter($allWidgets, fn($w) => $w['widget']->canView($context));

        // 3. 사용자 레이아웃 로드 (sanitize 포함)
        $layout = $this->layoutManager->load($domainId, $userId);

        // 4. 숨김 위젯 제외
        $visibleWidgets = $this->layoutManager->filterHidden($widgets, $layout);

        // 5. 모드별 정렬
        $mode = $this->layoutManager->getModeFromLayout($layout);
        if ($mode === 'MANUAL') {
            $grid = $this->layoutManager->arrangeManual($visibleWidgets, $layout);
        } else {
            $grid = $this->arranger->arrange(array_values($visibleWidgets));
        }

        // 6. 위젯 렌더링 (에러 격리)
        $renderedGrid = $this->renderGrid($grid);

        // 7. 에셋 수집
        $styles = [];
        $scripts = [];
        foreach ($visibleWidgets as $entry) {
            foreach ($entry['widget']->assets() as $asset) {
                $src = $asset['src'] ?? '';
                if (!$src) continue;
                if (($asset['type'] ?? '') === 'style') {
                    $styles[$src] = $src;
                } else {
                    $scripts[$src] = $src;
                }
            }
        }

        // 8. 숨긴 위젯 목록
        $hiddenWidgets = $this->layoutManager->getHiddenWidgets($layout);

        return ViewResponse::view('dashboard/index')
            ->withData([
                'pageTitle'     => '관리자 대시보드',
                'user'          => $user,
                'grid'          => $renderedGrid,
                'hiddenWidgets' => $hiddenWidgets,
                'assetStyles'   => array_values($styles),
                'assetScripts'  => array_values($scripts),
                'mode'          => $mode,
            ]);
    }

    /**
     * 위젯 숨김
     *
     * POST /admin/dashboard/widget/hide
     */
    public function hideWidget(array $params, Context $context): JsonResponse
    {
        $user = $this->authService->user();
        $domainId = $context->getDomainId() ?? 1;
        $userId = (int) ($user['member_id'] ?? 0);

        $request = $context->getRequest();
        $widgetId = $request->json('widget_id') ?? '';

        if (!$widgetId || !$this->registry->has($widgetId)) {
            return JsonResponse::error('유효하지 않은 위젯입니다.');
        }

        $this->layoutManager->hideWidget($domainId, $userId, $widgetId);

        return JsonResponse::success(null, '위젯이 숨겨졌습니다.');
    }

    /**
     * 위젯 복원
     *
     * POST /admin/dashboard/widget/show
     */
    public function showWidget(array $params, Context $context): JsonResponse
    {
        $user = $this->authService->user();
        $domainId = $context->getDomainId() ?? 1;
        $userId = (int) ($user['member_id'] ?? 0);

        $request = $context->getRequest();
        $widgetId = $request->json('widget_id') ?? '';

        if (!$widgetId || !$this->registry->has($widgetId)) {
            return JsonResponse::error('유효하지 않은 위젯입니다.');
        }

        $this->layoutManager->showWidget($domainId, $userId, $widgetId);

        return JsonResponse::success(null, '위젯이 복원되었습니다.');
    }

    /**
     * 위젯 이동
     *
     * POST /admin/dashboard/widget/move
     */
    public function moveWidget(array $params, Context $context): JsonResponse
    {
        $user = $this->authService->user();
        $domainId = $context->getDomainId() ?? 1;
        $userId = (int) ($user['member_id'] ?? 0);

        $request = $context->getRequest();
        $widgetId = $request->json('widget_id') ?? '';
        $direction = $request->json('direction') ?? '';

        if (!$widgetId || !$this->registry->has($widgetId)) {
            return JsonResponse::error('유효하지 않은 위젯입니다.');
        }

        if (!in_array($direction, ['up', 'down'], true)) {
            return JsonResponse::error('유효하지 않은 방향입니다.');
        }

        $moved = $this->layoutManager->moveWidget($domainId, $userId, $widgetId, $direction);

        if (!$moved) {
            return JsonResponse::error('더 이상 이동할 수 없습니다.');
        }

        return JsonResponse::success(null, '위젯이 이동되었습니다.');
    }

    /**
     * 위젯 순서 재배치 (드래그앤드롭)
     *
     * POST /admin/dashboard/layout/reorder
     */
    public function reorderWidgets(array $params, Context $context): JsonResponse
    {
        $user = $this->authService->user();
        $domainId = $context->getDomainId() ?? 1;
        $userId = (int) ($user['member_id'] ?? 0);

        $request = $context->getRequest();
        $widgetIds = $request->json('widget_ids') ?? [];

        if (empty($widgetIds) || !is_array($widgetIds)) {
            return JsonResponse::error('유효하지 않은 요청입니다.');
        }

        $this->layoutManager->reorder($domainId, $userId, $widgetIds);

        return JsonResponse::success(null, '위젯 순서가 변경되었습니다.');
    }

    /**
     * 레이아웃 초기화
     *
     * POST /admin/dashboard/layout/reset
     */
    public function resetLayout(array $params, Context $context): JsonResponse
    {
        $user = $this->authService->user();
        $domainId = $context->getDomainId() ?? 1;
        $userId = (int) ($user['member_id'] ?? 0);

        $this->layoutManager->resetLayout($domainId, $userId);

        return JsonResponse::success(null, '기본 레이아웃으로 복원되었습니다.');
    }

    /**
     * 위젯 그리드 렌더링 (에러 격리)
     */
    private function renderGrid(array $grid): array
    {
        $isDebug = (bool) ($_ENV['APP_DEBUG'] ?? false);
        $rendered = [];

        foreach ($grid as $rowIndex => $row) {
            foreach ($row as $entry) {
                $widget = $entry['widget'];
                try {
                    $html = $widget->render();
                } catch (\Throwable $e) {
                    $errorMsg = $isDebug
                        ? htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')
                        : '위젯을 불러올 수 없습니다.';

                    $html = '<div class="text-muted text-center py-4">'
                          . '<i class="bi bi-exclamation-triangle me-1"></i>'
                          . $errorMsg
                          . '</div>';

                    $this->logger?->warning('Dashboard widget render failed', [
                        'widget_id' => $widget->id(),
                        'error'     => $e->getMessage(),
                    ]);
                }

                $rendered[$rowIndex][] = [
                    'widget_id' => $widget->id(),
                    'title'     => $widget->title(),
                    'html'      => $html,
                    'slot'      => $entry['slot'],
                    'colClass'  => $entry['colClass'],
                ];
            }
        }

        return $rendered;
    }
}
