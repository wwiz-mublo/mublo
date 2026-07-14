<?php
namespace Mublo\Plugin\Manual\Controller\Admin;

use Mublo\Core\Context\Context;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Response\ViewResponse;
use Mublo\Helper\Form\FormHelper;
use Mublo\Plugin\Manual\Service\ManualService;

/**
 * 매뉴얼 페이지(트리) 관리 Controller (Admin)
 */
class ManualPageController
{
    private const VIEW_PATH = MUBLO_PLUGIN_PATH . '/Manual/views/Admin/';

    public function __construct(
        private ManualService $manualService,
    ) {
    }

    /**
     * 책의 페이지 트리 목록
     *
     * GET /admin/manual/pages/{bookId}
     */
    public function index(array $params, Context $context): ViewResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $bookId = (int) ($params['bookId'] ?? 0);

        $result = $this->manualService->getPageTreeAdmin($domainId, $bookId);
        if (!$result->isSuccess()) {
            return ViewResponse::absoluteView(self::VIEW_PATH . 'Book/List')
                ->withData([
                    'pageTitle'  => '매뉴얼 관리',
                    'books'      => $this->manualService->getBookList($domainId)->get('books', []),
                    'flashError' => $result->getMessage(),
                ]);
        }

        return ViewResponse::absoluteView(self::VIEW_PATH . 'Page/List')
            ->withData([
                'pageTitle' => '페이지 관리',
                'book'      => $result->get('book'),
                'tree'      => $result->get('tree', []),
            ]);
    }

    /**
     * 페이지 트리 (AJAX, JSON)
     *
     * GET /admin/manual/page/tree/{bookId}
     */
    public function tree(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $bookId = (int) ($params['bookId'] ?? 0);

        $result = $this->manualService->getPageTreeAdmin($domainId, $bookId);
        if (!$result->isSuccess()) {
            return JsonResponse::error($result->getMessage());
        }

        return JsonResponse::success(['tree' => $result->get('tree', [])]);
    }

    /**
     * 페이지 생성 폼
     *
     * GET /admin/manual/page/create/{bookId}
     */
    public function create(array $params, Context $context): ViewResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $bookId = (int) ($params['bookId'] ?? 0);

        $bookResult = $this->manualService->getBook($domainId, $bookId);
        if (!$bookResult->isSuccess()) {
            return ViewResponse::absoluteView(self::VIEW_PATH . 'Book/List')
                ->withData([
                    'pageTitle'  => '매뉴얼 관리',
                    'books'      => $this->manualService->getBookList($domainId)->get('books', []),
                    'flashError' => $bookResult->getMessage(),
                ]);
        }

        $tree = $this->manualService->getPageTreeAdmin($domainId, $bookId)->get('tree', []);
        $parentId = (int) ($context->getRequest()->query('parent_id', 0));

        return ViewResponse::absoluteView(self::VIEW_PATH . 'Page/Form')
            ->withData([
                'pageTitle'      => '페이지 등록',
                'book'           => $bookResult->get('book'),
                'page'           => null,
                'parentOptions'  => $this->flattenForSelect($tree),
                'selectedParent' => $parentId > 0 ? $parentId : null,
            ]);
    }

    /**
     * 페이지 수정 폼
     *
     * GET /admin/manual/page/edit/{id}
     */
    public function edit(array $params, Context $context): ViewResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $pageId = (int) ($params['id'] ?? 0);

        $result = $this->manualService->getPage($domainId, $pageId);
        if (!$result->isSuccess()) {
            return ViewResponse::absoluteView(self::VIEW_PATH . 'Book/List')
                ->withData([
                    'pageTitle'  => '매뉴얼 관리',
                    'books'      => $this->manualService->getBookList($domainId)->get('books', []),
                    'flashError' => $result->getMessage(),
                ]);
        }

        $page = $result->get('page');
        $book = $result->get('book');
        $tree = $this->manualService->getPageTreeAdmin($domainId, (int) $book['book_id'])->get('tree', []);

        return ViewResponse::absoluteView(self::VIEW_PATH . 'Page/Form')
            ->withData([
                'pageTitle'      => '페이지 수정',
                'book'           => $book,
                'page'           => $page,
                // 자기 자신 및 자손은 부모 후보에서 제외
                'parentOptions'  => $this->flattenForSelect($tree, (int) $page['page_id']),
                'selectedParent' => $page['parent_id'] !== null ? (int) $page['parent_id'] : null,
            ]);
    }

    /**
     * 페이지 생성
     *
     * POST /admin/manual/page
     */
    public function store(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $data = $this->formData($context);

        $result = $this->manualService->createPage($domainId, $data);

        return $result->isSuccess()
            ? JsonResponse::success($result->getData(), $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    /**
     * 페이지 수정
     *
     * PUT /admin/manual/page
     */
    public function update(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $data = $this->formData($context);
        $pageId = (int) ($data['page_id'] ?? 0);

        if ($pageId <= 0) {
            return JsonResponse::error('페이지를 선택해 주세요.');
        }

        $result = $this->manualService->updatePage($domainId, $pageId, $data);

        return $result->isSuccess()
            ? JsonResponse::success($result->getData(), $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    /**
     * 페이지 삭제
     *
     * DELETE /admin/manual/page
     */
    public function delete(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $data = $this->formData($context);
        $pageId = (int) ($data['page_id'] ?? 0);

        if ($pageId <= 0) {
            return JsonResponse::error('페이지를 선택해 주세요.');
        }

        $result = $this->manualService->deletePage($domainId, $pageId);

        return $result->isSuccess()
            ? JsonResponse::success($result->getData(), $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    /**
     * 트리 순서/계층 저장 (드래그)
     *
     * PUT /admin/manual/page/save-tree
     */
    public function saveTree(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $payload = $request->json() ?: [];

        $bookId = (int) ($payload['book_id'] ?? 0);
        $nodes = $payload['nodes'] ?? [];

        if ($bookId <= 0) {
            return JsonResponse::error('매뉴얼을 선택해 주세요.');
        }
        if (!is_array($nodes) || empty($nodes)) {
            return JsonResponse::error('저장할 목차가 없습니다.');
        }

        $result = $this->manualService->saveTree($domainId, $bookId, $nodes);

        return $result->isSuccess()
            ? JsonResponse::success([], $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    /**
     * 요청 본문 정규화 (JSON 또는 formData)
     */
    private function formData(Context $context): array
    {
        $request = $context->getRequest();
        $raw = $request->json() ?: ($request->input('formData') ?? []);

        return FormHelper::normalizeFormData($raw, [
            'numeric'         => ['page_id', 'book_id', 'parent_id', 'sort_order', 'is_active'],
            'required_string' => ['title', 'slug'],
            'html'            => ['content'],
        ]);
    }

    /**
     * 트리 → 부모 선택용 평탄 목록 (들여쓰기 라벨). $excludeId 및 그 자손은 제외.
     *
     * @return array [{page_id, label}, ...]
     */
    private function flattenForSelect(array $tree, int $excludeId = 0): array
    {
        $out = [];
        $walk = function (array $nodes, int $depth) use (&$walk, &$out, $excludeId): void {
            foreach ($nodes as $node) {
                if ((int) $node['page_id'] === $excludeId) {
                    continue; // 자기 자신 + 하위 서브트리 통째 제외
                }
                $out[] = [
                    'page_id' => (int) $node['page_id'],
                    'label'   => str_repeat('— ', $depth) . $node['title'],
                ];
                if (!empty($node['children'])) {
                    $walk($node['children'], $depth + 1);
                }
            }
        };
        $walk($tree, 0);
        return $out;
    }
}
