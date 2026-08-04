<?php
declare(strict_types=1);
namespace Mublo\Plugin\Banner\Controller;

use Mublo\Core\Context\Context;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Core\Extension\MigrationRunner;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Response\ViewResponse;
use Mublo\Helper\Form\FormHelper;
use Mublo\Infrastructure\Storage\FileUploader;
use Mublo\Infrastructure\Storage\UploadedFile;
use Mublo\Plugin\Banner\Event\BannerFormBuildEvent;
use Mublo\Plugin\Banner\Service\BannerService;

/**
 * BannerController
 *
 * 배너 관리 (Admin)
 */
class BannerController
{
    private BannerService $bannerService;
    private MigrationRunner $migrationRunner;
    private FileUploader $fileUploader;
    private ?EventDispatcher $eventDispatcher;

    private const PLUGIN_NAME = 'Banner';
    private const VIEW_PATH = MUBLO_PLUGIN_PATH . '/Banner/views/Admin/';

    public function __construct(
        BannerService $bannerService,
        MigrationRunner $migrationRunner,
        FileUploader $fileUploader,
        ?EventDispatcher $eventDispatcher = null
    ) {
        $this->bannerService = $bannerService;
        $this->migrationRunner = $migrationRunner;
        $this->fileUploader = $fileUploader;
        $this->eventDispatcher = $eventDispatcher;
    }

    /**
     * 배너 목록
     */
    public function index(array $params, Context $context): ViewResponse
    {
        // 마이그레이션 체크
        $status = $this->migrationRunner->getStatus('plugin', self::PLUGIN_NAME, $this->getMigrationPath());
        if (!empty($status['pending'])) {
            return ViewResponse::absoluteView(self::VIEW_PATH . 'Install')
                ->withData([
                    'pageTitle' => '배너 플러그인 설치',
                    'pending' => $status['pending'],
                ]);
        }

        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();

        $page = max(1, (int) $request->query('page', 1));
        $keyword = $request->query('keyword', '');

        $result = $this->bannerService->getList($domainId, $page, 20, [
            'keyword' => $keyword,
        ]);

        return ViewResponse::absoluteView(self::VIEW_PATH . 'List')
            ->withData([
                'pageTitle' => '배너 관리',
                'items' => $result->get('items', []),
                'pagination' => [
                    'totalItems' => $result->get('totalItems', 0),
                    'perPage' => $result->get('perPage', 20),
                    'currentPage' => $result->get('currentPage', 1),
                    'totalPages' => $result->get('totalPages', 0),
                ],
                'search' => ['keyword' => $keyword],
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

        return ($return === '/admin/banner/list' || str_starts_with($return, '/admin/banner/list?'))
            ? $return
            : '/admin/banner/list';
    }

    /**
     * 배너 생성 폼
     */
    public function create(array $params, Context $context): ViewResponse
    {
        $domainId = $context->getDomainId() ?? 1;

        $formEvent = new BannerFormBuildEvent($domainId, []);
        $this->eventDispatcher?->dispatch($formEvent);

        return ViewResponse::absoluteView(self::VIEW_PATH . 'Form')
            ->withData([
                'pageTitle' => '배너 추가',
                'isEdit' => false,
                'banner' => [],
                'extensionFields' => $formEvent->getFields(),
            ]);
    }

    /**
     * 배너 수정 폼
     */
    public function edit(array $params, Context $context): ViewResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $bannerId = (int) ($params['id'] ?? 0);
        $listUrl  = $this->resolveListUrl($context);
        $result = $this->bannerService->getBanner($domainId, $bannerId);

        if ($result->isFailure()) {
            return ViewResponse::absoluteView(self::VIEW_PATH . 'Form')
                ->withData([
                    'pageTitle' => '배너 수정',
                    'isEdit' => true,
                    'banner' => [],
                    'extensionFields' => [],
                    'error' => $result->getMessage(),
                    'listUrl' => $listUrl,
                ]);
        }

        $banner = $result->get('banner', []);
        $formEvent = new BannerFormBuildEvent($domainId, $banner);
        $this->eventDispatcher?->dispatch($formEvent);

        return ViewResponse::absoluteView(self::VIEW_PATH . 'Form')
            ->withData([
                'pageTitle' => '배너 수정',
                'isEdit' => true,
                'banner' => $banner,
                'extensionFields' => $formEvent->getFields(),
                'listUrl' => $listUrl,
            ]);
    }

    /**
     * 플러그인 설치 (마이그레이션 실행)
     */
    public function install(array $params, Context $context): JsonResponse
    {
        $result = $this->migrationRunner->run('plugin', self::PLUGIN_NAME, $this->getMigrationPath());

        if ($result['success']) {
            return JsonResponse::success(
                ['redirect' => '/admin/banner/list'],
                '배너 플러그인이 설치되었습니다. (실행: ' . count($result['executed']) . '개)'
            );
        }

        return JsonResponse::error('설치 실패: ' . ($result['error'] ?? '알 수 없는 오류'));
    }

    /**
     * 배너 저장 (생성/수정)
     */
    public function store(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();

        $formData = $request->input('formData') ?? [];
        $data = FormHelper::normalizeFormData($formData, $this->getFormSchema());

        // 파일 업로드 처리 (fileData[pc_image], fileData[mo_image])
        $imageUrls = $this->processImageUploads($domainId);
        foreach ($imageUrls as $field => $url) {
            $data[$field] = $url;
        }

        $bannerId = (int) ($data['banner_id'] ?? 0);

        if ($bannerId > 0) {
            $result = $this->bannerService->update($domainId, $bannerId, $data);
        } else {
            $result = $this->bannerService->create($domainId, $data);
        }

        if ($result->isSuccess()) {
            return JsonResponse::success(
                ['redirect' => '/admin/banner/list'],
                $result->getMessage()
            );
        }

        return JsonResponse::error($result->getMessage());
    }

    /**
     * 이미지 파일 업로드 처리
     *
     * @return array ['pc_image_url' => string, 'mo_image_url' => string] (업로드된 필드만 포함)
     */
    private function processImageUploads(int $domainId): array
    {
        $result = [];

        $imageFields = [
            'pc_image' => 'pc_image_url',
            'mo_image' => 'mo_image_url',
        ];

        foreach ($imageFields as $fileField => $dataField) {
            $files = UploadedFile::fromGlobalNested('fileData', $fileField);
            if (empty($files)) {
                continue;
            }

            $file = is_array($files) ? $files[0] : $files;

            if ($file instanceof UploadedFile && $file->isValid()) {
                $uploadResult = $this->fileUploader->upload($file, $domainId, [
                    'subdirectory' => 'banner',
                    'allowed_extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
                    'max_size' => 5 * 1024 * 1024,
                ]);

                if ($uploadResult->isSuccess()) {
                    $result[$dataField] = '/storage/'
                        . $uploadResult->getRelativePath() . '/'
                        . $uploadResult->getStoredName();
                }
            }
        }

        return $result;
    }

    /**
     * 배너 삭제
     */
    public function delete(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $bannerId = (int) ($params['id'] ?? 0);
        $result = $this->bannerService->delete($domainId, $bannerId);

        if ($result->isSuccess()) {
            return JsonResponse::success(
                ['redirect' => '/admin/banner/list'],
                $result->getMessage()
            );
        }

        return JsonResponse::error($result->getMessage());
    }

    /**
     * 배너 일괄 삭제
     */
    public function listDelete(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $ids = $request->input('chk') ?? [];

        if (empty($ids)) {
            return JsonResponse::error('삭제할 배너를 선택해주세요.');
        }

        $deleted = 0;
        foreach ($ids as $id) {
            $result = $this->bannerService->delete($domainId, (int) $id);
            if ($result->isSuccess()) {
                $deleted++;
            }
        }

        if ($deleted === 0) {
            return JsonResponse::error('삭제할 배너가 없습니다.');
        }

        return JsonResponse::success(
            ['redirect' => '/admin/banner/list'],
            "{$deleted}건이 삭제되었습니다."
        );
    }

    /**
     * 순서 변경 (AJAX)
     */
    public function sort(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $items = $request->input('items') ?? $request->json('items') ?? [];

        if (empty($items)) {
            return JsonResponse::error('정렬할 항목이 없습니다.');
        }

        $result = $this->bannerService->updateOrder($domainId, $items);

        if ($result->isSuccess()) {
            return JsonResponse::success([], $result->getMessage());
        }

        return JsonResponse::error($result->getMessage());
    }

    /**
     * 블록 에디터용 배너 목록 (AJAX)
     */
    public function blockItems(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $items = $this->bannerService->getListForBlock($domainId);

        return JsonResponse::success([
            'items' => $items,
        ]);
    }

    private function getMigrationPath(): string
    {
        return MUBLO_PLUGIN_PATH . '/Banner/database/migrations';
    }

    private function getFormSchema(): array
    {
        return [
            'numeric' => ['banner_id', 'sort_order'],
            'bool' => ['is_active'],
            'date' => ['start_date', 'end_date'],
        ];
    }
}
