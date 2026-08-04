<?php
declare(strict_types=1);
namespace Mublo\Controller\Admin;

use Mublo\Core\Response\ViewResponse;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Response\HtmlResponse;
use Mublo\Core\Context\Context;
use Mublo\Core\Block\BlockRegistry;
use Mublo\Core\Container\DependencyContainer;
use Mublo\Enum\Block\BlockPosition;
use Mublo\Helper\View\FrontPreviewTokensHelper;
use Mublo\Service\Block\BlockKitApplier;
use Mublo\Service\Block\BlockKitExporter;
use Mublo\Service\Block\MainScreenComposition;
use Mublo\Service\Block\BlockRowService;
use Mublo\Service\Block\BlockColumnService;
use Mublo\Service\System\InstallIdProvider;
use Mublo\Service\Block\BlockPageService;
use Mublo\Service\Block\BlockSkinService;
use Mublo\Service\Block\BlockPreviewService;
use Mublo\Service\Block\BlockRowPayloadMapper;
use Mublo\Service\Block\BlockImageMutationPlan;
use Mublo\Service\Block\BlockColumnWriteContext;
use Mublo\Service\Block\BlockColumnWriteContextFactory;
use Mublo\Service\Block\BlockColumnWriteGuard;
use Mublo\Service\Menu\MenuService;
use Mublo\Service\Auth\AuthService;
use Mublo\Service\Domain\DomainSettingsService;
use Mublo\Helper\Form\FormHelper;

/**
 * Admin BlockRowController
 *
 * 블록 행 관리 컨트롤러
 *
 * 자동 라우팅:
 * - GET  /admin/block-row              → index
 * - GET  /admin/block-row/create       → create
 * - GET  /admin/block-row/edit         → edit (쿼리: ?id=123)
 * - POST /admin/block-row/store        → store
 * - POST /admin/block-row/delete       → delete
 * - POST /admin/block-row/order-update → orderUpdate
 * - GET  /admin/block-row/get-columns  → getColumns (AJAX)
 */
class BlockRowController
{
    use BlockKitControllerTrait;

    /** "메인화면 구성" 필터의 특별 값. 개별 position 값과 겹치지 않게 접두어를 둔다. */
    private const SCREEN_MAIN = 'screen:main';

    private BlockRowService $rowService;
    private BlockColumnService $columnService;
    private BlockPageService $pageService;
    private BlockSkinService $skinService;
    private BlockPreviewService $previewService;
    private MenuService $menuService;
    private BlockRowPayloadMapper $payloadMapper;
    private BlockColumnWriteContextFactory $writeContextFactory;
    private BlockColumnWriteGuard $writeGuard;
    private \Mublo\Infrastructure\Database\Database $db;
    private DependencyContainer $container;

    public function __construct(
        BlockRowService $rowService,
        BlockColumnService $columnService,
        BlockPageService $pageService,
        BlockSkinService $skinService,
        BlockPreviewService $previewService,
        MenuService $menuService,
        BlockRowPayloadMapper $payloadMapper,
        BlockColumnWriteContextFactory $writeContextFactory,
        BlockColumnWriteGuard $writeGuard,
        \Mublo\Infrastructure\Database\Database $db,
        DependencyContainer $container
    ) {
        $this->rowService = $rowService;
        $this->columnService = $columnService;
        $this->pageService = $pageService;
        $this->skinService = $skinService;
        $this->previewService = $previewService;
        $this->menuService = $menuService;
        $this->payloadMapper = $payloadMapper;
        $this->writeContextFactory = $writeContextFactory;
        $this->writeGuard = $writeGuard;
        $this->db = $db;
        $this->container = $container;
    }

    /**
     * position 또는 메인화면 복합 블록 킷 내보내기
     *
     * POST /admin/block-row/export
     *
     * menuCode는 target에 함께 기록된다 — 그러지 않으면 replace 모드가
     * 무관한 메뉴의 행까지 지우게 된다(설계 6.2).
     */
    public function export(array $params, Context $context): JsonResponse|HtmlResponse
    {
        if ($denied = $this->denyUnlessDomainOperator()) {
            return $denied;
        }

        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();

        $screen = trim((string) $request->input('screen', ''));
        $position = trim((string) $request->input('position', ''));
        $isMainScreen = $screen === 'main';

        if (!$isMainScreen && ($position === '' || !BlockPosition::tryFrom($position))) {
            return JsonResponse::error('유효한 위치가 필요합니다.');
        }
        if ($screen !== '' && !$isMainScreen) {
            return JsonResponse::error('지원하지 않는 화면입니다.');
        }

        $menuCode = trim((string) $request->input('position_menu', '')) ?: null;
        $mode = $request->input('export_mode') === 'clone' ? 'clone' : 'distribution';

        $options = [
            'export_mode' => $mode,
            'include_content_items_by_column' => (array) $request->input('include_content_items_by_column', []),
        ];

        // 배포용 블록 킷에는 설치 정보를 남기지 않는다(설계 7.2)
        if ($mode === 'clone') {
            /** @var InstallIdProvider $installId */
            $installId = $this->container->get(InstallIdProvider::class);
            $options['source_install'] = $installId->getHash();
        }

        /** @var BlockKitExporter $exporter */
        $exporter = $this->container->get(BlockKitExporter::class);

        $meta = [
            'name' => trim((string) $request->input('kit_name', '')),
            'description' => trim((string) $request->input('kit_description', '')),
            'version' => trim((string) $request->input('kit_version', '')) ?: '1.0.0',
            'author' => trim((string) $request->input('kit_author', '')),
            'author_url' => trim((string) $request->input('kit_author_url', '')),
            'requires_core' => trim((string) $request->input('kit_requires_core', '')),
            'screenshot' => $this->collectKitScreenshot(),
        ];

        // 도메인 격리 — 블록 킷은 현재 도메인의 행과 설정만 내보낸다(설계 9.5)
        if ($isMainScreen) {
            /** @var DomainSettingsService $settingsService */
            $settingsService = $this->container->get(DomainSettingsService::class);
            $siteConfig = $settingsService->getSiteConfig($domainId);
            $kit = $exporter->exportMainScreen($domainId, $siteConfig, $meta, $options);
            $fileBase = 'main-screen';
        } else {
            $kit = $exporter->exportPosition($domainId, $position, $menuCode, $meta, $options);
            $fileBase = $position . ($menuCode ? '-' . $menuCode : '');
        }

        $json = json_encode($kit, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return (new HtmlResponse($json))
            ->withHeader('Content-Type', 'application/json; charset=UTF-8')
            ->withHeader('Content-Disposition', $this->kitDownloadDisposition($fileBase));
    }

    /**
     * 블록 킷 미리보기 (dry-run)
     *
     * POST /admin/block-row/kit-preview
     *
     * 적용을 즉시 실행하지 않고 무엇이 몇 개 생기는지, 어떤 확장이 빠졌는지 먼저 보여준다(설계 6.1).
     */
    public function kitPreview(array $params, Context $context): JsonResponse
    {
        if ($denied = $this->denyUnlessDomainOperator()) {
            return $denied;
        }

        $json = $this->readKitUpload();
        if ($json instanceof JsonResponse) {
            return $json;
        }

        /** @var BlockKitApplier $applier */
        $applier = $this->container->get(BlockKitApplier::class);
        $result = $applier->dryRunFromJson($json);

        // normalized_rows 는 내부 표현이라 화면으로 내보내지 않는다
        unset($result['normalized_rows']);

        return JsonResponse::success($result);
    }

    /**
     * 블록 킷 적용
     *
     * POST /admin/block-row/kit-apply
     */
    public function kitApply(array $params, Context $context): JsonResponse
    {
        if ($denied = $this->denyUnlessDomainOperator()) {
            return $denied;
        }

        $json = $this->readKitUpload();
        if ($json instanceof JsonResponse) {
            return $json;
        }

        $kit = json_decode($json, true);
        if (!is_array($kit)) {
            return JsonResponse::error('유효한 JSON 블록 킷이 아닙니다.');
        }

        $request = $context->getRequest();

        $mode = $request->input('mode') === BlockKitApplier::MODE_REPLACE
            ? BlockKitApplier::MODE_REPLACE
            : BlockKitApplier::MODE_APPEND;

        // 레이아웃 설정 변경은 별도 옵트인이다 — 이미 우사이드를 쓰는 사이트는
        // 설정을 건드릴 이유가 없다(설계 5.4 ⑥).
        $applySiteSettings = !empty($request->input('apply_site_settings'));

        /** @var BlockKitApplier $applier */
        $applier = $this->container->get(BlockKitApplier::class);

        // 도메인 격리 — 블록 킷 JSON 의 domain_id 는 무시된다(설계 9.5)
        $authService = $this->container->get(AuthService::class);
        $result = $applier->apply(
            $context->getDomainId() ?? 1,
            $kit,
            $mode,
            $applySiteSettings,
            $authService->isSuper()
        );

        if (!$result['ok']) {
            return JsonResponse::error(implode("\n", $result['errors']), $result);
        }

        return JsonResponse::success($result, '블록 킷이 적용되었습니다.');
    }


    /**
     * 행 목록
     *
     * GET /admin/block-row
     */
    public function index(array $params, Context $context): ViewResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();

        // 필터
        $position = $request->query('position');
        $pageId = (int) $request->query('page_id', 0);

        // "메인화면 구성"은 특별 필터다. 개별 위치가 아니라, 방문자가 메인화면을 볼 때
        // 실제로 조립되는 여러 슬롯(index + 사이드바 등)의 행을 한 화면으로 모은다.
        $screen = $position === self::SCREEN_MAIN ? 'main' : null;
        if ($screen !== null) {
            $position = null;
        }

        // 메뉴 필터는 위치 필터에 종속된다. 위치가 없거나 index면 의미가 없다(메인화면은
        // 메뉴 개념이 없다 — 편집 폼도 index 일 때 메뉴 셀렉트를 숨긴다).
        $menu = $screen === null && $position && $position !== 'index'
            ? trim((string) $request->query('menu', ''))
            : '';

        // 순서(sort_order) 관리 중심 화면이므로 페이지네이션 없이 전량 출력.
        // 목록 범위는 위치/페이지 필터로 좁힌다.
        if ($pageId > 0) {
            // 페이지 기반 행 조회 — 다른 도메인 page_id는 빈 범위로 처리한다.
            $page = $this->pageService->getPage($pageId, $domainId);
            $rows = $page ? $this->rowService->getRowsByPage($pageId) : [];
        } elseif ($screen === 'main') {
            // 메인화면에 조립되는 슬롯들의 행을 조립 순서대로 모은다.
            $rows = $this->collectMainScreenRows($domainId, $context->getDomainInfo()?->getSiteConfig() ?? []);
        } else {
            // 위치 기반 행 조회 (메뉴를 주면 전역 + 해당 메뉴로 좁힘)
            $rows = $this->rowService->getRowsByPosition($domainId, $position ?: null, $menu ?: null);
        }

        // 블록 킷 내보내기 폼에서 "포함할 참조" 체크박스를 그릴 칸 목록.
        // 이식 불가로 선언된 타입은 어느 모드에서든 비우므로 후보에서 제외한다(설계 7.4).
        $kitItemCandidates = [];

        // 행 데이터 가공
        $rowsData = [];
        foreach ($rows as $row) {
            $rowData = $row->toArray();
            $columns = $this->columnService->getColumnsByRowForDomain($row->getRowId(), $domainId) ?? [];
            $rowData['column_count_actual'] = count($columns);

            foreach ($columns as $col) {
                $type = $col->getContentTypeString();
                if (!$type || !BlockRegistry::isKitPortable($type) || empty($col->getContentItems())) {
                    continue;
                }

                $kitItemCandidates[] = [
                    'column_id' => $col->getColumnId(),
                    'row_title' => $row->getAdminTitle() ?: ('행 #' . $row->getRowId()),
                    'column_index' => $col->getColumnIndex(),
                    'content_type' => $type,
                    'item_count' => count($col->getContentItems()),
                ];
            }

            // 각 칸의 콘텐츠 유무 배열 (true: 콘텐츠 있음, false: 없음)
            $rowData['column_has_content'] = array_map(
                fn($col) => $col->hasContent(),
                $columns
            );

            // 이 행에서 어떤 블록 타입이 쓰이는지 집계한다(고유 타입 + 개수).
            // 라벨은 BlockRegistry 에서 얻는다 — 미설치 확장이면 null 이라 코드로 폴백한다.
            $usage = [];
            $tally = function (?string $type, string $kind) use (&$usage): void {
                if (!$type) {
                    return; // 빈 칸은 "칸" 열이 이미 시각화한다.
                }
                if (!isset($usage[$type])) {
                    $registered = BlockRegistry::getContentType($type);
                    $usage[$type] = [
                        'type' => $type,
                        'kind' => $kind,
                        'label' => $registered['title'] ?? $type,
                        'installed' => $registered !== null,
                        'count' => 0,
                    ];
                }
                $usage[$type]['count']++;
            };
            foreach ($columns as $col) {
                if ($col->isStack()) {
                    // 스택 칸 — 레거시 미러(첫 활성)만 세면 나머지 콘텐츠가 누락된다
                    foreach ($this->columnService->getStackContents($col) as $content) {
                        $tally($content->getContentTypeString(), $content->getContentKind()->value);
                    }
                    continue;
                }
                $tally($col->getContentTypeString(), $col->getContentKind()->value);
            }
            $rowData['block_usage'] = array_values($usage);

            $rowsData[] = $rowData;
        }

        /** @var AuthService $authService */
        $authService = $this->container->get(AuthService::class);

        // 메뉴(서브페이지) 필터 셀렉트용. 편집 폼과 같은 트리를 쓴다.
        $menuOptions = $this->buildMenuOptions($domainId);

        return ViewResponse::view('blockrow/index')
            ->withData([
                'pageTitle' => '블록 행 관리',
                'rows' => $rowsData,
                'positions' => $this->rowService->getValidPositions(),
                'pages' => $this->pageService->getSelectOptions($domainId),
                'currentPosition' => $position,
                'currentPageId' => $pageId,
                'currentScreen' => $screen,
                'menuOptions' => $menuOptions,
                'currentMenu' => $menu,
                // 목록 "출력 대상" 열에서 menu_code 를 라벨로 바꾸기 위한 code→label 맵.
                'menuLabels' => $this->flattenMenuLabels($menuOptions),
                // 블록 킷은 도메인 운영자 이상(설계 9.4). 메뉴는 보이되 화면 안에서 제한한다.
                'canExportKit' => $authService->canOperateDomain(),
                'kitItemCandidates' => $kitItemCandidates,
                // 행 미리보기 iframe 이 프론트 토큰 캐스케이드를 재현하는 데 필요한 값 (뷰가 전역으로 노출)
                'frontPreviewTokens' => FrontPreviewTokensHelper::forContext($context),
            ]);
    }

    /**
     * 행 생성 폼
     *
     * GET /admin/block-row/create
     */
    public function create(array $params, Context $context): ViewResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();

        // 기본 위치 또는 페이지 ID
        $position = $request->query('position', BlockPosition::INDEX->value);
        $pageId = (int) $request->query('page_id', 0);
        $pages = $this->pageService->getSelectOptions($domainId);

        $isPageBased = $pageId > 0;
        $currentPageLabel = $this->findPageLabel($pages, $pageId);
        [$contentTypes, $contentTypeGroups, $canEditInclude] = $this->contentTypeUiData();

        return ViewResponse::view('blockrow/form')
            ->withData([
                'pageTitle' => '블록 행 추가',
                'isEdit' => false,
                'rowData' => [],
                'rowId' => 0,
                'position' => $position,
                'pageId' => $pageId,
                'columnCount' => 1,
                'isPageBased' => $isPageBased,
                'currentPageLabel' => $currentPageLabel,
                'bgConfig' => [],
                'columns' => [],
                'positions' => $this->rowService->getValidPositions(),
                'pages' => $pages,
                'contentTypes' => $contentTypes,
                'contentTypeGroups' => $contentTypeGroups,
                'canEditInclude' => $canEditInclude,
                'skinLists' => $this->skinService->getAllSkinLists($context->isSuperDomain()),
                'menuOptions' => $this->buildMenuOptions($domainId),
                'domainId' => $domainId,
                'filterPosition' => (string) $request->query('filter_position', ''),
                // 칸 에디터·미리보기가 프론트 토큰 캐스케이드를 재현하는 데 필요한 값 (뷰가 전역으로 노출)
                'frontPreviewTokens' => FrontPreviewTokensHelper::forContext($context),
            ]);
    }

    /**
     * 행 수정 폼
     *
     * GET /admin/block-row/edit?id=123
     */
    public function edit(array $params, Context $context): ViewResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $rowId = (int) $request->query('id', 0);

        if ($rowId === 0 && isset($params[0])) {
            $rowId = (int) $params[0];
        }

        $row = $this->rowService->getRow($rowId);

        if (!$row) {
            return ViewResponse::view('Error/404')
                ->withData(['message' => '행을 찾을 수 없습니다.']);
        }

        // 도메인 소유권 검증
        if ($row->getDomainId() !== $domainId) {
            return ViewResponse::view('Error/404')
                ->withData(['message' => '행을 찾을 수 없습니다.']);
        }

        // 칸 목록 — 스택 칸은 하위 콘텐츠 포함 (관리자 편집은 비활성도 포함)
        $columns = $this->columnService->getColumnsByRowForDomain($rowId, $domainId) ?? [];
        $columnsData = $this->columnService->toEditableArrays($columns, $domainId);

        // 폼 데이터 준비
        $data = $row->toArray();
        $position = $data['position'] ?? $row->getPosition();
        $pageId = (int) ($data['page_id'] ?? $row->getPageId());
        $columnCount = (int) ($data['column_count'] ?? 1);
        $pages = $this->pageService->getSelectOptions($domainId);

        $isPageBased = $pageId > 0;
        $currentPageLabel = $this->findPageLabel($pages, $pageId);

        // 배경 설정 파싱
        $bgConfig = $data['background_config'] ?? [];
        if (is_string($bgConfig)) {
            $bgConfig = json_decode($bgConfig, true) ?? [];
        }

        // 임베드 모드 — 블록 에디터가 모달 iframe 으로 띄운다(블록 에디터 설계 6).
        // 관리자 셸 없이 폼만 렌더하고, 저장 시 리다이렉트 대신 부모에 postMessage 한다.
        $embedMode = $request->query('_embed') === '1';
        [$contentTypes, $contentTypeGroups, $canEditInclude] = $this->contentTypeUiData();

        $response = ViewResponse::view('blockrow/form')
            ->withData([
                'pageTitle' => '블록 행 수정',
                'isEdit' => true,
                'rowData' => $data,
                'rowId' => $rowId,
                'position' => $position,
                'pageId' => $pageId,
                'columnCount' => $columnCount,
                'isPageBased' => $isPageBased,
                'currentPageLabel' => $currentPageLabel,
                'bgConfig' => $bgConfig,
                'columns' => $columnsData,
                'positions' => $this->rowService->getValidPositions(),
                'pages' => $pages,
                'contentTypes' => $contentTypes,
                'contentTypeGroups' => $contentTypeGroups,
                'canEditInclude' => $canEditInclude,
                'skinLists' => $this->skinService->getAllSkinLists($context->isSuperDomain()),
                'menuOptions' => $this->buildMenuOptions($domainId),
                'domainId' => $domainId,
                'filterPosition' => (string) $request->query('filter_position', ''),
                'embedMode' => $embedMode,
                'openColumn' => $embedMode ? (int) $request->query('open_column', -1) : -1,
                // 칸 에디터·미리보기가 프론트 토큰 캐스케이드를 재현하는 데 필요한 값 (뷰가 전역으로 노출)
                'frontPreviewTokens' => FrontPreviewTokensHelper::forContext($context),
            ]);

        if ($embedMode) {
            $response->fullPage();
        }

        return $response;
    }

    /**
     * 행 저장 (생성/수정)
     *
     * POST /admin/block-row/store
     */
    public function store(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();

        $formData = $request->input('formData') ?? [];

        // 목록 복귀 시 유지할 컨텍스트 (행 데이터 아님 → 폼 최상위 필드에서 읽음)
        // - activeCode: 전역 스크립트(Foot.php)가 모든 폼에 자동 주입
        // - filter_position: 목록의 위치 필터 (프로그래밍적 redirect는 전역 처리 대상 밖)
        $activeCode = (string) ($request->input('activeCode') ?? '');
        $filterPosition = (string) ($request->input('filter_position') ?? '');

        $uploadErrors = [];
        $imageMutation = new BlockImageMutationPlan();

        // 배경 설정 필드를 background_config JSON으로 통합
        $formData = $this->payloadMapper->mapBackground(
            $formData,
            $domainId,
            $request->getRawFile('bg_image'),
            $imageMutation,
            $uploadErrors
        );

        $data = FormHelper::normalizeFormData($formData, $this->getFormSchema());

        // 칸 데이터 처리 (이미지 업로드 실패를 수집)
        $columnsData = $this->payloadMapper->mapColumns(
            $request->input('columns') ?? [],
            $domainId,
            $request->getRawFile('column_images'),
            $request->getRawFile('column_title_image'),
            $imageMutation,
            $uploadErrors,
            $request->getRawFile('column_content_images'),
            $request->getRawFile('column_content_title_images')
        );

        // 이미지 업로드가 하나라도 실패하면 행을 저장하지 않고 알린다.
        // (칸 이미지는 업로드 성공 후에만 교체·삭제하므로 기존 데이터는 안전)
        if (!empty($uploadErrors)) {
            $this->payloadMapper->rollbackImages($imageMutation);
            return JsonResponse::error("이미지 업로드에 실패했습니다:\n" . implode("\n", $uploadErrors));
        }

        $writeContext = $this->writeContextFactory->create(
            $domainId,
            BlockColumnWriteContext::SOURCE_INTERACTIVE
        );
        $permissionError = $this->writeGuard->firstViolation($columnsData, $writeContext);
        if ($permissionError !== null) {
            $this->payloadMapper->rollbackImages($imageMutation);
            return JsonResponse::error($permissionError);
        }

        $rowId = (int) ($data['row_id'] ?? 0);
        $authUser = $this->container->get(AuthService::class)->user();
        $actorId = isset($authUser['member_id']) ? (int) $authUser['member_id'] : null;

        try {
            if ($rowId > 0) {
                // 수정 (도메인 소유권 검증 포함)
                $result = $this->rowService->updateRow(
                    $rowId,
                    $data,
                    $columnsData,
                    $domainId,
                    $writeContext,
                    isset($data['revision_no']) ? (int) $data['revision_no'] : null,
                    $actorId
                );
            } else {
                // 생성
                $result = $this->rowService->createRow(
                    $domainId,
                    $data,
                    $columnsData,
                    $writeContext
                );
            }
        } catch (\Throwable $e) {
            $this->payloadMapper->rollbackImages($imageMutation);
            throw $e;
        }

        if ($result->isSuccess()) {
            $this->payloadMapper->commitImages($imageMutation);
            // 목록 복귀 URL: 페이지 기반이면 page_id, 위치 기반이면 위치 필터 유지.
            // activeCode(메뉴 컨텍스트)도 함께 전달해 사이드바 하이라이트를 보존.
            $pageId = (int) ($data['page_id'] ?? 0);
            $query = [];
            if ($pageId > 0) {
                $query['page_id'] = $pageId;
            } elseif ($filterPosition !== '') {
                $query['position'] = $filterPosition;
            }
            if ($activeCode !== '') {
                $query['activeCode'] = $activeCode;
            }
            $redirect = '/admin/block-row' . (!empty($query) ? '?' . http_build_query($query) : '');

            return JsonResponse::success(
                [
                    'redirect' => $redirect,
                    // 블록 에디터가 생성 직후 행을 선택·재배치하는 데 쓴다.
                    'row_id' => $rowId > 0 ? $rowId : (int) $result->get('row_id', 0),
                    'warnings' => $result->get('warnings', []),
                ],
                $result->getMessage()
            );
        }

        $this->payloadMapper->rollbackImages($imageMutation);

        return JsonResponse::error(
            $result->getMessage(),
            $result->getData(),
            $result->get('conflict', false) ? 409 : 400
        );
    }

    /**
     * 행 삭제
     *
     * POST /admin/block-row/delete
     */
    public function delete(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $rowId = (int) $request->json('row_id', 0);

        if ($rowId <= 0) {
            return JsonResponse::error('행 ID가 필요합니다.');
        }

        $authUser = $this->container->get(AuthService::class)->user();
        $actorId = isset($authUser['member_id']) ? (int) $authUser['member_id'] : null;
        $result = $this->rowService->deleteRow($rowId, $domainId, $actorId);

        if ($result->isSuccess()) {
            return JsonResponse::success(null, $result->getMessage());
        }

        return JsonResponse::error($result->getMessage());
    }

    /** GET /admin/block-row/revisions?row_id=123 */
    public function revisions(array $params, Context $context): JsonResponse
    {
        if ($denied = $this->denyUnlessDomainOperator('블록 행 이력은')) {
            return $denied;
        }

        $domainId = $context->getDomainId() ?? 1;
        $rowId = (int) $context->getRequest()->query('row_id', 0);
        if ($rowId <= 0) {
            return JsonResponse::error('행 ID가 필요합니다.');
        }

        return JsonResponse::success([
            'items' => $this->rowService->getRevisions($rowId, $domainId),
        ]);
    }

    /** GET /admin/block-row/deleted-revisions */
    public function deletedRevisions(array $params, Context $context): JsonResponse
    {
        if ($denied = $this->denyUnlessDomainOperator('블록 행 삭제 이력은')) {
            return $denied;
        }

        return JsonResponse::success([
            'items' => $this->rowService->getDeletedRevisions($context->getDomainId() ?? 1),
        ]);
    }

    /** POST /admin/block-row/restore-revision */
    public function restoreRevision(array $params, Context $context): JsonResponse
    {
        if ($denied = $this->denyUnlessDomainOperator('블록 행 복구는')) {
            return $denied;
        }

        $request = $context->getRequest();
        $domainId = $context->getDomainId() ?? 1;
        $revisionId = (int) $request->input('revision_id', $request->json('revision_id', 0));
        $expectedRevision = (int) $request->input('revision_no', $request->json('revision_no', 0));
        $authUser = $this->container->get(AuthService::class)->user();
        $actorId = isset($authUser['member_id']) ? (int) $authUser['member_id'] : null;

        if ($revisionId <= 0) {
            return JsonResponse::error('복구할 이력을 선택해 주세요.');
        }

        $result = $this->rowService->restoreRevision(
            $domainId,
            $revisionId,
            $expectedRevision > 0 ? $expectedRevision : null,
            $actorId,
            $this->container->get(AuthService::class)->isSuper()
        );
        if ($result->isFailure()) {
            return JsonResponse::error(
                $result->getMessage(),
                $result->getData(),
                $result->get('conflict', false) ? 409 : 400
            );
        }

        return JsonResponse::success($result->getData(), $result->getMessage());
    }

    /**
     * 행 캐시 갱신 (단건)
     *
     * POST /admin/block-row/cache-refresh
     */
    public function cacheRefresh(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $rowId = (int) $request->json('row_id', 0);

        if ($rowId <= 0) {
            return JsonResponse::error('행 ID가 필요합니다.');
        }

        $result = $this->rowService->refreshCache($rowId, $domainId);

        if ($result->isSuccess()) {
            return JsonResponse::success(null, $result->getMessage());
        }

        return JsonResponse::error($result->getMessage());
    }

    /**
     * 사용 여부 토글
     *
     * POST /admin/block-row/toggle-active
     */
    public function toggleActive(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $rowId = (int) $request->json('row_id', 0);

        if ($rowId <= 0) {
            return JsonResponse::error('행 ID가 필요합니다.');
        }

        $row = $this->rowService->getRow($rowId);

        if (!$row || $row->getDomainId() !== $domainId) {
            return JsonResponse::error('행을 찾을 수 없습니다.');
        }

        $newActive = !$row->isActive();
        // 부분 업데이트: is_active만 변경 (레이아웃 필드가 기본값으로 덮어써지는 것 방지)
        $result = $this->rowService->setActive($rowId, $newActive, $domainId);

        if ($result->isSuccess()) {
            return JsonResponse::success(
                ['is_active' => $newActive],
                $result->getMessage()
            );
        }

        return JsonResponse::error($result->getMessage());
    }

    /**
     * 정렬 순서 변경
     *
     * POST /admin/block-row/order-update
     */
    public function orderUpdate(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $rowIds = $request->json('row_ids', []);

        if (empty($rowIds) || !is_array($rowIds)) {
            return JsonResponse::error('정렬할 행 목록이 필요합니다.');
        }

        $result = $this->rowService->updateOrder($rowIds, $domainId);

        if ($result->isSuccess()) {
            return JsonResponse::success(null, $result->getMessage());
        }

        return JsonResponse::error($result->getMessage());
    }

    /**
     * 정렬 순서 직접 설정
     *
     * POST /admin/block-row/order-set
     */
    public function orderSet(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $orders = $request->json('orders', []);

        if (empty($orders) || !is_array($orders)) {
            return JsonResponse::error('순서 정보가 필요합니다.');
        }

        $result = $this->rowService->setOrder($orders, $domainId);

        if ($result->isSuccess()) {
            return JsonResponse::success(null, $result->getMessage());
        }

        return JsonResponse::error($result->getMessage());
    }

    /**
     * 칸 목록 조회 (AJAX)
     *
     * GET /admin/block-row/get-columns?row_id=123
     */
    public function getColumns(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $rowId = (int) $request->query('row_id', 0);

        if ($rowId <= 0) {
            return JsonResponse::error('행 ID가 필요합니다.');
        }

        $columns = $this->columnService->getColumnsByRowForDomain($rowId, $domainId);
        if ($columns === null) {
            return JsonResponse::error('행을 찾을 수 없습니다.', null, 404);
        }
        $columnsData = array_map(fn($col) => $col->toArray(), $columns);

        return JsonResponse::success([
            'columns' => $columnsData,
        ]);
    }

    /**
     * 콘텐츠 타입별 아이템 목록 조회 (AJAX)
     *
     * GET /admin/block-row/get-content-items?content_type=board
     *
     * @return JsonResponse { items: [{id: string, label: string}, ...] }
     */
    public function getContentItems(array $params, Context $context): JsonResponse
    {
        $request = $context->getRequest();
        $domainId = $context->getDomainId() ?? 1;
        $contentType = $request->query('content_type', '');

        if (empty($contentType)) {
            return JsonResponse::error('콘텐츠 타입이 필요합니다.');
        }

        $items = $this->fetchContentItems($domainId, $contentType);

        return JsonResponse::success([
            'items' => $items,
            'content_type' => $contentType,
        ]);
    }

    /**
     * 콘텐츠 타입별 아이템 가져오기
     *
     * @return array [{id: string, label: string}, ...]
     */
    private function fetchContentItems(int $domainId, string $contentType): array
    {
        // Core 타입: menu
        if ($contentType === 'menu') {
            $menus = $this->menuService->getItems($domainId, true);
            return array_map(fn($m) => [
                'id' => $m['menu_code'],
                'label' => $m['label'],
            ], $menus);
        }

        // 이벤트로 Package/Plugin에 위임
        $event = $this->container->get(\Mublo\Core\Event\EventDispatcher::class)
            ->dispatch(new \Mublo\Core\Event\Block\BlockContentItemsCollectEvent($domainId, $contentType));

        if ($event->hasItems()) {
            return $event->getItems();
        }

        // Fallback: BlockRegistry의 itemsProvider
        $providerClass = BlockRegistry::getItemsProviderClass($contentType);
        if ($providerClass) {
            try {
                $provider = $this->container->canResolve($providerClass)
                    ? $this->container->get($providerClass)
                    : new $providerClass();
                return $provider->getItems($domainId);
            } catch (\Throwable $e) {
                error_log("BlockItemsProvider failed for {$contentType}: " . $e->getMessage());
                return [];
            }
        }

        return [];
    }

    /**
     * 목록 일괄 삭제
     *
     * POST /admin/block-row/list-delete
     */
    public function listDelete(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $chk = $request->input('chk') ?? [];

        if (empty($chk)) {
            return JsonResponse::error('삭제할 항목을 선택해주세요.');
        }

        $deleted = 0;
        $authUser = $this->container->get(AuthService::class)->user();
        $actorId = isset($authUser['member_id']) ? (int) $authUser['member_id'] : null;

        foreach ($chk as $rowId) {
            $result = $this->rowService->deleteRow((int) $rowId, $domainId, $actorId);
            if ($result->isSuccess()) {
                $deleted++;
            }
        }

        if ($deleted > 0) {
            return JsonResponse::success(
                ['deleted' => $deleted],
                "{$deleted}개 항목이 삭제되었습니다."
            );
        }

        return JsonResponse::error('삭제할 수 있는 항목이 없습니다.');
    }

    /**
     * 목록 일괄 캐시 갱신
     *
     * POST /admin/block-row/list-cache-refresh
     */
    public function listCacheRefresh(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $chk = $request->input('chk') ?? [];

        if (empty($chk)) {
            return JsonResponse::error('갱신할 항목을 선택해주세요.');
        }

        $refreshed = 0;

        foreach ($chk as $rowId) {
            $result = $this->rowService->refreshCache((int) $rowId, $domainId);
            if ($result->isSuccess()) {
                $refreshed++;
            }
        }

        if ($refreshed > 0) {
            return JsonResponse::success(
                ['refreshed' => $refreshed],
                "{$refreshed}개 행의 캐시를 갱신했습니다."
            );
        }

        return JsonResponse::error('갱신할 수 있는 항목이 없습니다.');
    }

    /**
     * 행 복사
     *
     * POST /admin/block-row/copy
     */
    public function copy(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $rowId = (int) $request->json('row_id', 0);
        $targetPosition = $request->json('position');
        $targetPageId = $request->json('page_id') ? (int) $request->json('page_id') : null;

        if ($rowId <= 0) {
            return JsonResponse::error('복사할 행 ID가 필요합니다.');
        }

        // 동일 도메인 내 복사만 허용 (교차 도메인 복사 차단)
        $result = $this->rowService->copyRow($rowId, $targetPosition, $targetPageId, $domainId);

        if ($result->isSuccess()) {
            return JsonResponse::success(
                [
                    'row_id' => $result->get('row_id'),
                    'warnings' => $result->get('warnings', []),
                ],
                $result->getMessage()
            );
        }

        return JsonResponse::error($result->getMessage());
    }

    /**
     * 행 이동
     *
     * POST /admin/block-row/move
     */
    public function move(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $rowId = (int) $request->json('row_id', 0);
        $targetPosition = $request->json('position');
        $targetPageId = $request->json('page_id') ? (int) $request->json('page_id') : null;

        if ($rowId <= 0) {
            return JsonResponse::error('이동할 행 ID가 필요합니다.');
        }

        $result = $this->rowService->moveRow($rowId, $targetPosition, $targetPageId, $domainId);

        if ($result->isSuccess()) {
            return JsonResponse::success(null, $result->getMessage());
        }

        return JsonResponse::error($result->getMessage());
    }

    /**
     * 저장된 행 미리보기 (목록에서 사용)
     *
     * GET /admin/block-row/preview-row?row_id=123
     */
    public function previewRow(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $rowId = (int) ($request->query('row_id') ?? $request->json('row_id') ?? 0);

        if ($rowId <= 0) {
            return JsonResponse::error('행 ID가 필요합니다.');
        }

        $row = $this->rowService->getRow($rowId);
        if (!$row || $row->getDomainId() !== $domainId) {
            return JsonResponse::error('행을 찾을 수 없습니다.');
        }

        // 렌더링 전 에셋 스냅샷
        $assets = $this->container->canResolve(\Mublo\Core\Rendering\AssetManager::class)
            ? $this->container->get(\Mublo\Core\Rendering\AssetManager::class)
            : null;
        $cssBefore = $assets ? $assets->getCssPaths() : [];
        $jsBefore = $assets ? $assets->getJsPaths() : [];

        $html = $this->previewService->renderExistingRowPreview($rowId, $domainId, [], []);

        if ($html === null) {
            return JsonResponse::error('미리보기를 생성할 수 없습니다.');
        }

        $skinCss = $assets ? array_values(array_diff($assets->getCssPaths(), $cssBefore)) : [];
        $skinJs = $assets ? array_values(array_diff($assets->getJsPaths(), $jsBefore)) : [];

        return JsonResponse::success([
            'html' => $html,
            'skinCss' => $skinCss,
            'skinJs' => $skinJs,
        ]);
    }

    /**
     * 미리보기 생성
     *
     * POST /admin/block-row/preview
     */
    public function preview(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();

        $formData = $request->json('formData') ?? [];
        $uploadErrors = [];
        $imageMutation = new BlockImageMutationPlan();

        // 배경 설정 필드를 background_config JSON으로 통합
        $formData = $this->payloadMapper->mapBackground(
            $formData,
            $domainId,
            $request->getRawFile('bg_image'),
            $imageMutation,
            $uploadErrors
        );

        $data = FormHelper::normalizeFormData($formData, $this->getFormSchema());
        $columnsData = $this->payloadMapper->mapColumns(
            $request->json('columns') ?? [],
            $domainId,
            $request->getRawFile('column_images'),
            $request->getRawFile('column_title_image'),
            $imageMutation,
            $uploadErrors,
            $request->getRawFile('column_content_images'),
            $request->getRawFile('column_content_title_images')
        );

        if (!empty($uploadErrors)) {
            $this->payloadMapper->rollbackImages($imageMutation);
            return JsonResponse::error("이미지 업로드에 실패했습니다:\n" . implode("\n", $uploadErrors));
        }

        $writeContext = $this->writeContextFactory->create(
            $domainId,
            BlockColumnWriteContext::SOURCE_PREVIEW
        );

        // 도메인 ID 추가
        $data['domain_id'] = $domainId;

        // 렌더링 전 에셋 스냅샷 (스킨 CSS 캡처용)
        $assets = $this->container->canResolve(\Mublo\Core\Rendering\AssetManager::class)
            ? $this->container->get(\Mublo\Core\Rendering\AssetManager::class)
            : null;
        $cssBefore = $assets ? $assets->getCssPaths() : [];
        $jsBefore = $assets ? $assets->getJsPaths() : [];

        // 미리보기 서비스
        $previewService = $this->previewService;

        $rowId = (int) ($data['row_id'] ?? 0);

        try {
            if ($rowId > 0) {
                // 기존 행 미리보기
                $html = $previewService->renderExistingRowPreview(
                    $rowId,
                    $domainId,
                    $data,
                    $columnsData,
                    $writeContext
                );
            } else {
                // 새 행 미리보기
                $token = $previewService->createPreview(
                    $data,
                    $columnsData,
                    $writeContext
                );
                $html = $token === null ? null : $previewService->renderPreview($token);
            }
        } catch (\Throwable $e) {
            $this->payloadMapper->rollbackImages($imageMutation);
            throw $e;
        }

        if ($html === null) {
            $this->payloadMapper->rollbackImages($imageMutation);
            return JsonResponse::error('미리보기를 생성할 수 없습니다.');
        }

        // 렌더러는 표시할 칸이 하나도 없으면 '' 를 돌려준다. 성공으로 내보내면
        // 클라이언트가 html 없는 응답을 받아 성공 메시지를 실패 자리에 표시한다.
        if (trim($html) === '') {
            $this->payloadMapper->rollbackImages($imageMutation);
            return JsonResponse::error('미리보기에 표시할 칸이 없습니다. 칸 설정을 확인해 주세요.');
        }

        // 미리보기는 읽기 전용이다. 렌더에 사용한 신규 업로드만 제거하고
        // 기존 파일 삭제 계획은 절대 확정하지 않는다.
        $this->payloadMapper->rollbackImages($imageMutation);

        // 렌더링 중 추가된 스킨 CSS 추출
        $skinCss = $assets ? array_values(array_diff($assets->getCssPaths(), $cssBefore)) : [];
        $skinJs = $assets ? array_values(array_diff($assets->getJsPaths(), $jsBefore)) : [];

        return JsonResponse::success([
            'html' => $html,
            'skinCss' => $skinCss,
            'skinJs' => $skinJs,
        ], '미리보기가 생성되었습니다.');
    }

    /**
     * 폼 데이터 스키마
     */
    private function getFormSchema(): array
    {
        return [
            'numeric' => [
                'row_id',
                'revision_no',
                'page_id',
                'width_type',
                'column_count',
                'column_margin',
                'column_width_unit',
                'sort_order',
                'dismiss_hours',
            ],
            'bool' => ['is_active', 'dismissible'],
            'json' => ['background_config'],
        ];
    }

    /**
     * raw JS 는 편집 신뢰 전원 허용(편집 자율성 정책) — 여기서 거르는 것은
     * Include(서버사이드 PHP 실행) 타입뿐이다.
     *
     * @return array{array<int, array<string, mixed>>, array<string, array<string, mixed>>, bool}
     */
    private function contentTypeUiData(): array
    {
        $canEditInclude = $this->container->get(AuthService::class)->isSuper();
        $types = BlockRegistry::getContentTypeOptions();
        $groups = BlockRegistry::getContentTypesGroupedByKind();
        if (!$canEditInclude) {
            $types = array_values(array_filter(
                $types,
                static fn (array $type): bool => ($type['value'] ?? '') !== 'include'
            ));
            foreach ($groups as &$group) {
                unset($group['include']);
            }
            unset($group);
        }

        return [$types, $groups, $canEditInclude];
    }

    /**
     * 페이지 라벨 찾기
     */
    private function findPageLabel(array $pages, int $pageId): string
    {
        if ($pageId <= 0) {
            return '';
        }

        foreach ($pages as $page) {
            if ($page['value'] == $pageId) {
                return $page['label'] . ' (' . $page['code'] . ')';
            }
        }

        return '';
    }

    /**
     * 메뉴 옵션 목록 생성 (position_menu 셀렉트용)
     *
     * 메뉴 트리 항목과 트리 미배치 항목을 optgroup으로 분류
     *
     * @return array [['group' => string, 'items' => [['value' => string, 'label' => string, 'depth' => int], ...]], ...]
     */
    private function buildMenuOptions(int $domainId): array
    {
        $options = [];

        // 메뉴 트리 (depth 정보 포함)
        $treeItems = $this->menuService->getTree($domainId, true);
        $treeMenuCodes = [];

        if (!empty($treeItems)) {
            $treeGroup = ['group' => '메뉴 트리', 'items' => []];
            foreach ($treeItems as $node) {
                $treeGroup['items'][] = [
                    'value' => $node['menu_code'],
                    'label' => $node['label'] ?? $node['menu_code'],
                    'depth' => (int) ($node['depth'] ?? 0),
                ];
                $treeMenuCodes[] = $node['menu_code'];
            }
            $options[] = $treeGroup;
        }

        // 트리 미배치 아이템
        $allItems = $this->menuService->getItems($domainId, true);
        $unplacedItems = [];
        foreach ($allItems as $item) {
            $code = $item['menu_code'] ?? '';
            if (!in_array($code, $treeMenuCodes, true)) {
                $unplacedItems[] = [
                    'value' => $code,
                    'label' => $item['label'] ?? $code,
                    'depth' => 0,
                ];
            }
        }

        if (!empty($unplacedItems)) {
            $options[] = ['group' => '트리 미배치', 'items' => $unplacedItems];
        }

        return $options;
    }

    /**
     * buildMenuOptions() 의 그룹 구조를 menu_code => label 평면 맵으로 편다.
     *
     * 목록의 "출력 대상" 열이 원시 menu_code 대신 사람이 읽는 라벨을 보여주기 위해 쓴다.
     *
     * @param array<int, array{group: string, items: array<int, array{value: string, label: string}>}> $menuOptions
     * @return array<string, string>
     */
    private function flattenMenuLabels(array $menuOptions): array
    {
        // 특수값 "메인화면 전용"(__index__) — 목록 "출력 대상" 열에서 사람이 읽는 라벨로 표시
        $labels = [BlockRowService::POSITION_MENU_MAIN_ONLY => '메인화면 전용'];
        foreach ($menuOptions as $group) {
            foreach ($group['items'] ?? [] as $item) {
                if (isset($item['value']) && $item['value'] !== '') {
                    $labels[$item['value']] = $item['label'] ?? $item['value'];
                }
            }
        }

        return $labels;
    }

    /**
     * 메인화면에 조립되는 슬롯의 행을 조립 순서대로 모은다.
     *
     * 방문자가 메인화면을 볼 때 실제로 렌더되는 위치들이다. 관리자가 "메인화면"을
     * 편집하려는데 index 본문만 보이면 반쪽이므로, 그 화면에 함께 뜨는 사이드바·
     * 헤더/푸터 슬롯을 한 목록에 모아 순서대로 보여준다.
     *
     * @param array<string, mixed> $siteConfig
     * @return \Mublo\Entity\Block\BlockRow[]
     */
    private function collectMainScreenRows(int $domainId, array $siteConfig): array
    {
        /** @var MainScreenComposition $composition */
        $composition = $this->container->get(MainScreenComposition::class);

        $rows = [];
        foreach ($composition->slots($siteConfig) as $slot) {
            foreach ($this->rowService->getRowsByPosition($domainId, $slot) as $row) {
                // 메인화면은 메뉴 스코프가 없으므로 전역 행만 실제 조립 대상이다.
                if (($row->getPositionMenu() ?: null) !== null) {
                    continue;
                }
                $rows[] = $row; // 슬롯 순서를 유지한다 — 화면 위→아래 배치를 반영한다.
            }
        }

        return $rows;
    }
}
