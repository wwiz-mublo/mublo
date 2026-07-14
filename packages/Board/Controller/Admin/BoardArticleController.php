<?php
namespace Mublo\Packages\Board\Controller\Admin;

use Mublo\Core\Response\ViewResponse;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Context\Context;
use Mublo\Packages\Board\Service\BoardArticleService;
use Mublo\Packages\Board\Service\BoardConfigService;
use Mublo\Packages\Board\Service\BoardCategoryService;
use Mublo\Packages\Board\Service\BoardCommentService;
use Mublo\Packages\Board\Service\BoardFileService;
use Mublo\Packages\Board\Helper\BoardContentSanitizer;
use Mublo\Helper\Form\FormHelper;

/**
 * Admin BoardArticleController
 *
 * 게시글 관리 컨트롤러
 *
 * 자동 라우팅:
 * - GET  /admin/board/article              → index (목록)
 * - GET  /admin/board/article/create       → create (작성 폼)
 * - GET  /admin/board/article/edit         → edit (수정 폼)
 * - GET  /admin/board/article/view         → view (상세)
 * - POST /admin/board/article/store        → store (저장)
 * - POST /admin/board/article/status-update → statusUpdate (상태 변경)
 * - POST /admin/board/article/bulk-delete  → bulkDelete (일괄 삭제)
 * - POST /admin/board/article/bulk-status-update → bulkStatusUpdate (일괄 상태 변경)
 */
class BoardArticleController
{
    private BoardArticleService $articleService;
    private BoardConfigService $boardService;
    private BoardCategoryService $categoryService;
    private BoardCommentService $commentService;
    private BoardFileService $fileService;

    public function __construct(
        BoardArticleService $articleService,
        BoardConfigService $boardService,
        BoardCategoryService $categoryService,
        BoardCommentService $commentService,
        BoardFileService $fileService
    ) {
        $this->articleService = $articleService;
        $this->boardService = $boardService;
        $this->categoryService = $categoryService;
        $this->commentService = $commentService;
        $this->fileService = $fileService;
    }

    /**
     * 게시글 관리 목록
     *
     * GET /admin/board/article
     */
    public function index(array $params, Context $context): ViewResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();

        // 필터 파라미터
        $page = max(1, (int) $request->query('page', 1));
        $perPage = (int) ($context->getDomainInfo()?->getSiteConfig()['per_page'] ?? 20);

        $filters = [
            'board_id' => $request->query('board_id', ''),
            'status' => $request->query('status', 'all'),
            'search_field' => $request->query('search_field', 'title'),
            'keyword' => $request->query('keyword', ''),
        ];

        // 게시글 목록 조회
        $result = $this->articleService->getAdminList($domainId, $page, $perPage, $filters);

        // 게시판 목록 (필터용)
        $boards = $this->boardService->getBoardsWithGroup($domainId);

        return ViewResponse::absoluteView(dirname(__DIR__, 2) . '/views/Admin/Article/Index')
            ->withData([
                'pageTitle' => '게시글 관리',
                'articles' => $result['items'],
                'pagination' => $result['pagination'],
                'filters' => $filters,
                'boards' => $boards,
                'statusOptions' => [
                    'all' => '전체',
                    'published' => '발행',
                    'draft' => '임시저장',
                    'deleted' => '삭제됨',
                ],
                'searchFieldOptions' => [
                    'title' => '제목',
                    'content' => '내용',
                    'author' => '작성자',
                ],
            ]);
    }

    /**
     * 게시글 상세 보기
     *
     * GET /admin/board/article/view?id=123
     */
    public function view(array $params, Context $context): ViewResponse
    {
        $request = $context->getRequest();
        $articleId = (int) $request->query('id', 0);

        if ($articleId === 0 && isset($params[0])) {
            $articleId = (int) $params[0];
        }

        // 게시글 조회 (조회수 증가 안함)
        $result = $this->articleService->getArticle($articleId, $context, false);

        if ($result->isFailure()) {
            return ViewResponse::view('Error/404')
                ->withData(['message' => $result->getMessage() ?: '게시글을 찾을 수 없습니다.']);
        }

        $data = $result->getData();

        // 댓글 목록
        $comments = $this->commentService->getCommentsByArticle($articleId);

        // 첨부파일 목록
        $attachments = $this->fileService->getAttachmentsByArticle($articleId);

        // 링크 목록
        $links = $this->fileService->getLinksByArticle($articleId);

        return ViewResponse::absoluteView(dirname(__DIR__, 2) . '/views/Admin/Article/View')
            ->withData([
                'pageTitle' => '게시글 상세',
                'article' => $data['article'] ?? null,
                'author' => $data['author'] ?? null,
                'board' => $data['board'] ?? null,
                'comments' => $comments,
                'attachments' => $attachments,
                'links' => $links,
            ]);
    }

    /**
     * 게시글 작성 폼
     *
     * GET /admin/board/article/create?board_id=1
     */
    public function create(array $params, Context $context): ViewResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $boardId = (int) $request->query('board_id', 0);

        // 게시판 목록
        $boards = $this->boardService->getBoardsWithGroup($domainId);

        // 선택된 게시판의 카테고리
        $categories = [];
        $selectedBoard = null;
        if ($boardId > 0) {
            $selectedBoard = $this->boardService->getBoard($boardId);
            if ($selectedBoard && $selectedBoard->isUseCategory()) {
                $categories = $this->categoryService->getCategoriesByBoard($boardId);
            }
        }

        return ViewResponse::absoluteView(dirname(__DIR__, 2) . '/views/Admin/Article/Form')
            ->withData([
                'pageTitle' => '게시글 작성',
                'isEdit' => false,
                'article' => null,
                'boards' => $boards,
                'selectedBoardId' => $boardId,
                'selectedBoard' => $selectedBoard,
                'categories' => $categories,
                'statusOptions' => [
                    'published' => '발행',
                    'draft' => '임시저장',
                ],
            ]);
    }

    /**
     * 게시글 수정 폼
     *
     * GET /admin/board/article/edit?id=123
     */
    public function edit(array $params, Context $context): ViewResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $articleId = (int) $request->query('id', 0);

        if ($articleId === 0 && isset($params[0])) {
            $articleId = (int) $params[0];
        }

        // 게시글 조회
        $result = $this->articleService->getArticle($articleId, $context, false);

        if ($result->isFailure()) {
            return ViewResponse::view('Error/404')
                ->withData(['message' => $result->getMessage() ?: '게시글을 찾을 수 없습니다.']);
        }

        $data = $result->getData();
        $article = $data['article'] ?? null;

        if (!$article) {
            return ViewResponse::view('Error/404')
                ->withData(['message' => '게시글을 찾을 수 없습니다.']);
        }

        $boardId = $article['board_id'];

        // 게시판 목록
        $boards = $this->boardService->getBoardsWithGroup($domainId);

        // 선택된 게시판의 카테고리
        $categories = [];
        $selectedBoard = $this->boardService->getBoard($boardId);
        if ($selectedBoard && $selectedBoard->isUseCategory()) {
            $categories = $this->categoryService->getCategoriesByBoard($boardId);
        }

        // 첨부파일 / 링크 목록
        $attachments = $this->fileService->getAttachmentsByArticle($articleId);
        $links = $this->fileService->getLinksByArticle($articleId);

        return ViewResponse::absoluteView(dirname(__DIR__, 2) . '/views/Admin/Article/Form')
            ->withData([
                'pageTitle' => '게시글 수정',
                'isEdit' => true,
                'article' => $article,
                'boards' => $boards,
                'selectedBoardId' => $boardId,
                'selectedBoard' => $selectedBoard,
                'categories' => $categories,
                'attachments' => $attachments,
                'links' => $links,
                'statusOptions' => [
                    'published' => '발행',
                    'draft' => '임시저장',
                ],
            ]);
    }

    /**
     * 게시글 저장 (생성/수정)
     *
     * POST /admin/board/article/store
     */
    public function store(array $params, Context $context): JsonResponse
    {
        try {
            $domainId = $context->getDomainId() ?? 1;
            $request = $context->getRequest();

            $formData = $request->input('formData') ?? [];
            if (!is_array($formData)) {
                return JsonResponse::error('잘못된 폼 데이터입니다.');
            }

            // 본문(content)은 게시판 전용 정화기로 처리 — Core 공용 정화기를 우회해 동영상 임베드 허용
            $rawContent = is_string($formData['content'] ?? null) ? $formData['content'] : '';
            unset($formData['content']);

            // 링크는 스키마 정규화 대상이 아니므로 별도 추출(저장 직후 일괄 처리)
            $rawLinks = is_array($formData['links'] ?? null) ? $formData['links'] : [];
            unset($formData['links']);

            $data = FormHelper::normalizeFormData($formData, $this->getFormSchema());
            $data['content'] = trim($rawContent) === '' ? '' : BoardContentSanitizer::sanitize($rawContent);

            $articleId = (int) ($data['article_id'] ?? 0);
            $boardId = (int) ($data['board_id'] ?? 0);

            if ($boardId <= 0) {
                return JsonResponse::error('게시판을 선택해주세요.');
            }

            if (empty($data['title'])) {
                return JsonResponse::error('제목을 입력해주세요.');
            }

            // 수정 모드
            if ($articleId > 0) {
                $result = $this->articleService->update($articleId, $data, $context);

                if ($result->isSuccess()) {
                    $this->processAttachmentsAndLinks($articleId, $boardId, $request, $rawLinks, $context);
                    return JsonResponse::success(
                        ['redirect' => '/admin/board/article/view?id=' . $articleId],
                        $result->getMessage()
                    );
                }

                return JsonResponse::error($result->getMessage());
            }

            // 생성 모드
            $result = $this->articleService->create($domainId, $boardId, $data, $context);

            if ($result->isSuccess()) {
                $newArticleId = (int) $result->get('article_id');
                $this->processAttachmentsAndLinks($newArticleId, $boardId, $request, $rawLinks, $context);
                return JsonResponse::success(
                    ['redirect' => '/admin/board/article/view?id=' . $newArticleId],
                    $result->getMessage()
                );
            }

            return JsonResponse::error($result->getMessage());
        } catch (\Throwable $e) {
            if (defined('APP_DEBUG') && APP_DEBUG) {
                $message = $e->getMessage();
                if ($e->getPrevious()) {
                    $message .= ' | Cause: ' . $e->getPrevious()->getMessage();
                }
                return JsonResponse::error($message . ' in ' . $e->getFile() . ':' . $e->getLine());
            }
            return JsonResponse::error('게시글 저장 중 오류가 발생했습니다.');
        }
    }

    /**
     * 게시판별 카테고리 조회 (AJAX)
     *
     * GET /admin/board/article/categories?board_id=1
     */
    public function categories(array $params, Context $context): JsonResponse
    {
        $request = $context->getRequest();
        $boardId = (int) $request->query('board_id', 0);

        if ($boardId <= 0) {
            return JsonResponse::success(['categories' => [], 'board' => null]);
        }

        $board = $this->boardService->getBoard($boardId);
        if (!$board) {
            return JsonResponse::success(['categories' => [], 'board' => null]);
        }

        $categories = $board->isUseCategory()
            ? $this->categoryService->getCategoriesByBoard($boardId)
            : [];

        return JsonResponse::success([
            'categories' => $categories,
            'board' => [
                'use_file'               => $board->isUseFile(),
                'use_link'               => $board->isUseLink(),
                'file_count_limit'       => $board->getFileCountLimit(),
                'file_extension_allowed' => $board->getFileExtensionAllowed(),
            ],
        ]);
    }

    /**
     * 폼 데이터 스키마 정의
     */
    private function getFormSchema(): array
    {
        return [
            'numeric' => ['article_id', 'board_id', 'category_id', 'read_level', 'download_level'],
            'bool' => ['is_notice', 'is_secret'],
            // content는 BoardContentSanitizer로 별도 처리(FormHelper 우회)하므로 html 목록에서 제외
            'html' => [],
            'enum' => [
                'status' => ['values' => ['published', 'draft'], 'default' => 'published'],
            ],
        ];
    }

    /**
     * 상태 변경
     *
     * POST /admin/board/article/status-update
     */
    public function statusUpdate(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $articleId = (int) $request->json('article_id', 0);
        $status = $request->json('status', '');

        if ($articleId <= 0) {
            return JsonResponse::error('게시글 ID가 필요합니다.');
        }

        $validStatuses = ['published', 'draft', 'deleted'];
        if (!in_array($status, $validStatuses, true)) {
            return JsonResponse::error('유효하지 않은 상태입니다.');
        }

        $result = $this->articleService->bulkUpdateStatus($domainId, [$articleId], $status);

        if ($result->isSuccess()) {
            return JsonResponse::success(null, '상태가 변경되었습니다.');
        }

        return JsonResponse::error($result->getMessage());
    }

    /**
     * 게시글 삭제
     *
     * POST /admin/board/article/delete
     */
    public function delete(array $params, Context $context): JsonResponse
    {
        $request = $context->getRequest();
        $articleId = (int) $request->json('article_id', 0);

        if ($articleId <= 0) {
            return JsonResponse::error('게시글 ID가 필요합니다.');
        }

        $result = $this->articleService->delete($articleId, $context);

        if ($result->isSuccess()) {
            return JsonResponse::success(null, $result->getMessage());
        }

        return JsonResponse::error($result->getMessage());
    }

    /**
     * 일괄 삭제
     *
     * POST /admin/board/article/bulk-delete
     */
    public function bulkDelete(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $articleIds = $request->json('article_ids', []);

        if (empty($articleIds) || !is_array($articleIds)) {
            return JsonResponse::error('삭제할 게시글을 선택해주세요.');
        }

        // ID 정수 변환
        $articleIds = array_map('intval', $articleIds);

        $result = $this->articleService->bulkUpdateStatus($domainId, $articleIds, 'deleted');

        if ($result->isSuccess()) {
            return JsonResponse::success(
                ['affected' => $result->get('affected')],
                $result->getMessage()
            );
        }

        return JsonResponse::error($result->getMessage());
    }

    /**
     * 일괄 상태 변경
     *
     * POST /admin/board/article/bulk-status-update
     */
    public function bulkStatusUpdate(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $articleIds = $request->json('article_ids', []);
        $status = $request->json('status', '');

        if (empty($articleIds) || !is_array($articleIds)) {
            return JsonResponse::error('게시글을 선택해주세요.');
        }

        $validStatuses = ['published', 'draft', 'deleted'];
        if (!in_array($status, $validStatuses, true)) {
            return JsonResponse::error('유효하지 않은 상태입니다.');
        }

        // ID 정수 변환
        $articleIds = array_map('intval', $articleIds);

        $result = $this->articleService->bulkUpdateStatus($domainId, $articleIds, $status);

        if ($result->isSuccess()) {
            return JsonResponse::success(
                ['affected' => $result->get('affected')],
                $result->getMessage()
            );
        }

        return JsonResponse::error($result->getMessage());
    }

    /**
     * 댓글 삭제
     *
     * POST /admin/board/article/comment-delete
     */
    public function commentDelete(array $params, Context $context): JsonResponse
    {
        $request = $context->getRequest();
        $commentId = (int) $request->json('comment_id', 0);

        if ($commentId <= 0) {
            return JsonResponse::error('댓글 ID가 필요합니다.');
        }

        $result = $this->commentService->delete($commentId, $context);

        if ($result->isSuccess()) {
            return JsonResponse::success(null, $result->getMessage());
        }

        return JsonResponse::error($result->getMessage());
    }

    /**
     * 첨부파일 삭제
     *
     * POST /admin/board/article/attachment-delete
     */
    public function attachmentDelete(array $params, Context $context): JsonResponse
    {
        $request = $context->getRequest();
        $attachmentId = (int) $request->json('attachment_id', 0);

        if ($attachmentId <= 0) {
            return JsonResponse::error('첨부파일 ID가 필요합니다.');
        }

        $result = $this->fileService->deleteFile($attachmentId, $context);

        if ($result->isSuccess()) {
            return JsonResponse::success(null, $result->getMessage());
        }

        return JsonResponse::error($result->getMessage());
    }

    /**
     * 링크 삭제
     *
     * POST /admin/board/article/link-delete
     */
    public function linkDelete(array $params, Context $context): JsonResponse
    {
        $request = $context->getRequest();
        $linkId = (int) $request->json('link_id', 0);

        if ($linkId <= 0) {
            return JsonResponse::error('링크 ID가 필요합니다.');
        }

        $result = $this->fileService->deleteLink($linkId, $context);

        if ($result->isSuccess()) {
            return JsonResponse::success(null, $result->getMessage());
        }

        return JsonResponse::error($result->getMessage());
    }

    /**
     * 저장 직후 첨부파일 업로드 / 링크 추가 일괄 처리
     *
     * - 새 파일: multipart `files[]` (게시판 use_file 시)
     * - 새 링크: `formData[links][n][url|title]` (게시판 use_link 시)
     * 기존 첨부/링크 삭제는 attachmentDelete / linkDelete(AJAX)로 즉시 처리됨.
     */
    private function processAttachmentsAndLinks(
        int $articleId,
        int $boardId,
        $request,
        array $rawLinks,
        Context $context
    ): void {
        if ($articleId <= 0) {
            return;
        }

        $board = $this->boardService->getBoard($boardId);
        if ($board === null) {
            return;
        }

        // 새 파일 업로드
        if ($board->isUseFile() && $request->hasFile('files')) {
            $rawFiles = $request->getRawFile('files');
            if (is_array($rawFiles['name'] ?? null)) {
                $fileCount = count($rawFiles['name']);
                for ($i = 0; $i < $fileCount; $i++) {
                    if (($rawFiles['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                        $this->fileService->uploadFile($articleId, [
                            'name'     => $rawFiles['name'][$i],
                            'type'     => $rawFiles['type'][$i],
                            'tmp_name' => $rawFiles['tmp_name'][$i],
                            'error'    => $rawFiles['error'][$i],
                            'size'     => $rawFiles['size'][$i],
                        ], $context);
                    }
                }
            }
        }

        // 새 링크 추가
        if ($board->isUseLink() && !empty($rawLinks)) {
            foreach ($rawLinks as $link) {
                if (!is_array($link)) {
                    continue;
                }
                $url = is_string($link['url'] ?? null) ? trim($link['url']) : '';
                if ($url !== '') {
                    $this->fileService->addLink($articleId, [
                        'link_url'   => $url,
                        'link_title' => is_string($link['title'] ?? null) ? trim($link['title']) : '',
                    ], $context);
                }
            }
        }
    }
}
