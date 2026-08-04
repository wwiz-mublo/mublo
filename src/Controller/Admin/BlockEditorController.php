<?php
declare(strict_types=1);
namespace Mublo\Controller\Admin;

use Mublo\Core\Block\BlockRegistry;
use Mublo\Core\Container\DependencyContainer;
use Mublo\Core\Context\Context;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Response\FileResponse;
use Mublo\Core\Response\ViewResponse;
use Mublo\Entity\Block\BlockRow;
use Mublo\Helper\View\FrontPreviewTokensHelper;
use Mublo\Service\Auth\AuthService;
use Mublo\Service\Block\BlockColumnService;
use Mublo\Service\Block\BlockPageService;
use Mublo\Service\Block\BlockRowService;
use Mublo\Service\Block\BlockSkinService;
use Mublo\Service\Block\MainScreenComposition;
use Mublo\Service\AI\HtmlBlockAiService;
use Mublo\Service\AI\AiAssetService;
use Mublo\Service\AI\DomainAiConfigService;
use Mublo\Infrastructure\Storage\UploadedFile;
use Mublo\Service\Migration\CoreMigrationService;
use Mublo\Service\Menu\MenuService;
use Mublo\Service\Frame\DomainFrameService;
use Mublo\Core\Rendering\CoreFrameTemplateSources;
use Mublo\Core\Rendering\ThemeSkinPolicy;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Core\Event\Rendering\FrameTemplateSourceCollectEvent;

/**
 * Admin BlockEditorController — 블록 에디터 (블록 에디터 설계 7)
 *
 * 미리보기 기반 페이지 관리 화면. 실제 프론트를 iframe 으로 띄우고,
 * 렌더 마커(.block-section--{rowId}, #bc-{columnId})로 클릭을 행/칸에 매핑한다.
 *
 * 자동 라우팅:
 * - GET  /admin/block-editor             → index      (에디터 화면, fullPage)
 * - GET  /admin/block-editor/contexts    → contexts   (편집 컨텍스트 트리 JSON)
 * - GET  /admin/block-editor/rows        → rows       (컨텍스트별 행 메타 JSON)
 * - GET  /admin/block-editor/row-data    → rowData    (행 편집 데이터 — store 재제출용)
 * - GET  /admin/block-editor/frame-skins → frameSkins (프레임 스킨 목록 + 현재값)
 * - POST /admin/block-editor/frame-skin  → frameSkin  (프레임 스킨 변경)
 *
 * frame-skin 을 제외하면 전부 읽기 전용이다. 블록 쓰기는 기존 블록 행/페이지
 * 컨트롤러를 재사용하고(설계 1.1 ③), 프레임 스킨 저장도 DomainSettingsService
 * 의 기존 저장 경로를 그대로 쓴다(설계 6.6).
 *
 * ## 권한
 * 에디터는 화면 전체를 바꿀 수 있는 도구이므로 블록 킷과 같은 급 — 도메인 운영자
 * 이상(설계 9). 조회 API 도 같은 게이트를 건다.
 */
class BlockEditorController
{
    use BlockKitControllerTrait;

    private const DEFAULT_RETURN_URL = '/admin/block-page?activeCode=004_002';

    public function __construct(
        private BlockRowService $rowService,
        private BlockColumnService $columnService,
        private BlockPageService $pageService,
        private BlockSkinService $skinService,
        private MenuService $menuService,
        private MainScreenComposition $composition,
        private HtmlBlockAiService $htmlBlockAiService,
        private AiAssetService $aiAssetService,
        private DomainAiConfigService $aiConfigService,
        private CoreMigrationService $migrationService,
        private DependencyContainer $container,
        private ?\Mublo\Repository\Block\BlockColumnContentRepository $contentRepository = null
    ) {
    }

    /**
     * 스택 칸의 하위 콘텐츠 (관리자 조회 — 비활성 포함, 계획 6.2.2).
     *
     * @return \Mublo\Entity\Block\BlockColumnContent[]
     */
    private function stackContentsForColumn(\Mublo\Entity\Block\BlockColumn $column): array
    {
        if ($this->contentRepository === null) {
            return [];
        }

        try {
            return $this->contentRepository->findByColumnForDomain(
                $column->getColumnId(),
                $column->getDomainId(),
                true
            );
        } catch (\Throwable) {
            return []; // 콘텐츠 테이블 미설치 환경
        }
    }

    /**
     * 에디터 화면
     *
     * GET /admin/block-editor
     *
     * 관리자 셸(사이드바·헤더) 없이 전체 화면을 쓴다 — 미리보기가 주인공인
     * 화면이라 fullPage 로 렌더한다(로그인 페이지와 같은 경로).
     */
    public function index(array $params, Context $context): ViewResponse
    {
        /** @var AuthService $authService */
        $authService = $this->container->get(AuthService::class);

        // JSON 조회 API와 동일한 도메인 운영자 게이트를 첫 화면에도 적용한다.
        // 미들웨어의 일반 관리자 권한만으로 에디터 UI가 노출되지 않게 한다.
        if (!$authService->canOperateDomain()) {
            return ViewResponse::view('Error/403')
                ->fullPage()
                ->withStatusCode(403)
                ->withData(['message' => '블록 에디터는 도메인 운영자 이상만 사용할 수 있습니다.']);
        }

        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $returnCandidate = trim((string) $request->query('return_url', ''));
        if ($returnCandidate === '') {
            $returnCandidate = trim((string) $request->header('Referer', ''));
        }
        $returnUrl = self::safeAdminReturnUrl(
            $returnCandidate,
            (string) $request->header('Host', '')
        );
        // raw JS 는 편집 신뢰 전원 허용(편집 자율성 정책) — 게이트는 Include(서버 실행)만.
        $canEditInclude = $authService->isSuper();
        $contentTypes = BlockRegistry::getContentTypeOptions();
        if (!$canEditInclude) {
            $contentTypes = array_values(array_filter(
                $contentTypes,
                static fn (array $type): bool => ($type['value'] ?? '') !== 'include'
            ));
        }

        return ViewResponse::view('blockeditor/index')
            ->fullPage()
            ->withData([
                'pageTitle' => '블록 에디터',
                // 첫 렌더 왕복을 줄이기 위해 트리 초기 데이터를 함께 싣는다(설계 7.1)
                'initialContexts' => $this->buildContexts($domainId, $context),
                'initialContext' => trim((string) $request->query('context', '')),
                'returnUrl' => $returnUrl,
                // 칸 인스펙터용: 비주얼 타입 피커 카드와 스킨 셀렉트 (설계 6.4 ③, 6.6)
                'contentTypes' => $contentTypes,
                'skinLists' => $this->skinService->getAllSkinLists(),
                'canEditInclude' => $canEditInclude,
                // 캔버스가 프론트 토큰 캐스케이드를 재현하는 데 필요한 값 (뷰가 전역으로 노출)
                'frontPreviewTokens' => FrontPreviewTokensHelper::forContext($context),
            ]);
    }

    /**
     * 전체 화면 에디터에서 돌아갈 관리자 내부 경로만 허용한다.
     * 외부 Referer, 프로토콜 상대 URL, 에디터 자기 자신은 안전한 기본값으로 보낸다.
     */
    private static function safeAdminReturnUrl(string $candidate, string $currentHost): string
    {
        if ($candidate === '' || str_starts_with($candidate, '//')) {
            return self::DEFAULT_RETURN_URL;
        }

        $parts = parse_url($candidate);
        if ($parts === false) {
            return self::DEFAULT_RETURN_URL;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $expectedHost = strtolower(explode(':', $currentHost, 2)[0]);
        if ($host !== '' && (!in_array($scheme, ['http', 'https'], true) || $host !== $expectedHost)) {
            return self::DEFAULT_RETURN_URL;
        }

        $path = (string) ($parts['path'] ?? '');
        if (($path !== '/admin' && !str_starts_with($path, '/admin/'))
            || $path === '/admin/block-editor'
            || str_starts_with($path, '/admin/block-editor/')) {
            return self::DEFAULT_RETURN_URL;
        }

        $url = $path;
        if (isset($parts['query']) && $parts['query'] !== '') {
            $url .= '?' . $parts['query'];
        }
        if (isset($parts['fragment']) && $parts['fragment'] !== '') {
            $url .= '#' . $parts['fragment'];
        }

        return $url;
    }

    /**
     * 편집 컨텍스트 트리
     *
     * GET /admin/block-editor/contexts
     */
    public function contexts(array $params, Context $context): JsonResponse
    {
        if ($denied = $this->denyUnlessDomainOperator('블록 에디터는')) {
            return $denied;
        }

        $domainId = $context->getDomainId() ?? 1;

        return JsonResponse::success($this->buildContexts($domainId, $context));
    }

    /**
     * 컨텍스트별 행 메타 (오버레이 라벨·인스펙터용)
     *
     * GET /admin/block-editor/rows?context={id}
     */
    public function rows(array $params, Context $context): JsonResponse
    {
        if ($denied = $this->denyUnlessDomainOperator('블록 에디터는')) {
            return $denied;
        }

        $domainId = $context->getDomainId() ?? 1;
        $contextId = trim((string) $context->getRequest()->query('context', ''));

        $rows = $this->resolveContextRows($domainId, $contextId, $context);
        if ($rows === null) {
            return JsonResponse::error('알 수 없는 컨텍스트입니다.', null, 404);
        }

        $menuLabels = $this->menuLabelMap($domainId);
        $positions = $this->rowService->getValidPositions();

        return JsonResponse::success([
            'context' => $contextId,
            'rows' => array_map(
                fn (BlockRow $row) => $this->toRowMeta($row, $positions, $menuLabels),
                $rows
            ),
        ]);
    }

    /**
     * 행 편집 데이터 — 인스펙터 네이티브 편집용 (설계 6)
     *
     * GET /admin/block-editor/row-data?id={rowId}
     *
     * 기존 행 저장 API(/admin/block-row/store)에 그대로 재제출할 수 있는 형태로
     * 돌려준다. 인스펙터는 바꾼 필드만 수정하고 나머지(특히 columns)는 그대로
     * 통과시킨다 — store 의 replaceByRow 는 전달된 columns 로 칸을 갈아끼우므로,
     * columns 를 빠뜨리면 칸이 통째로 사라진다. 이 API 가 그 실수를 막는 근거다.
     */
    public function rowData(array $params, Context $context): JsonResponse
    {
        if ($denied = $this->denyUnlessDomainOperator('블록 에디터는')) {
            return $denied;
        }

        $domainId = $context->getDomainId() ?? 1;
        $rowId = (int) $context->getRequest()->query('id', 0);

        $row = $this->rowService->getRow($rowId);
        if (!$row || $row->getDomainId() !== $domainId) {
            return JsonResponse::error('행을 찾을 수 없습니다.', null, 404);
        }

        $bg = $row->getBackgroundConfig() ?? [];

        // formData[*] 키와 1:1 — 블록 행 폼의 입력 이름을 그대로 따른다.
        $form = [
            'row_id' => $row->getRowId(),
            'revision_no' => $row->getRevisionNo(),
            'admin_title' => $row->getAdminTitle() ?? '',
            'section_id' => $row->getSectionId() ?? '',
            'is_active' => (int) $row->isActive(),
            'page_id' => $row->getPageId() ?? 0,
            'position' => $row->getPosition()?->value ?? '',
            'position_menu' => $row->getPositionMenu() ?? '',
            'width_type' => $row->getWidthType(),
            'dismissible' => (int) $row->isDismissible(),
            'dismiss_hours' => $row->getDismissHours(),
            'column_count' => $row->getColumnCount(),
            'column_margin' => $row->getColumnMargin(),
            'pc_margin' => $row->getPcMargin() ?? '',
            'mobile_margin' => $row->getMobileMargin() ?? '',
            'pc_padding' => $row->getPcPadding() ?? '',
            'mobile_padding' => $row->getMobilePadding() ?? '',
            'bg_color' => $bg['color'] ?? '',
            'bg_image_old' => $bg['image'] ?? '',
            'bg_size' => $bg['size'] ?? 'cover',
            'bg_position' => $bg['position'] ?? 'center center',
            'bg_repeat' => $bg['repeat'] ?? 'no-repeat',
            'bg_attachment' => $bg['attachment'] ?? 'scroll',
        ];

        // columns[i][*] — 폼 hidden input 과 같은 형태(JSON 필드는 문자열).
        // 설정류(config)는 연관 배열이므로 (object) 캐스팅으로 인코딩한다 — 빈 값이
        // '[]' 로 나가면 JS 에서 배열이 되어 .html 등 속성 대입이 stringify 에서
        // 조용히 유실된다(HTML 저장 미반영 버그의 원인). content_items 는 목록이라
        // 배열 인코딩을 유지한다.
        $columns = [];
        foreach ($this->columnService->getColumnsByRowForDomain($rowId, $domainId) ?? [] as $column) {
            $columnData = [
                'width' => $column->getWidth() ?? '',
                'pc_padding' => $column->getPcPadding() ?? '',
                'mobile_padding' => $column->getMobilePadding() ?? '',
                'content_type' => $column->getContentTypeString() ?? '',
                'content_kind' => $column->getContentKind()->value,
                'content_skin' => $column->getContentSkin() ?? '',
                'background_config' => json_encode((object) ($column->getBackgroundConfig() ?? []), JSON_UNESCAPED_UNICODE),
                'border_config' => json_encode((object) ($column->getBorderConfig() ?? []), JSON_UNESCAPED_UNICODE),
                'title_config' => json_encode((object) ($column->getTitleConfig() ?? []), JSON_UNESCAPED_UNICODE),
                'content_config' => json_encode((object) ($column->getContentConfig() ?? []), JSON_UNESCAPED_UNICODE),
                'content_items' => json_encode($column->getContentItems() ?? [], JSON_UNESCAPED_UNICODE),
                'is_active' => (int) $column->isActive(),
                // stable 동기화(6.2.0)용 — 재제출 시 칸 식별자 유지
                'column_id' => $column->getColumnId(),
            ];

            // 스택 칸 — 그대로 재제출 가능해야 인스펙터의 부분 수정 저장이
            // 구형 payload 방어(column_id/contents 부재 거부)에 걸리지 않는다
            if ($column->isStack()) {
                $columnData['content_mode'] = 'stack';
                $columnData['pc_content_gap'] = $column->getPcContentGap();
                $columnData['mobile_content_gap'] = $column->getMobileContentGap();
                $columnData['contents'] = array_map(
                    static fn($content) => [
                        'content_id' => $content->getContentId(),
                        'content_type' => $content->getContentTypeString() ?? '',
                        'content_kind' => $content->getContentKind()->value,
                        'content_skin' => $content->getContentSkin() ?? '',
                        'title_config' => json_encode((object) ($content->getTitleConfig() ?? []), JSON_UNESCAPED_UNICODE),
                        'content_config' => json_encode((object) ($content->getContentConfig() ?? []), JSON_UNESCAPED_UNICODE),
                        'content_items' => json_encode($content->getContentItems() ?? [], JSON_UNESCAPED_UNICODE),
                        'is_active' => (int) $content->isActive(),
                    ],
                    $this->stackContentsForColumn($column)
                );
            }

            $columns[] = $columnData;
        }

        return JsonResponse::success(['form' => $form, 'columns' => $columns]);
    }

    /** POST /admin/block-editor/ai-html — HTML 타입 칸 전용 AI 생성 */
    public function aiHtml(array $params, Context $context): JsonResponse
    {
        if ($denied = $this->denyUnlessDomainOperator('HTML 블록 AI는')) return $denied;

        $request = $context->getRequest();
        $domainId = $context->getDomainId() ?? 1;
        $rowId = (int) $request->input('row_id', 0);
        $columnIndex = (int) $request->input('column_index', -1);
        $row = $this->rowService->getRow($rowId);
        $columns = $row
            ? ($this->columnService->getColumnsByRowForDomain($rowId, $domainId) ?? [])
            : [];
        $column = $columns[$columnIndex] ?? null;

        if (!$row || $row->getDomainId() !== $domainId || !$column
            || $column->getDomainId() !== $domainId || $column->getContentTypeString() !== 'html') {
            return JsonResponse::notFound('현재 도메인의 HTML 편집 블록을 찾을 수 없습니다.');
        }

        try {
            $this->ensureCoreMigrations();
            $assetIds = json_decode((string) $request->input('asset_ids', '[]'), true);
            /** @var AuthService $authService */
            $authService = $this->container->get(AuthService::class);
            $siteConfig = $context->getDomainInfo()?->getSiteConfig() ?? [];
            return JsonResponse::success($this->htmlBlockAiService->generate($domainId, $rowId, $columnIndex, [
                'prompt' => $request->input('prompt', ''),
                'mode' => $request->input('mode', 'create'),
                'current_html' => $request->input('current_html', ''),
                'current_css' => $request->input('current_css', ''),
                'current_js' => $request->input('current_js', ''),
                'asset_ids' => is_array($assetIds) ? $assetIds : [],
                'member_id' => $authService->id(),
                // 사이트 컨텍스트 — 컬러 자율성 정책(개선 계획 §4)에 따라
                // primary_color·디자인 토큰은 전달하지 않는다
                'site' => [
                    'site_name' => (string) ($siteConfig['site_title'] ?? ''),
                ],
            ]));
        } catch (\InvalidArgumentException $e) {
            return JsonResponse::validationError([], $e->getMessage());
        } catch (\RuntimeException $e) {
            return JsonResponse::error($e->getMessage());
        } catch (\Throwable) {
            return JsonResponse::serverError('HTML 블록을 생성하지 못했습니다.');
        }
    }

    /** GET /admin/block-editor/ai-assets */
    public function aiAssets(array $params, Context $context): JsonResponse
    {
        if ($denied = $this->denyUnlessDomainOperator('AI 자료실은')) return $denied;
        try {
            $this->ensureCoreMigrations();
            $domainId = $context->getDomainId() ?? 1;
            $records = $this->htmlBlockAiService->recentRecords($domainId);
            foreach ($records as &$record) {
                $ids = json_decode((string) ($record['asset_ids_json'] ?? '[]'), true);
                $record['asset_ids'] = is_array($ids) ? array_values(array_map('intval', $ids)) : [];
                unset($record['asset_ids_json']);
            }
            $config = $this->aiConfigService->publicConfig($domainId);
            return JsonResponse::success([
                'assets' => $this->aiAssetService->list($domainId),
                'records' => $records,
                'ai_ready' => !empty($config['is_enabled']) && !empty($config['api_key_configured']),
                'api_key_configured' => !empty($config['api_key_configured']),
            ]);
        } catch (\Throwable) {
            return JsonResponse::serverError('AI 자료실을 준비하지 못했습니다. 시스템 마이그레이션을 확인해주세요.');
        }
    }

    /** POST /admin/block-editor/ai-assets-upload */
    public function aiAssetsUpload(array $params, Context $context): JsonResponse
    {
        if ($denied = $this->denyUnlessDomainOperator('AI 자료실은')) return $denied;
        $files = UploadedFile::fromGlobalMultiple('files');
        if (!$files) return JsonResponse::validationError([], '업로드할 파일을 선택해주세요.');
        /** @var AuthService $authService */
        $authService = $this->container->get(AuthService::class);
        try {
            $this->ensureCoreMigrations();
            $result = [];
            foreach ($files as $file) $result[] = $this->aiAssetService->upload($file, $context->getDomainId() ?? 1, $authService->id());
            return JsonResponse::success($result, count($result) . '개 자료가 저장되었습니다.');
        } catch (\InvalidArgumentException $e) {
            return JsonResponse::validationError([], $e->getMessage());
        } catch (\Throwable) {
            return JsonResponse::serverError('AI 자료를 저장하지 못했습니다.');
        }
    }

    /** POST /admin/block-editor/ai-asset-delete */
    public function aiAssetDelete(array $params, Context $context): JsonResponse
    {
        if ($denied = $this->denyUnlessDomainOperator('AI 자료실은')) return $denied;
        try {
            $this->ensureCoreMigrations();
            $id = (int) $context->getRequest()->input('asset_id', 0);
            if (!$this->aiAssetService->delete($context->getDomainId() ?? 1, $id)) return JsonResponse::notFound('자료를 찾을 수 없습니다.');
            return JsonResponse::success(null, 'AI 자료가 삭제되었습니다.');
        } catch (\Throwable) {
            return JsonResponse::serverError('AI 자료를 삭제하지 못했습니다.');
        }
    }

    /** POST /admin/block-editor/ai-asset-edit */
    public function aiAssetEdit(array $params, Context $context): JsonResponse
    {
        if ($denied = $this->denyUnlessDomainOperator('AI 자료실은')) return $denied;
        $request = $context->getRequest();
        /** @var AuthService $authService */
        $authService = $this->container->get(AuthService::class);
        try {
            $this->ensureCoreMigrations();
            return JsonResponse::success($this->aiAssetService->editImage(
                $context->getDomainId() ?? 1,
                (int) $request->input('asset_id', 0),
                (string) $request->input('operation', ''),
                [
                    'x' => $request->input('x', 0), 'y' => $request->input('y', 0),
                    'width' => $request->input('width', 0), 'height' => $request->input('height', 0),
                ],
                $authService->id()
            ), '이미지 편집본이 저장되었습니다.');
        } catch (\InvalidArgumentException $e) {
            return JsonResponse::validationError([], $e->getMessage());
        } catch (\Throwable) {
            return JsonResponse::serverError('이미지를 편집하지 못했습니다.');
        }
    }

    /** GET /admin/block-editor/ai-asset-file?id=... */
    public function aiAssetFile(array $params, Context $context): FileResponse|JsonResponse
    {
        if ($denied = $this->denyUnlessDomainOperator('AI 자료실은')) return $denied;
        try {
            $this->ensureCoreMigrations();
            $found = $this->aiAssetService->findFile($context->getDomainId() ?? 1, (int) $context->getRequest()->query('id', 0));
        } catch (\Throwable) {
            return JsonResponse::serverError('AI 자료를 불러오지 못했습니다.');
        }
        if (!$found) return JsonResponse::notFound('자료를 찾을 수 없습니다.');
        $asset = $found['asset'];
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $asset['original_name']) ?: 'asset';
        return new FileResponse($found['path'], 200, [
            'Content-Type' => $asset['mime_type'], 'Content-Length' => (string) filesize($found['path']),
            'Content-Disposition' => 'inline; filename="' . $name . '"',
            'Cache-Control' => 'private, max-age=300', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function ensureCoreMigrations(): void
    {
        if ($this->migrationService->hasPending()) $this->migrationService->runPending();
    }

    /**
     * 프레임 스킨 목록 + 현재값 (설계 6.6)
     *
     * GET /admin/block-editor/frame-skins
     *
     * 목록은 views/Front/frame/* 폴더 스캔으로 발견한다 — 스킨을 추가하면
     * 자동으로 나타난다. 프레임 스킨은 Head/Header/Layout/Footer/Foot 일체가
     * 한 단위다.
     */
    public function frameSkins(array $params, Context $context): JsonResponse
    {
        if ($denied = $this->denyUnlessDomainOperator('블록 에디터는')) {
            return $denied;
        }

        $current = ThemeSkinPolicy::resolveExisting(
            'frame',
            $context->getDomainInfo()?->getThemeConfig()['frame'] ?? null
        );

        return JsonResponse::success([
            'current' => $current,
            'skins' => $this->scanFrameSkins($context->isSuperDomain()),
        ]);
    }

    /**
     * 프레임 스킨 변경 (설계 6.6)
     *
     * POST /admin/block-editor/frame-skin  { skin: string }
     *
     * 저장은 DomainSettingsService::saveSettings() 를 그대로 쓴다 — 화이트리스트
     * 검증과 캐시 무효화가 붙어 있는 기존 경로다. sanitizeThemeConfig 가 빠진
     * 키를 basic 으로 재구성하므로 반드시 현재 theme_config 전체에 병합해 보낸다
     * (블록 킷의 site_config 병합과 같은 원칙, 설계 5.4 ①②).
     */
    public function frameSkin(array $params, Context $context): JsonResponse
    {
        if ($denied = $this->denyUnlessDomainOperator('프레임 스킨 변경은')) {
            return $denied;
        }

        $skin = trim((string) $context->getRequest()->input('skin', ''));
        $available = array_column($this->scanFrameSkins(), 'value');

        if (!ThemeSkinPolicy::isValidName($skin) || !in_array($skin, $available, true)) {
            return JsonResponse::error('존재하지 않는 프레임 스킨입니다.');
        }

        $domainId = $context->getDomainId() ?? 1;
        $current = $context->getDomainInfo()?->getThemeConfig() ?? [];
        $merged = array_replace($current, ['frame' => $skin]);

        /** @var \Mublo\Service\Domain\DomainSettingsService $settings */
        $settings = $this->container->get(\Mublo\Service\Domain\DomainSettingsService::class);
        $result = $settings->saveSettings($domainId, ['theme' => $merged]);

        if ($result->isFailure()) {
            return JsonResponse::error('프레임 스킨 저장에 실패했습니다: ' . $result->getMessage());
        }

        return JsonResponse::success(['skin' => $skin], '프레임 스킨이 변경되었습니다.');
    }

    /* =====================================================================
     * 도메인 프레임 편집 (header/footer HTML 오버라이드)
     *
     * 설계: storage/docs/Mublo_Domain_Frame_Editing_Implementation_Plan.md
     * - GET  frame-status  : 편집 현황 + 시드 + 변수 팔레트
     * - POST frame-draft   : 초안 저장 (프론트 무영향)
     * - POST frame-publish : 게시 (draft → published, 렌더 플래그 on)
     * - POST frame-revert  : 스킨으로 되돌리기 (오버라이드 삭제, §3.7 탈출구)
     * ===================================================================== */

    /**
     * 프레임 편집 현황 + 시드 + 변수 팔레트
     *
     * GET /admin/block-editor/frame-status?part=header
     */
    public function frameStatus(array $params, Context $context): JsonResponse
    {
        if ($denied = $this->denyUnlessDomainOperator('프레임 편집은')) {
            return $denied;
        }

        $part = strtolower(trim((string) $context->getRequest()->query('part', '')));
        if (!in_array($part, DomainFrameService::PARTS, true)) {
            return JsonResponse::error('지원하지 않는 프레임 파트입니다.');
        }

        $this->ensureCoreMigrations();

        $domainId = $context->getDomainId() ?? 1;
        $frameSkin = ThemeSkinPolicy::resolveExisting(
            'frame',
            $context->getDomainInfo()?->getThemeConfig()['frame'] ?? null
        );

        $row = $this->frameService()->get($domainId, $part);
        $seed = DomainFrameService::loadSeed($frameSkin, $part);

        $palette = $this->framePalette($domainId);

        // AI 준비 상태 — 블록 편집과 동일한 UX (개선 계획 §10.2)
        $aiConfig = $this->aiConfigService->publicConfig($domainId);
        $aiReady = !empty($aiConfig['is_enabled']) && !empty($aiConfig['api_key_configured']);

        return JsonResponse::success([
            'part' => $part,
            'status' => $row['status'] ?? 'none',
            'published' => ($row['status'] ?? '') === 'published',
            'has_draft' => trim((string) ($row['draft_html'] ?? '')) !== '',
            'draft' => [
                'html' => (string) ($row['draft_html'] ?? ''),
                'css' => (string) ($row['draft_css'] ?? ''),
                'js' => (string) ($row['draft_js'] ?? ''),
            ],
            'seeded_from_skin' => $row['seeded_from_skin'] ?? null,
            'seed' => $seed,
            'frame_skin' => $frameSkin,
            'palette' => $palette,
            'ai_ready' => $aiReady,
            'api_key_configured' => !empty($aiConfig['api_key_configured']),
        ]);
    }

    /**
     * 프레임 초안 저장 — 게시 전까지 프론트에 영향 없음
     *
     * POST /admin/block-editor/frame-draft  { part, html, css, js, seeded_from_skin? }
     */
    public function frameDraft(array $params, Context $context): JsonResponse
    {
        if ($denied = $this->denyUnlessDomainOperator('프레임 편집은')) {
            return $denied;
        }

        $request = $context->getRequest();
        $part = strtolower(trim((string) $request->input('part', '')));
        if (!in_array($part, DomainFrameService::PARTS, true)) {
            return JsonResponse::error('지원하지 않는 프레임 파트입니다.');
        }

        $html = (string) $request->input('html', '');
        if (trim($html) === '') {
            return JsonResponse::error('HTML이 비어 있습니다. 빈 프레임을 원하면 "스킨으로 되돌리기"를 사용하세요.');
        }

        $this->ensureCoreMigrations();

        /** @var AuthService $auth */
        $auth = $this->container->get(AuthService::class);

        $result = $this->frameService()->saveDraft(
            $context->getDomainId() ?? 1,
            $part,
            $html,
            (string) $request->input('css', ''),
            (string) $request->input('js', ''),
            trim((string) $request->input('seeded_from_skin', '')) ?: null,
            isset($auth->user()['member_id']) ? (int) $auth->user()['member_id'] : null
        );

        return $result->isSuccess()
            ? JsonResponse::success([], $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    /**
     * 프레임 게시 — draft를 게시본으로 승격 (전 페이지 반영)
     *
     * POST /admin/block-editor/frame-publish  { part }
     */
    public function framePublish(array $params, Context $context): JsonResponse
    {
        if ($denied = $this->denyUnlessDomainOperator('프레임 게시는')) {
            return $denied;
        }

        $part = strtolower(trim((string) $context->getRequest()->input('part', '')));
        if (!in_array($part, DomainFrameService::PARTS, true)) {
            return JsonResponse::error('지원하지 않는 프레임 파트입니다.');
        }

        $this->ensureCoreMigrations();

        /** @var AuthService $auth */
        $auth = $this->container->get(AuthService::class);

        $result = $this->frameService()->publish(
            $context->getDomainId() ?? 1,
            $part,
            isset($auth->user()['member_id']) ? (int) $auth->user()['member_id'] : null
        );

        return $result->isSuccess()
            ? JsonResponse::success([], $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    /**
     * 스킨으로 되돌리기 — 오버라이드 삭제, 파일 스킨 복귀 (§3.7 탈출구)
     *
     * POST /admin/block-editor/frame-revert  { part }
     */
    public function frameRevert(array $params, Context $context): JsonResponse
    {
        if ($denied = $this->denyUnlessDomainOperator('프레임 되돌리기는')) {
            return $denied;
        }

        $part = strtolower(trim((string) $context->getRequest()->input('part', '')));
        if (!in_array($part, DomainFrameService::PARTS, true)) {
            return JsonResponse::error('지원하지 않는 프레임 파트입니다.');
        }

        $this->ensureCoreMigrations();

        $result = $this->frameService()->revertToSkin($context->getDomainId() ?? 1, $part);

        return $result->isSuccess()
            ? JsonResponse::success([], $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    /**
     * 프레임 AI 생성 — 결과는 에디터에 반영만, 저장·게시는 운영자가 명시적으로
     *
     * POST /admin/block-editor/frame-ai  { part, prompt, mode?, current_html?, current_css? }
     */
    public function frameAi(array $params, Context $context): JsonResponse
    {
        if ($denied = $this->denyUnlessDomainOperator('프레임 AI 생성은')) {
            return $denied;
        }

        $request = $context->getRequest();
        $part = strtolower(trim((string) $request->input('part', '')));
        if (!in_array($part, DomainFrameService::PARTS, true)) {
            return JsonResponse::error('지원하지 않는 프레임 파트입니다.');
        }

        $this->ensureCoreMigrations();

        $domainId = $context->getDomainId() ?? 1;

        /** @var AuthService $auth */
        $auth = $this->container->get(AuthService::class);
        /** @var \Mublo\Service\AI\FrameAiService $frameAi */
        $frameAi = $this->container->get(\Mublo\Service\AI\FrameAiService::class);

        // 사이트 컨텍스트 — 스킨 정합용(클래스 사전·시드). 컬러 자율성 정책(개선
        // 계획 §4)에 따라 primary_color·디자인 토큰은 전달하지 않는다.
        $siteConfig = $context->getDomainInfo()?->getSiteConfig() ?? [];
        $frameSkin = ThemeSkinPolicy::resolveExisting(
            'frame',
            $context->getDomainInfo()?->getThemeConfig()['frame'] ?? null
        );
        $siteContext = [
            'site_name' => (string) ($siteConfig['site_title'] ?? ''),
            'skin' => $frameSkin,
            'seed_html' => DomainFrameService::loadSeed($frameSkin, $part)['html'],
        ];

        try {
            $result = $frameAi->generate($domainId, $part, [
                'prompt' => (string) $request->input('prompt', ''),
                'mode' => (string) $request->input('mode', 'create'),
                'current_html' => (string) $request->input('current_html', ''),
                'current_css' => (string) $request->input('current_css', ''),
                'member_id' => $auth->user()['member_id'] ?? null,
            ], $this->framePalette($domainId), $siteContext);
        } catch (\InvalidArgumentException $e) {
            return JsonResponse::error($e->getMessage());
        } catch (\RuntimeException $e) {
            return JsonResponse::error($e->getMessage());
        } catch (\Throwable $e) {
            error_log('[BlockEditorController] frameAi failed: ' . $e->getMessage());
            return JsonResponse::error('AI 생성에 실패했습니다. 잠시 후 다시 시도해주세요.');
        }

        return JsonResponse::success($result, 'AI 생성 결과를 에디터에 반영했습니다. 검토 후 저장하세요.');
    }

    /**
     * 프레임 AI 생성 이력 (P4 — 파트별 최근 성공 이력)
     *
     * GET /admin/block-editor/frame-ai-history?part=header
     */
    public function frameAiHistory(array $params, Context $context): JsonResponse
    {
        if ($denied = $this->denyUnlessDomainOperator('프레임 AI 이력은')) {
            return $denied;
        }

        $part = strtolower(trim((string) $context->getRequest()->query('part', '')));
        if (!in_array($part, DomainFrameService::PARTS, true)) {
            return JsonResponse::error('지원하지 않는 프레임 파트입니다.');
        }

        $records = $this->htmlBlockAiService->recentRecordsByModes(
            $context->getDomainId() ?? 1,
            ['frame_' . $part],
            10
        );

        return JsonResponse::success($records);
    }

    /**
     * AI 생성 이력 단건 (결과물 포함) — "이 결과에서 이어서" 복원 (P4, 블록·프레임 공통)
     *
     * GET /admin/block-editor/ai-record?id=123
     */
    public function aiRecord(array $params, Context $context): JsonResponse
    {
        if ($denied = $this->denyUnlessDomainOperator('AI 이력은')) {
            return $denied;
        }

        $recordId = (int) $context->getRequest()->query('id', 0);
        if ($recordId <= 0) {
            return JsonResponse::error('이력 ID가 올바르지 않습니다.');
        }

        $record = $this->htmlBlockAiService->findRecord($context->getDomainId() ?? 1, $recordId);
        if ($record === null) {
            return JsonResponse::notFound('이력을 찾을 수 없습니다.');
        }

        return JsonResponse::success($record);
    }

    /**
     * 변수 팔레트: 코어(§3.1) + 현재 도메인 활성 확장(수집 이벤트, §3.9)
     *
     * @return array<array{name: string, kind: string, label: string, source: string}>
     */
    private function framePalette(int $domainId): array
    {
        $palette = CoreFrameTemplateSources::palette();

        /** @var FrameTemplateSourceCollectEvent $event */
        $event = $this->container->get(EventDispatcher::class)
            ->dispatch(new FrameTemplateSourceCollectEvent($domainId));
        foreach ($event->getVariables() as $name => $def) {
            $palette[] = ['name' => $name, 'kind' => 'variable', 'label' => $def['label'], 'source' => explode('.', $name)[0]];
        }
        foreach ($event->getSlots() as $name => $def) {
            $palette[] = ['name' => $name, 'kind' => 'slot', 'label' => $def['label'], 'source' => explode('.', $name)[0]];
        }

        return $palette;
    }

    private function frameService(): DomainFrameService
    {
        return $this->container->get(DomainFrameService::class);
    }

    /**
     * views/Front/frame/* 스킨 폴더 스캔.
     *
     * @return array<int, array{value: string, label: string}>
     */
    private function scanFrameSkins(bool $includeSuperOnly = false): array
    {
        $base = MUBLO_VIEW_PATH . '/Front/frame';
        $skins = [];

        foreach (is_dir($base) ? scandir($base) : [] as $dir) {
            if ($dir === '.' || $dir === '..' || str_starts_with($dir, '_')
                || !ThemeSkinPolicy::isValidName($dir)) {
                continue;
            }
            // super_ 스킨은 SUPER 전용이다 (ThemeSkinPolicy 관례)
            if (!$includeSuperOnly && ThemeSkinPolicy::isSuperOnly($dir)) {
                continue;
            }
            // 프레임 스킨은 최소한 Header 파트가 있어야 한다
            if (is_file("{$base}/{$dir}/Header.php")) {
                $skins[] = ['value' => $dir, 'label' => $dir];
            }
        }

        return $skins;
    }

    /**
     * 컨텍스트 트리를 구성한다 (설계 3.1).
     *
     * @return array<string, mixed>
     */
    private function buildContexts(int $domainId, Context $context): array
    {
        $siteConfig = $context->getDomainInfo()?->getSiteConfig() ?? [];
        $menus = $this->menuItemMap($domainId);
        $positions = $this->rowService->getValidPositions();

        /* ---- 메인화면 ---- */
        $mainRowCount = 0;
        foreach ($this->composition->slots($siteConfig) as $slot) {
            $mainRowCount += count($this->rowService->getRowsByPosition($domainId, $slot));
        }

        $screen = [[
            'id' => 'screen:main',
            'label' => '메인화면',
            'preview_url' => '/',
            'row_count' => $mainRowCount,
        ]];

        /* ---- 블록 페이지 ---- */
        $pages = [];
        foreach ($this->pageService->getPages($domainId) as $page) {
            $pages[] = [
                'id' => 'page:' . $page->getPageId(),
                'label' => $page->getPageTitle(),
                'code' => $page->getPageCode(),
                'preview_url' => '/p/' . rawurlencode($page->getPageCode()),
                'row_count' => count($this->rowService->getRowsByPage($page->getPageId())),
                'is_active' => $page->isActive(),
                'allow_level' => $page->getAllowLevel(),
                'settings_url' => '/admin/block-page/edit?id=' . $page->getPageId(),
            ];
        }

        /* ---- 페이지 (메뉴 기준) ----
         *
         * 편집 단위는 위치가 아니라 페이지다 — 운영자는 "커뮤니티 페이지를 고친다"고
         * 생각하지 "오른쪽 사이드바를 고친다"고 생각하지 않는다. 위치는 행 추가
         * 시점에만 "어디에 표시할까요"로 등장한다(설계 6.8 용어 원칙).
         */
        $subSlots = $this->composition->subSlots($siteConfig);
        $menuContexts = [];
        foreach ($this->menuService->getTree($domainId, true) as $node) {
            $code = $node['menu_code'] ?? '';
            $menu = $menus[$code] ?? null;
            $url = $this->menuPreviewUrl($menu);

            // 홈(/)은 "메인화면"이 대체하고, 외부 링크·URL 없는 메뉴는 편집할 화면이 없다.
            if ($code === '' || $url === null || $url === '/') {
                continue;
            }

            $count = 0;
            foreach ($subSlots as $slot) {
                $count += count($this->rowService->getRowsByPosition($domainId, $slot, $code));
            }

            $menuContexts[] = [
                'id' => "menu:{$code}",
                'label' => $menu['label'] ?? $code,
                'depth' => (int) ($node['depth'] ?? 0),
                'preview_url' => $url,
                'row_count' => $count,
            ];
        }

        return [
            'screen' => $screen,
            'menus' => $menuContexts,
            'pages' => $pages,
            // 행 추가 다이얼로그의 "어디에 표시할까요" 선택지 (현재 레이아웃에서
            // 렌더되는 슬롯만, 화면 위→아래 순서)
            'sub_slots' => array_map(
                fn (string $slot) => ['value' => $slot, 'label' => $positions[$slot] ?? $slot],
                $subSlots
            ),
            'main_slots' => array_map(
                fn (string $slot) => [
                    'value' => $slot,
                    'label' => $slot === 'index' ? '메인 본문' : ($positions[$slot] ?? $slot),
                ],
                $this->composition->slots($siteConfig)
            ),
        ];
    }

    /**
     * 컨텍스트 ID → 행 목록 (설계 3.1 표의 행 조회 열).
     *
     * @return BlockRow[]|null 알 수 없는 컨텍스트면 null
     */
    private function resolveContextRows(int $domainId, string $contextId, Context $context): ?array
    {
        if ($contextId === 'screen:main') {
            $siteConfig = $context->getDomainInfo()?->getSiteConfig() ?? [];
            $rows = [];
            foreach ($this->composition->slots($siteConfig) as $slot) {
                foreach ($this->rowService->getRowsByPosition($domainId, $slot) as $row) {
                    $rows[] = $row;
                }
            }
            return $rows;
        }

        if (preg_match('/^page:(\d+)$/', $contextId, $m)) {
            // 도메인 격리 — 남의 도메인 페이지 ID 는 404(설계 9)
            $page = $this->pageService->getPage((int) $m[1], $domainId);
            if (!$page) {
                return null;
            }
            return $this->rowService->getRowsByPage($page->getPageId());
        }

        // 메뉴 페이지 — 그 페이지에서 실제로 보이는 행(전역 + 해당 메뉴)을 슬롯 순서로.
        if (preg_match('/^menu:(.+)$/', $contextId, $m)) {
            $menuCode = $m[1];
            if (!array_key_exists($menuCode, $this->menuItemMap($domainId))) {
                return null;
            }
            $siteConfig = $context->getDomainInfo()?->getSiteConfig() ?? [];
            $rows = [];
            foreach ($this->composition->subSlots($siteConfig) as $slot) {
                foreach ($this->rowService->getRowsByPosition($domainId, $slot, $menuCode) as $row) {
                    $rows[] = $row;
                }
            }
            return $rows;
        }

        // position:{pos} 또는 position:{pos}:{menuCode}
        if (preg_match('/^position:([a-z]+)(?::(.+))?$/', $contextId, $m)) {
            $position = $m[1];
            if (!array_key_exists($position, $this->rowService->getValidPositions())) {
                return null;
            }
            // 전역 컨텍스트는 그 위치의 모든 행(전역+메뉴 스코프),
            // 메뉴 컨텍스트는 그 페이지에서 보이는 행(전역+해당 메뉴)이다.
            return $this->rowService->getRowsByPosition($domainId, $position, $m[2] ?? null);
        }

        return null;
    }

    /**
     * 행 하나를 오버레이·인스펙터가 쓰는 메타로 변환한다 (설계 7.3).
     *
     * @param array<string, string> $positions position => label
     * @param array<string, string> $menuLabels menu_code => label
     * @return array<string, mixed>
     */
    private function toRowMeta(BlockRow $row, array $positions, array $menuLabels): array
    {
        // "전역" 경고는 여러 페이지에 함께 나타나는 행에만 의미가 있다.
        // index 는 메인화면에서만 렌더되므로 제외한다.
        $isGlobal = $row->isPositionRow()
            && $row->getPositionMenu() === null
            && $row->getPosition()?->value !== 'index';

        if ($row->isPageRow()) {
            $scopeLabel = '페이지';
        } else {
            $position = $row->getPosition()?->value ?? '';
            $scopeLabel = $positions[$position] ?? $position;
            $scopeLabel .= $row->getPositionMenu() !== null
                ? ' · ' . ($menuLabels[$row->getPositionMenu()] ?? $row->getPositionMenu())
                : ' · 전역';
        }

        $columns = [];
        $excerpt = null;
        foreach ($this->columnService->getColumnsByRowForDomain(
            $row->getRowId(),
            $row->getDomainId()
        ) ?? [] as $column) {
            $type = $column->getContentTypeString();
            $registered = $type ? BlockRegistry::getContentType($type) : null;

            // 객체형 아이템(쇼핑몰 상품 등 전용 셀렉터 타입) 여부 — 에디터가
            // 일반 체크 피커 대신 전용 설정 화면으로 직행하는 라우팅 근거.
            $items = $column->getContentItems();
            $objectItems = is_array($items) && $items !== [] && is_array($items[array_key_first($items)] ?? null);

            $columnMeta = [
                'column_id' => $column->getColumnId(),
                'index' => $column->getColumnIndex(),
                'content_type' => $type,
                'label' => $registered['title'] ?? $type,
                'installed' => $type === null || $registered !== null,
                'has_content' => $column->hasContent(),
                'object_items' => $objectItems,
                'content_mode' => $column->getContentMode(),
            ];

            // 스택 칸 — 콘텐츠 badge 목록·클릭 라우팅용 요약 (계획 8.3)
            if ($column->isStack()) {
                $columnMeta['contents'] = array_map(
                    static function ($content) {
                        $contentType = $content->getContentTypeString();
                        $contentRegistered = $contentType ? BlockRegistry::getContentType($contentType) : null;
                        return [
                            'content_id' => $content->getContentId(),
                            'content_type' => $contentType,
                            'label' => $contentRegistered['title'] ?? $contentType,
                            'installed' => $contentType === null || $contentRegistered !== null,
                            'is_active' => $content->isActive(),
                        ];
                    },
                    $this->stackContentsForColumn($column)
                );
            }

            $columns[] = $columnMeta;

            // 행 목록 카드에 보여줄 실제 콘텐츠 발췌 — 관리 제목만으로는
            // "이 행이 화면 어디인가"를 알기 어렵다(설계 6.8 직관적 탐색 원칙).
            $excerpt ??= $this->columnExcerpt($column);
        }

        return [
            'row_id' => $row->getRowId(),
            'section_id' => $row->getSectionId(),
            'admin_title' => $row->getAdminTitle() ?: ('행 #' . $row->getRowId()),
            'excerpt' => $excerpt,
            'scope_label' => $scopeLabel,
            // "이 행 아래에 추가"가 새 행의 소속을 상속하는 데 쓴다 (설계 6.4 ①)
            'target' => [
                'page_id' => $row->getPageId() ?? 0,
                'position' => $row->getPosition()?->value ?? '',
                'position_menu' => $row->getPositionMenu() ?? '',
            ],
            'is_global' => $isGlobal,
            'is_active' => $row->isActive(),
            'sort_order' => $row->getSortOrder(),
            'edit_url' => '/admin/block-row/edit?id=' . $row->getRowId(),
            'columns' => $columns,
        ];
    }

    /**
     * 칸의 내용을 한 줄로 발췌한다 — 행 목록 카드의 식별용.
     *
     * 칸 제목이 있으면 그것이 가장 정확한 이름이고, HTML 칸은 본문 텍스트의
     * 앞부분을 딴다. 그 외 타입은 칸 목록(chips)이 이미 말해주므로 null.
     */
    private function columnExcerpt(\Mublo\Entity\Block\BlockColumn $column): ?string
    {
        $title = trim((string) (($column->getTitleConfig() ?? [])['text'] ?? ''));
        if ($title !== '') {
            return mb_strimwidth($title, 0, 60, '…');
        }

        if ($column->getContentTypeString() === 'html') {
            $html = (string) (($column->getContentConfig() ?? [])['html'] ?? '');
            $text = trim(preg_replace('/\s+/u', ' ', strip_tags($html)) ?? '');
            if ($text !== '') {
                return mb_strimwidth($text, 0, 60, '…');
            }
        }

        return null;
    }

    /**
     * 메뉴의 미리보기 URL. 외부 링크·빈 URL 은 미리보기 불가(null).
     *
     * @param array{label: string, url: ?string}|null $menu
     */
    private function menuPreviewUrl(?array $menu): ?string
    {
        $url = $menu['url'] ?? null;
        if (!is_string($url) || $url === '' || !str_starts_with($url, '/')) {
            return null;
        }

        return $url;
    }

    /**
     * menu_code => {label, url} 맵.
     *
     * @return array<string, array{label: string, url: ?string}>
     */
    private function menuItemMap(int $domainId): array
    {
        $map = [];
        foreach ($this->menuService->getItems($domainId, true) as $item) {
            $code = $item['menu_code'] ?? '';
            if ($code === '') {
                continue;
            }
            $map[$code] = [
                'label' => $item['label'] ?? $code,
                'url' => $item['url'] ?? null,
            ];
        }

        return $map;
    }

    /**
     * menu_code => label 맵.
     *
     * @return array<string, string>
     */
    private function menuLabelMap(int $domainId): array
    {
        return array_map(fn (array $m) => $m['label'], $this->menuItemMap($domainId));
    }
}
