<?php

namespace Mublo\Packages\Shop\Controller\Admin;

use Mublo\Core\Response\ViewResponse;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Context\Context;
use Mublo\Helper\Form\FormHelper;
use Mublo\Infrastructure\Storage\UploadedFile;
use Mublo\Infrastructure\Storage\FileUploader;
use Mublo\Packages\Shop\Service\ExhibitionService;
use Mublo\Packages\Shop\Service\ProductService;
use Mublo\Packages\Shop\Helper\ExhibitionUrl;
use Mublo\Service\Menu\MenuService;

class ExhibitionController
{
    /** 기획전 메뉴 아이템 URL 프리픽스 (개별 기획전 상세) */
    private const MENU_URL_PREFIX = '/shop/exhibitions/';

    private ExhibitionService $exhibitionService;
    private ProductService $productService;
    private FileUploader $fileUploader;
    private MenuService $menuService;

    public function __construct(
        ExhibitionService $exhibitionService,
        ProductService $productService,
        FileUploader $fileUploader,
        MenuService $menuService
    ) {
        $this->exhibitionService  = $exhibitionService;
        $this->productService     = $productService;
        $this->fileUploader       = $fileUploader;
        $this->menuService        = $menuService;
    }

    public function index(array $params, Context $context): ViewResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request  = $context->getRequest();
        $page     = (int) ($request->query('page') ?? 1);
        $filters  = array_filter([
            'is_active' => $request->query('is_active') ?? '',
            'keyword'   => $request->query('keyword') ?? '',
        ], fn($v) => $v !== '');

        $result = $this->exhibitionService->getList($domainId, $filters, $page);

        return ViewResponse::absoluteView(dirname(__DIR__, 2) . '/views/Admin/Exhibition/List')
            ->withData([
                'pageTitle'  => '기획전 관리',
                'items'      => $result['items'],
                'pagination' => $result['pagination'],
                'filters'    => $filters,
            ]);
    }

    public function create(array $params, Context $context): ViewResponse
    {
        return ViewResponse::absoluteView(dirname(__DIR__, 2) . '/views/Admin/Exhibition/Form')
            ->withData([
                'pageTitle'  => '기획전 등록',
                'exhibition' => null,
                'items'      => [],
            ]);
    }

    public function edit(array $params, Context $context): ViewResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $id      = (int) ($params['id'] ?? $params[0] ?? $request->query('id', 0));

        $exhibition = $this->exhibitionService->getDetail($domainId, $id);
        if (!$exhibition) {
            return ViewResponse::absoluteView(dirname(__DIR__, 2) . '/views/Admin/Error/404')
                ->withData(['message' => '기획전을 찾을 수 없습니다.']);
        }

        return ViewResponse::absoluteView(dirname(__DIR__, 2) . '/views/Admin/Exhibition/Form')
            ->withData([
                'pageTitle'  => '기획전 수정',
                'exhibition' => $exhibition,
                'items'      => $exhibition['items'] ?? [],
            ]);
    }

    public function store(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request  = $context->getRequest();

        $formData = $request->json('formData') ?? [];
        $data     = FormHelper::normalizeFormData($formData, $this->getFormSchema());

        $exhibitionId = (int) ($data['exhibition_id'] ?? 0);
        $items        = $request->json('items') ?? [];
        unset($data['exhibition_id']);

        if ($exhibitionId) {
            $result = $this->exhibitionService->update($domainId, $exhibitionId, $data);
        } else {
            $result = $this->exhibitionService->create($domainId, $data);
            if ($result->isSuccess()) {
                $exhibitionId = (int) $result->get('exhibition_id', 0);
            }
        }

        // 상품 연결 동기화
        if ($result->isSuccess() && $exhibitionId && !empty($items)) {
            $this->exhibitionService->syncItems($domainId, $exhibitionId, $items);
        }

        return $result->isSuccess()
            ? JsonResponse::success($result->getData(), $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    /**
     * 배너 이미지 즉시 업로드 (파일 → shop/exhibition/ 저장 후 URL 반환)
     */
    public function uploadBanner(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $files    = UploadedFile::fromGlobalNested('fileData', 'banner');
        $file     = $files[0] ?? null;

        if (!$file || !$file->isValid()) {
            return JsonResponse::error('업로드할 이미지를 선택해주세요.');
        }

        $result = $this->fileUploader->upload($file, $domainId, [
            'subdirectory'       => 'shop/exhibition',
            'include_date'       => false,
            'allowed_extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
            'max_size'           => 5 * 1024 * 1024,
        ]);

        if (!$result->isSuccess()) {
            return JsonResponse::error($result->getMessage() ?: '업로드에 실패했습니다.');
        }

        $url = $this->fileUploader->getUrl($result->getRelativePath(), $result->getStoredName());

        return JsonResponse::success(['url' => $url], '업로드되었습니다.');
    }

    public function delete(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request      = $context->getRequest();
        $exhibitionId = (int) ($request->json('exhibition_id') ?? 0);

        $result = $this->exhibitionService->delete($domainId, $exhibitionId);

        return $result->isSuccess()
            ? JsonResponse::success(null, $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    public function listDelete(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $ids = array_values(array_filter(
            array_map('intval', (array) ($request->input('chk') ?? [])),
            fn($id) => $id > 0
        ));

        if (empty($ids)) {
            return JsonResponse::error('삭제할 기획전을 선택해주세요.');
        }

        $deleted = 0;
        foreach ($ids as $id) {
            if ($this->exhibitionService->delete($domainId, $id)->isSuccess()) {
                $deleted++;
            }
        }

        return JsonResponse::success(null, "{$deleted}건이 삭제되었습니다.");
    }

    /**
     * 선택 기획전을 메뉴 아이템으로 수동 등록.
     *
     * 생성 시 자동 등록되지만 관리자가 메뉴에서 지웠을 때 다시 올리기 위한 경로.
     * (카테고리 메뉴 등록과 동일한 수동 배치 방식)
     */
    public function menuRegister(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request  = $context->getRequest();
        $ids = array_values(array_filter(
            array_map('intval', (array) ($request->input('chk') ?? [])),
            fn($id) => $id > 0
        ));

        if (empty($ids)) {
            return JsonResponse::error('등록할 기획전을 선택해주세요.');
        }

        // 이미 등록된 기획전 메뉴 URL 집합 (slug/id 어느 형태든)
        $existing = $this->menuService->findItemsByUrlPrefix($domainId, self::MENU_URL_PREFIX);
        $registeredUrls = [];
        foreach ($existing as $item) {
            $registeredUrls[$item['url']] = true;
        }

        $created = 0;
        $skipped = 0;

        foreach ($ids as $id) {
            $exhibition = $this->exhibitionService->getDetail($domainId, $id);
            if (!$exhibition) {
                continue;
            }

            $slugUrl = ExhibitionUrl::detail($id, $exhibition['slug'] ?? null);
            $idUrl   = ExhibitionUrl::detail($id);

            // slug/id 어느 형태로든 이미 등록돼 있으면 skip
            if (isset($registeredUrls[$slugUrl]) || isset($registeredUrls[$idUrl])) {
                $skipped++;
                continue;
            }

            $result = $this->menuService->createItem($domainId, [
                'label'         => $exhibition['title'] ?? '',
                'url'           => $slugUrl,
                'provider_type' => 'package',
                'provider_name' => 'Shop',
            ]);

            if ($result->isSuccess()) {
                $created++;
                $registeredUrls[$slugUrl] = true;
            }
        }

        $msg = "{$created}건 메뉴 등록 완료";
        if ($skipped > 0) {
            $msg .= " ({$skipped}건 이미 등록됨)";
        }

        return JsonResponse::success(['created' => $created, 'skipped' => $skipped], $msg);
    }

    public function addItem(array $params, Context $context): JsonResponse
    {
        $domainId     = $context->getDomainId() ?? 1;
        $request      = $context->getRequest();
        $data         = $request->json();
        $exhibitionId = (int) ($data['exhibition_id'] ?? 0);

        $result = $this->exhibitionService->addItem($domainId, $exhibitionId, $data);

        return $result->isSuccess()
            ? JsonResponse::success($result->getData(), $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    public function deleteItem(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $itemId  = (int) ($request->json('item_id') ?? 0);

        $result = $this->exhibitionService->deleteItem($domainId, $itemId);

        return $result->isSuccess()
            ? JsonResponse::success(null, $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    public function syncItems(array $params, Context $context): JsonResponse
    {
        $domainId     = $context->getDomainId() ?? 1;
        $request      = $context->getRequest();
        $data         = $request->json();
        $exhibitionId = (int) ($data['exhibition_id'] ?? 0);
        $items        = $data['items'] ?? [];

        $result = $this->exhibitionService->syncItems($domainId, $exhibitionId, $items);

        return $result->isSuccess()
            ? JsonResponse::success(null, $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    private function getFormSchema(): array
    {
        return [
            'numeric' => ['exhibition_id', 'is_active', 'sort_order'],
            'date'    => ['start_date', 'end_date'],
        ];
    }
}
