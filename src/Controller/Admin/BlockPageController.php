<?php
namespace Mublo\Controller\Admin;

use Mublo\Core\Response\ViewResponse;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Response\HtmlResponse;
use Mublo\Core\Context\Context;
use Mublo\Core\Container\DependencyContainer;
use Mublo\Core\Http\Request;
use Mublo\Service\Auth\AuthService;
use Mublo\Service\Block\BlockKitApplier;
use Mublo\Service\Block\BlockKitExporter;
use Mublo\Service\Block\BlockPageService;
use Mublo\Service\Block\BlockRowService;
use Mublo\Service\Member\MemberLevelService;
use Mublo\Service\System\InstallIdProvider;
use Mublo\Enum\Block\LayoutType;
use Mublo\Helper\Form\FormHelper;

/**
 * Admin BlockPageController
 *
 * 블록 페이지 관리 컨트롤러
 *
 * 자동 라우팅:
 * - GET  /admin/block-page              → index
 * - GET  /admin/block-page/create       → create
 * - GET  /admin/block-page/edit         → edit (쿼리: ?id=123)
 * - POST /admin/block-page/store        → store
 * - POST /admin/block-page/delete       → delete
 */
class BlockPageController
{
    use BlockKitControllerTrait;

    private BlockPageService $pageService;
    private BlockRowService $rowService;
    private MemberLevelService $levelService;
    private DependencyContainer $container;

    public function __construct(
        BlockPageService $pageService,
        BlockRowService $rowService,
        MemberLevelService $levelService,
        DependencyContainer $container
    ) {
        $this->pageService = $pageService;
        $this->rowService = $rowService;
        $this->levelService = $levelService;
        $this->container = $container;
    }

    /**
     * 페이지 블록 킷 내보내기
     *
     * POST /admin/block-page/export
     *
     * 페이지 블록 킷은 레이아웃이 page 객체 안에 자기완결적으로 담기므로
     * site_config를 건드리지 않는다(설계 5.6).
     */
    public function export(array $params, Context $context): JsonResponse|HtmlResponse
    {
        // 슈퍼/도메인 운영자 게이트는 자동 라우팅 미들웨어로 걸리지 않으므로 메서드 안에서 직접 확인.
        if ($denied = $this->denyUnlessDomainOperator('블록 킷 내보내기는')) {
            return $denied;
        }

        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $pageId = (int) $request->input('page_id', 0);

        if ($pageId <= 0) {
            return JsonResponse::error('페이지 ID가 필요합니다.');
        }

        // 도메인 격리 — 블록 킷은 현재 도메인의 페이지만 내보낸다
        $page = $this->pageService->getPage($pageId, $domainId);
        if (!$page) {
            return JsonResponse::notFound('페이지를 찾을 수 없습니다.');
        }

        /** @var BlockKitExporter $exporter */
        $exporter = $this->container->get(BlockKitExporter::class);

        $kit = $exporter->exportPage(
            $page,
            $this->collectKitMeta($request),
            $this->collectKitOptions($request)
        );

        return $this->downloadKit($kit, $page->getPageCode());
    }

    /**
     * 블록 킷 JSON 다운로드 응답
     *
     * @param array<string, mixed> $kit
     */
    private function downloadKit(array $kit, string $slug): HtmlResponse
    {
        $json = json_encode($kit, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return (new HtmlResponse($json))
            ->withHeader('Content-Type', 'application/json; charset=UTF-8')
            ->withHeader('Content-Disposition', $this->kitDownloadDisposition($slug));
    }

    /**
     * @return array<string, mixed>
     */
    private function collectKitMeta(Request $request): array
    {
        return [
            'name' => trim((string) $request->input('kit_name', '')),
            'description' => trim((string) $request->input('kit_description', '')),
            'version' => trim((string) $request->input('kit_version', '')) ?: '1.0.0',
            'author' => trim((string) $request->input('kit_author', '')),
            'author_url' => trim((string) $request->input('kit_author_url', '')),
            'requires_core' => trim((string) $request->input('kit_requires_core', '')),
            'screenshot' => $this->collectKitScreenshot(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function collectKitOptions(Request $request): array
    {
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

        return $options;
    }

    /**
     * 페이지 블록 킷 미리보기 (dry-run)
     *
     * POST /admin/block-page/kit-preview
     *
     * 이 화면은 페이지 블록 킷 전용이다 — position 블록 킷은 블록 행 관리에서 다룬다.
     * dry-run 결과에 더해, 어떤 페이지 코드에 어떻게 붙는지(새로 생성 / 기존 페이지에 적용)를
     * 함께 알려 준다. 적용 버튼을 누르기 전에 무슨 일이 생길지 알아야 한다(설계 6.1).
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

        $request = $context->getRequest();
        $prepared = $this->preparePageKit(
            json_decode($json, true),
            trim((string) $request->input('page_code', ''))
        );
        if ($prepared instanceof JsonResponse) {
            return $prepared;
        }

        /** @var BlockKitApplier $applier */
        $applier = $this->container->get(BlockKitApplier::class);
        $result = $applier->dryRun($prepared, ['bytes' => strlen($json)]);

        // normalized_rows 는 내부 표현이라 화면으로 내보내지 않는다
        unset($result['normalized_rows']);

        $domainId = $context->getDomainId() ?? 1;
        $pageCode = (string) ($prepared['target']['page_code'] ?? '');

        $result['page'] = [
            'page_code' => $pageCode,
            'page_title' => (string) ($prepared['page']['page_title'] ?? ''),
            'exists' => $pageCode !== '' && $this->pageService->getPageByCode($domainId, $pageCode) !== null,
        ];

        return JsonResponse::success($result);
    }

    /**
     * 페이지 블록 킷 적용
     *
     * POST /admin/block-page/kit-apply
     *
     * 같은 코드의 페이지가 없으면 블록 킷의 page 객체로 새로 만든다(BlockKitApplier::applyPageKit).
     * page_code 를 함께 보내면 그 코드로 대상을 바꿔, 하나의 블록 킷으로 여러 페이지를 찍어낼 수 있다.
     */
    public function kitApply(array $params, Context $context): JsonResponse
    {
        if ($denied = $this->denyUnlessDomainOperator('블록 킷 적용은')) {
            return $denied;
        }

        $json = $this->readKitUpload();
        if ($json instanceof JsonResponse) {
            return $json;
        }

        $request = $context->getRequest();
        $prepared = $this->preparePageKit(
            json_decode($json, true),
            trim((string) $request->input('page_code', ''))
        );
        if ($prepared instanceof JsonResponse) {
            return $prepared;
        }

        $mode = $request->input('mode') === BlockKitApplier::MODE_REPLACE
            ? BlockKitApplier::MODE_REPLACE
            : BlockKitApplier::MODE_APPEND;

        /** @var BlockKitApplier $applier */
        $applier = $this->container->get(BlockKitApplier::class);

        // 페이지 블록 킷은 레이아웃을 page 객체 안에 담으므로 apply_site_settings 는 받지 않는다(설계 5.6)
        $authService = $this->container->get(AuthService::class);
        $result = $applier->apply(
            $context->getDomainId() ?? 1,
            $prepared,
            $mode,
            false,
            $authService->isSuper()
        );

        if (!$result['ok']) {
            return JsonResponse::error(implode("\n", $result['errors']), $result);
        }

        return JsonResponse::success($result, '블록 킷이 적용되었습니다.');
    }

    /**
     * 업로드된 블록 킷을 페이지 블록 킷으로 검증하고, 요청된 코드로 대상 페이지를 바꾼다.
     *
     * 코드 덮어쓰기는 target 과 page 양쪽에 모두 써야 한다 — applyPageKit 은 target.page_code 로
     * 페이지를 찾고, 새로 만들 때는 page 객체의 필드를 쓴다. 한쪽만 바꾸면 찾는 코드와
     * 만드는 코드가 갈라진다.
     *
     * @return array<string, mixed>|JsonResponse 성공 시 블록 킷 배열, 실패 시 오류 응답
     */
    private function preparePageKit(mixed $kit, string $codeOverride): array|JsonResponse
    {
        if (!is_array($kit)) {
            return JsonResponse::error('유효한 JSON 블록 킷이 아닙니다.');
        }

        if (($kit['target']['kind'] ?? null) !== 'page') {
            return JsonResponse::error('페이지 블록 킷이 아닙니다. 위치(position) 블록 킷은 블록 행 관리에서 적용하세요.');
        }

        if ($codeOverride !== '') {
            $validation = $this->pageService->validateCode($codeOverride);
            if (!$validation['valid']) {
                return JsonResponse::error($validation['message']);
            }

            $kit['target']['page_code'] = $codeOverride;
            if (is_array($kit['page'] ?? null)) {
                $kit['page']['page_code'] = $codeOverride;
            }
        }

        return $kit;
    }

    /**
     * 페이지 목록
     *
     * GET /admin/block-page
     */
    public function index(array $params, Context $context): ViewResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();

        // 검색 + 페이지네이션
        $keyword = trim((string) $request->query('keyword', ''));
        $page = (int) $request->query('page', 1);
        $perPage = (int) ($context->getDomainInfo()?->getSiteConfig()['per_page'] ?? 20);

        $pagination = $this->pageService->paginate($domainId, $page, $perPage, $keyword);
        $pages = $pagination['data'];

        // 페이지 데이터 가공
        $pagesData = [];
        foreach ($pages as $pageItem) {
            $pageData = $pageItem->toArray();
            $pageData['row_count'] = count($this->rowService->getRowsByPage($pageItem->getPageId()));
            $pagesData[] = $pageData;
        }

        /** @var AuthService $authService */
        $authService = $this->container->get(AuthService::class);

        return ViewResponse::view('blockpage/index')
            ->withData([
                'pageTitle' => '블록 페이지 관리',
                'pages' => $pagesData,
                'pagination' => $pagination,
                'currentKeyword' => $keyword,
                // 블록 킷은 도메인 운영자 이상(설계 9.4). 목록은 보이되 내보내기만 제한한다.
                'canExportKit' => $authService->canOperateDomain(),
            ]);
    }

    /**
     * 페이지 생성 폼
     *
     * GET /admin/block-page/create
     */
    public function create(array $params, Context $context): ViewResponse
    {
        $request = $context->getRequest();

        return ViewResponse::view('blockpage/form')
            ->withData([
                'pageTitle' => '블록 페이지 추가',
                'isEdit' => false,
                'page' => null,
                'levelOptions' => $this->levelService->getOptionsForSelect(),
                'layoutOptions' => LayoutType::options(),
                'listKeyword' => trim((string) $request->query('keyword', '')),
                'listPage' => (int) $request->query('page', 1),
            ]);
    }

    /**
     * 페이지 수정 폼
     *
     * GET /admin/block-page/edit?id=123
     */
    public function edit(array $params, Context $context): ViewResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $pageId = (int) $request->query('id', 0);

        if ($pageId === 0 && isset($params[0])) {
            $pageId = (int) $params[0];
        }

        $page = $this->pageService->getPage($pageId, $domainId);

        if (!$page) {
            return ViewResponse::view('Error/404')
                ->withData(['message' => '페이지를 찾을 수 없습니다.']);
        }

        // 연결된 행 수
        $rowCount = count($this->rowService->getRowsByPage($pageId));

        return ViewResponse::view('blockpage/form')
            ->withData([
                'pageTitle' => '블록 페이지 수정',
                'isEdit' => true,
                'page' => $page->toArray(),
                'rowCount' => $rowCount,
                'levelOptions' => $this->levelService->getOptionsForSelect(),
                'layoutOptions' => LayoutType::options(),
                'listKeyword' => trim((string) $request->query('keyword', '')),
                'listPage' => (int) $request->query('page', 1),
            ]);
    }

    /**
     * 페이지 저장 (생성/수정)
     *
     * POST /admin/block-page/store
     */
    public function store(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();

        $formData = $request->input('formData') ?? [];
        $data = FormHelper::normalizeFormData($formData, $this->getFormSchema());

        // 목록 복귀용 컨텍스트 (편집 왕복 시 검색어/페이지 유지, activeCode는 전역 처리)
        $listKeyword = trim((string) ($request->input('list_keyword') ?? ''));
        $listPage = (int) ($request->input('list_page') ?? 1);

        $pageId = (int) ($data['page_id'] ?? 0);

        if ($pageId > 0) {
            // 수정
            $result = $this->pageService->updatePage($pageId, $data, $domainId);
        } else {
            // 생성
            $result = $this->pageService->createPage($domainId, $data);
        }

        if ($result->isSuccess()) {
            $newPageId = $result->get('page_id', $pageId);
            // 저장 후 편집 화면 유지 + 목록 컨텍스트를 함께 실어 목록 복귀 시 유지
            $query = ['id' => $newPageId];
            if ($listKeyword !== '') {
                $query['keyword'] = $listKeyword;
            }
            if ($listPage > 1) {
                $query['page'] = $listPage;
            }
            return JsonResponse::success(
                ['redirect' => '/admin/block-page/edit?' . http_build_query($query)],
                $result->getMessage()
            );
        }

        return JsonResponse::error($result->getMessage());
    }

    /**
     * 페이지 삭제
     *
     * POST /admin/block-page/delete
     */
    public function delete(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $pageId = (int) $request->json('page_id', 0);

        if ($pageId <= 0) {
            return JsonResponse::error('페이지 ID가 필요합니다.');
        }

        $result = $this->pageService->deletePage($pageId, $domainId);

        if ($result->isSuccess()) {
            return JsonResponse::success(null, $result->getMessage());
        }

        return JsonResponse::error($result->getMessage());
    }

    /**
     * 목록 일괄 삭제
     *
     * POST /admin/block-page/list-delete
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
        $failed = 0;

        foreach ($chk as $pageId) {
            $result = $this->pageService->deletePage((int) $pageId, $domainId);
            if ($result->isSuccess()) {
                $deleted++;
            } else {
                $failed++;
            }
        }

        if ($deleted > 0) {
            $message = "{$deleted}개 항목이 삭제되었습니다.";
            if ($failed > 0) {
                $message .= " ({$failed}개는 연결된 행이 있어 삭제 불가)";
            }
            return JsonResponse::success(['deleted' => $deleted], $message);
        }

        return JsonResponse::error('삭제할 수 있는 항목이 없습니다.');
    }

    /**
     * 목록 일괄 수정 (상태 변경)
     *
     * POST /admin/block-page/list-modify
     *
     * 선택된 페이지의 is_active 값을 일괄 반영한다. (회원 관리 목록 패턴)
     */
    public function listModify(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();

        $checkedIds = $request->input('chk') ?? [];
        if (empty($checkedIds)) {
            return JsonResponse::error('변경할 항목을 선택해주세요.');
        }

        $statusData = $request->input('is_active') ?? [];

        $updated = 0;
        $failed = 0;

        foreach ($checkedIds as $pageId) {
            $pageId = (int) $pageId;
            if (!isset($statusData[$pageId])) {
                continue;
            }
            // is_active만 부분 업데이트 (다른 필드 미변경)
            $result = $this->pageService->updatePage(
                $pageId,
                ['is_active' => (int) $statusData[$pageId]],
                $domainId
            );
            if ($result->isSuccess()) {
                $updated++;
            } else {
                $failed++;
            }
        }

        if ($updated > 0) {
            $message = "{$updated}개 항목의 상태가 변경되었습니다.";
            if ($failed > 0) {
                $message .= " ({$failed}개 실패)";
            }
            return JsonResponse::success(['updated' => $updated], $message);
        }

        return JsonResponse::error('변경된 항목이 없습니다.');
    }

    /**
     * 코드 중복 확인
     *
     * POST /admin/block-page/check-code
     */
    public function checkCode(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();

        $code = $request->json('code', '');
        $excludeId = (int) $request->json('exclude_id', 0);

        // 코드 유효성 + 중복 검사 (Service 사용)
        $result = $this->pageService->checkCodeAvailability(
            $domainId,
            $code,
            $excludeId > 0 ? $excludeId : null
        );

        if (!$result['available']) {
            return JsonResponse::error($result['message']);
        }

        return JsonResponse::success(null, $result['message']);
    }

    /**
     * 폼 데이터 스키마
     */
    private function getFormSchema(): array
    {
        return [
            'numeric' => ['page_id', 'layout_type', 'allow_level', 'use_fullpage', 'custom_width', 'sidebar_left_width', 'sidebar_right_width'],
            'bool' => ['use_header', 'use_footer', 'is_active', 'sidebar_left_mobile', 'sidebar_right_mobile'],
            'required_string' => ['page_code', 'page_title'],
        ];
    }
}
