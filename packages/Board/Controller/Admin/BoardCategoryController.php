<?php
namespace Mublo\Packages\Board\Controller\Admin;

use Mublo\Core\Response\ViewResponse;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Context\Context;
use Mublo\Packages\Board\Service\BoardCategoryService;
use Mublo\Helper\Form\FormHelper;

/**
 * Admin BoardCategoryController
 *
 * 게시판 카테고리 관리 컨트롤러
 *
 * 자동 라우팅:
 * - GET  /admin/board/category              → index
 * - GET  /admin/board/category/create       → create
 * - GET  /admin/board/category/edit         → edit (쿼리: ?id=123)
 * - POST /admin/board/category/store        → store
 * - POST /admin/board/category/delete       → delete
 * - POST /admin/board/category/order-update → orderUpdate
 */
class BoardCategoryController
{
    private BoardCategoryService $categoryService;

    public function __construct(BoardCategoryService $categoryService)
    {
        $this->categoryService = $categoryService;
    }

    /**
     * 카테고리 목록
     *
     * GET /admin/board/category
     */
    public function index(array $params, Context $context): ViewResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();

        $page = max(1, (int) $request->query('page', 1));
        $perPage = 20;

        $result = $this->categoryService->getCategoriesWithCount($domainId, $page, $perPage);

        return ViewResponse::absoluteView(dirname(__DIR__, 2) . '/views/Admin/Category/Index')
            ->withData([
                'pageTitle' => '게시판 카테고리 관리',
                'categories' => $result['items'],
                'pagination' => $result['pagination'],
            ]);
    }

    /**
     * 카테고리 생성 폼
     *
     * GET /admin/board/category/create
     */
    public function create(array $params, Context $context): ViewResponse
    {
        return ViewResponse::absoluteView(dirname(__DIR__, 2) . '/views/Admin/Category/Form')
            ->withData([
                'pageTitle' => '카테고리 추가',
                'isEdit' => false,
                'category' => null,
            ]);
    }

    /**
     * 카테고리 수정 폼
     *
     * GET /admin/board/category/edit?id=123
     */
    public function edit(array $params, Context $context): ViewResponse
    {
        $request = $context->getRequest();
        $categoryId = (int) $request->query('id', 0);

        if ($categoryId === 0 && isset($params[0])) {
            $categoryId = (int) $params[0];
        }

        $category = $this->categoryService->getCategory($categoryId);

        if (!$category) {
            return ViewResponse::view('Error/404')
                ->withData(['message' => '카테고리를 찾을 수 없습니다.']);
        }

        return ViewResponse::absoluteView(dirname(__DIR__, 2) . '/views/Admin/Category/Form')
            ->withData([
                'pageTitle' => '카테고리 수정',
                'isEdit' => true,
                'category' => $category->toArray(),
                'boardCount' => $this->categoryService->getBoardCount($categoryId),
            ]);
    }

    /**
     * 카테고리 저장 (생성/수정)
     *
     * POST /admin/board/category/store
     */
    public function store(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();

        $formData = $request->input('formData') ?? [];
        $data = FormHelper::normalizeFormData($formData, $this->getFormSchema());

        $categoryId = (int) ($data['category_id'] ?? 0);

        if ($categoryId > 0) {
            // 수정
            $result = $this->categoryService->updateCategory($categoryId, $data);
        } else {
            // 생성
            $result = $this->categoryService->createCategory($domainId, $data);
        }

        if ($result->isSuccess()) {
            return JsonResponse::success(
                ['redirect' => '/admin/board/category'],
                $result->getMessage()
            );
        }

        return JsonResponse::error($result->getMessage());
    }

    /**
     * 카테고리 삭제
     *
     * POST /admin/board/category/delete
     */
    public function delete(array $params, Context $context): JsonResponse
    {
        $request = $context->getRequest();
        $categoryId = (int) $request->json('category_id', 0);

        if ($categoryId <= 0) {
            return JsonResponse::error('카테고리 ID가 필요합니다.');
        }

        $result = $this->categoryService->deleteCategory($categoryId);

        if ($result->isSuccess()) {
            return JsonResponse::success(null, $result->getMessage());
        }

        return JsonResponse::error($result->getMessage());
    }

    /**
     * 정렬 순서 변경
     *
     * POST /admin/board/category/order-update
     */
    public function orderUpdate(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $categoryIds = $request->json('category_ids', []);

        if (empty($categoryIds) || !is_array($categoryIds)) {
            return JsonResponse::error('정렬할 카테고리 목록이 필요합니다.');
        }

        $result = $this->categoryService->updateOrder($domainId, $categoryIds);

        if ($result->isSuccess()) {
            return JsonResponse::success(null, $result->getMessage());
        }

        return JsonResponse::error($result->getMessage());
    }

    /**
     * 목록 일괄 수정
     *
     * POST /admin/board/category/list-update
     */
    public function listUpdate(array $params, Context $context): JsonResponse
    {
        $request = $context->getRequest();

        $chk = $request->input('chk') ?? [];
        $isActiveList = $request->input('is_active') ?? [];

        if (empty($chk)) {
            return JsonResponse::error('수정할 항목을 선택해주세요.');
        }

        // chk에 있는 항목만 is_active 값 수집
        $items = [];
        foreach ($chk as $categoryId) {
            $categoryId = (int) $categoryId;
            if (isset($isActiveList[$categoryId])) {
                $items[$categoryId] = $isActiveList[$categoryId];
            }
        }

        $result = $this->categoryService->batchUpdateIsActive($items);

        if ($result->isSuccess()) {
            return JsonResponse::success(
                ['updated' => $result->get('updated')],
                $result->getMessage()
            );
        }

        return JsonResponse::error($result->getMessage());
    }

    /**
     * 목록 일괄 삭제
     *
     * POST /admin/board/category/list-delete
     */
    public function listDelete(array $params, Context $context): JsonResponse
    {
        $request = $context->getRequest();

        $chk = $request->input('chk') ?? [];

        if (empty($chk)) {
            return JsonResponse::error('삭제할 항목을 선택해주세요.');
        }

        $deleted = 0;
        $failed = 0;

        foreach ($chk as $categoryId) {
            $categoryId = (int) $categoryId;
            $result = $this->categoryService->deleteCategory($categoryId);
            if ($result->isSuccess()) {
                $deleted++;
            } else {
                $failed++;
            }
        }

        if ($deleted > 0) {
            $message = "{$deleted}개 항목이 삭제되었습니다.";
            if ($failed > 0) {
                $message .= " ({$failed}개는 사용 중인 게시판이 있어 삭제 불가)";
            }
            return JsonResponse::success(['deleted' => $deleted], $message);
        }

        return JsonResponse::error('삭제할 수 있는 항목이 없습니다. (사용 중인 게시판이 있는 카테고리는 삭제 불가)');
    }

    /**
     * 슬러그 중복 확인
     *
     * POST /admin/board/category/check-slug
     */
    public function checkSlug(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();

        $slug = $request->json('slug', '');
        $excludeId = (int) $request->json('exclude_id', 0);

        $result = $this->categoryService->checkSlugAvailability(
            $domainId,
            $slug,
            $excludeId > 0 ? $excludeId : null
        );

        if ($result->isSuccess()) {
            return JsonResponse::success(null, $result->getMessage());
        }

        return JsonResponse::error($result->getMessage());
    }

    /**
     * 폼 데이터 스키마
     */
    private function getFormSchema(): array
    {
        return [
            'numeric' => ['category_id', 'sort_order'],
            'bool' => ['is_active'],
            'required_string' => ['category_slug', 'category_name'],
        ];
    }
}
