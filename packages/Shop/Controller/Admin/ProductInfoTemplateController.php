<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\Controller\Admin;

use Mublo\Core\Response\ViewResponse;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Context\Context;
use Mublo\Helper\Form\FormHelper;
use Mublo\Helper\Editor\EditorHelper;
use Mublo\Packages\Shop\Service\ProductInfoTemplateService;
use Mublo\Packages\Shop\Service\CategoryService;

class ProductInfoTemplateController
{
    private ProductInfoTemplateService $templateService;
    private CategoryService $categoryService;

    public function __construct(ProductInfoTemplateService $templateService, CategoryService $categoryService)
    {
        $this->templateService = $templateService;
        $this->categoryService = $categoryService;
    }

    public function index(array $params, Context $context): ViewResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $page = (int) ($request->query('page') ?? 1);
        $filters = array_filter([
            'status' => $request->query('status') ?? '',
            'keyword' => $request->query('keyword') ?? '',
        ], fn($v) => $v !== '');

        $result = $this->templateService->getList($domainId, $filters, $page);

        return ViewResponse::absoluteView(dirname(__DIR__, 2) . '/views/Admin/ProductInfoTemplate/List')
            ->withData([
                'pageTitle' => '상품정보 템플릿',
                'items' => $result['items'],
                'pagination' => $result['pagination'],
                'filters' => $filters,
            ]);
    }

    public function create(array $params, Context $context): ViewResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $categories = $this->getCategoryOptions($domainId);

        return ViewResponse::absoluteView(dirname(__DIR__, 2) . '/views/Admin/ProductInfoTemplate/Form')
            ->withData([
                'pageTitle' => '상품정보 템플릿 등록',
                'template' => null,
                'categories' => $categories,
            ]);
    }

    public function edit(array $params, Context $context): ViewResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $id = (int) ($params['id'] ?? $params[0] ?? $request->query('id', 0));

        $template = $this->templateService->getTemplateById($domainId, $id);
        if (!$template) {
            return ViewResponse::absoluteView(dirname(__DIR__, 2) . '/views/Admin/Error/404')
                ->withData(['message' => '템플릿을 찾을 수 없습니다.']);
        }

        $categories = $this->getCategoryOptions($domainId);

        return ViewResponse::absoluteView(dirname(__DIR__, 2) . '/views/Admin/ProductInfoTemplate/Form')
            ->withData([
                'pageTitle' => '상품정보 템플릿 수정',
                'template' => $template,
                'categories' => $categories,
            ]);
    }

    private function getCategoryOptions(int $domainId): array
    {
        $result = $this->categoryService->getTree($domainId);
        return $result->isSuccess() ? $result->get('items', []) : [];
    }

    public function store(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();

        $formData = $request->input('formData') ?? [];
        $data = FormHelper::normalizeFormData($formData, $this->getFormSchema());

        if (!empty($data['content'])) {
            $storagePath = defined('MUBLO_PUBLIC_STORAGE_PATH') ? MUBLO_PUBLIC_STORAGE_PATH : 'public/storage';
            $data['content'] = EditorHelper::processImages(
                $data['content'],
                'shop/template/' . date('Y/m'),
                $storagePath . '/D' . $domainId,
                '/storage/D' . $domainId
            );
        }

        $id = (int) ($data['template_id'] ?? 0);
        unset($data['template_id']);

        $result = $this->templateService->save($domainId, $data, $id ?: null);

        return $result->isSuccess()
            ? JsonResponse::success($result->getData(), $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    public function delete(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $id = (int) ($request->json('template_id') ?? 0);

        if (!$id) {
            return JsonResponse::error('삭제할 템플릿을 선택해주세요.');
        }

        $result = $this->templateService->delete($domainId, $id);

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
            return JsonResponse::error('삭제할 템플릿을 선택해주세요.');
        }

        $deleted = 0;
        foreach ($ids as $id) {
            if ($this->templateService->delete($domainId, $id)->isSuccess()) {
                $deleted++;
            }
        }

        return JsonResponse::success(null, "{$deleted}건이 삭제되었습니다.");
    }

    public function listSort(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $sorts = (array) ($request->json('sorts') ?? []);

        if (empty($sorts)) {
            return JsonResponse::error('저장할 정렬순서가 없습니다.');
        }

        $result = $this->templateService->updateSortOrders($domainId, $sorts);

        return $result->isSuccess()
            ? JsonResponse::success(null, $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    private function getFormSchema(): array
    {
        return [
            'numeric' => ['template_id', 'sort_order'],
            'html' => ['content'],
            'enum' => [
                'status' => ['values' => ['Y', 'N'], 'default' => 'Y'],
            ],
        ];
    }
}
