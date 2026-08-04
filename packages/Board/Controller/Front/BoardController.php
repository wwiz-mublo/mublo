<?php
declare(strict_types=1);
namespace Mublo\Packages\Board\Controller\Front;

use Mublo\Core\Response\ViewResponse;
use Mublo\Core\Response\RedirectResponse;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Context\Context;
use Mublo\Packages\Board\Service\BoardArticleService;
use Mublo\Packages\Board\Service\BoardCategoryService;
use Mublo\Packages\Board\Service\BoardCommentService;
use Mublo\Packages\Board\Service\BoardConfigService;
use Mublo\Packages\Board\Service\BoardFileService;
use Mublo\Packages\Board\Service\BoardPermissionService;
use Mublo\Packages\Board\Service\BoardReactionService;
use Mublo\Contract\Auth\AuthContextInterface;
use Mublo\Core\Session\SessionInterface;
use Mublo\Helper\Form\FormHelper;
use Mublo\Packages\Board\Helper\ArticlePresenter;
use Mublo\Packages\Board\Helper\BoardContentSanitizer;
use Mublo\Packages\Board\Helper\ArticleSeoHelper;
use Mublo\Packages\Board\Event\ArticleActionsCollectEvent;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Core\Response\FileResponse;
use Mublo\Infrastructure\Storage\UploadedFile;
use Mublo\Infrastructure\Security\RateLimiter;

/**
 * Front 게시판 컨트롤러
 *
 * /board/{board_id} 라우트 처리
 */
class BoardController
{
    /**
     * 조회수 중복 방지 쿠키(article_viewed)에 담는 최대 글 수.
     *
     * 이 쿠키는 path=/ 라 사이트 전체 요청에 실려 나간다. 상한이 없으면 하루 동안
     * 본 글 수만큼 계속 자라고, 브라우저 한도(보통 4KB)를 넘는 순간 Set-Cookie 가
     * 조용히 무시돼 중복 방지가 통째로 풀린다.
     *
     * 200 개면 6자리 ID 기준 약 1.4KB 로 한도에 여유가 크다. 하루에 이보다 많이 본
     * 사용자는 가장 오래된 글의 조회수가 한 번 더 오를 수 있는데, 조회수 중복 방지는
     * 원래 최선 노력이라 이 쪽을 택한다.
     */
    private const VIEWED_COOKIE_MAX = 200;

    private BoardArticleService $articleService;
    private BoardCategoryService $categoryService;
    private BoardCommentService $commentService;
    private BoardConfigService $boardConfigService;
    private BoardFileService $fileService;
    private BoardPermissionService $permissionService;
    private BoardReactionService $reactionService;
    private AuthContextInterface $authService;
    private SessionInterface $session;
    private EventDispatcher $eventDispatcher;
    private ?RateLimiter $rateLimiter;

    public function __construct(
        BoardArticleService $articleService,
        BoardCategoryService $categoryService,
        BoardCommentService $commentService,
        BoardConfigService $boardConfigService,
        BoardFileService $fileService,
        BoardPermissionService $permissionService,
        BoardReactionService $reactionService,
        AuthContextInterface $authService,
        SessionInterface $session,
        EventDispatcher $eventDispatcher,
        ?RateLimiter $rateLimiter = null
    ) {
        $this->articleService = $articleService;
        $this->categoryService = $categoryService;
        $this->commentService = $commentService;
        $this->boardConfigService = $boardConfigService;
        $this->fileService = $fileService;
        $this->permissionService = $permissionService;
        $this->reactionService = $reactionService;
        $this->authService = $authService;
        $this->session = $session;
        $this->eventDispatcher = $eventDispatcher;
        $this->rateLimiter = $rateLimiter;
    }

    /**
     * 게시판 목록
     */
    public function list(array $params, Context $context): ViewResponse|RedirectResponse
    {
        $slug = $params['board_id'] ?? '';
        $domainId = $context->getDomainId() ?? 1;

        // 게시판 조회 (slug → BoardConfig)
        $board = $this->boardConfigService->getBoardBySlug($domainId, $slug);

        if (!$board || !$board->isActive()) {
            return ViewResponse::view('error/notfound')
                ->withStatusCode(404)
                ->withData(['message' => '게시판을 찾을 수 없습니다.']);
        }

        $boardId = $board->getBoardId();

        // 카테고리
        $categories = [];
        if ($board->useCategory()) {
            $categories = $this->categoryService->getCategoriesByBoard($boardId);
        }

        // 요청 파라미터
        $request = $context->getRequest();
        $page = max(1, (int) ($request->get('page') ?? 1));
        $keyword = trim($request->get('keyword') ?? '');
        $searchField = $request->get('search_field') ?? 'title';
        $categoryId = $board->useCategory() ? (int) ($request->get('category_id') ?? 0) : 0;

        // 검색 필터
        $filters = [
            'per_page' => $board->getPerPage() ?: 20,
        ];
        if ($keyword !== '') {
            $filters['keyword'] = $keyword;
            $filters['search_field'] = $searchField;
        }
        if ($categoryId > 0) {
            $filters['category_id'] = $categoryId;
        }

        // 공지글 분리 (1페이지에서만 상단 표시, 검색 시 미표시)
        $noticeCount = $board->getNoticeCount();
        $notices = [];
        if ($noticeCount > 0 && $page === 1 && $keyword === '') {
            $notices = $this->articleService->getNotices($domainId, $boardId, $noticeCount);
        }

        // 본문 목록 조회 (공지 제외)
        $filters['is_notice'] = 0;
        $result = $this->articleService->getList($domainId, $boardId, $page, $filters, $context);

        if ($result->isFailure()) {
            if ($this->authService->guest()) {
                return RedirectResponse::to('/login');
            }
            return ViewResponse::view('error/forbidden')
                ->withStatusCode(403)
                ->withData(['message' => $result->getMessage()]);
        }

        $data = $result->getData();

        // Presenter: 목록용 데이터 변환
        $presenter = new ArticlePresenter($data['board']);
        $items = $presenter->toList($data['items'], $slug);
        $noticeItems = $presenter->toList($notices, $slug);

        $pagination = $data['pagination'];
        $pagination['pageNums'] = 10;

        // 글쓰기 권한
        $canWrite = $this->permissionService->canWrite($board, $context);

        $skin = $board->getBoardSkin();

        return ViewResponse::absoluteView($this->skinView($skin, 'List'))
            ->withData([
                'board'      => $data['board'],
                'notices'    => $noticeItems,
                'items'      => $items,
                'pagination' => $pagination,
                'filters'    => [
                    'keyword'      => $keyword,
                    'search_field' => $searchField,
                    'category_id'  => $categoryId,
                ],
                'categories' => $categories,
                'canWrite'   => $canWrite,
            ]);
    }

    /**
     * 게시글 상세
     */
    public function view(array $params, Context $context): ViewResponse|RedirectResponse
    {
        $boardSlug = $params['board_id'] ?? '';
        $articleId = (int) ($params['post_no'] ?? 0);
        $domainId = $context->getDomainId() ?? 1;

        if ($articleId <= 0) {
            return ViewResponse::view('error/notfound')
                ->withStatusCode(404)
                ->withData(['message' => '게시글을 찾을 수 없습니다.']);
        }

        // 조회수 중복 방지 (쿠키 기반, 1일 1회)
        $request = $context->getRequest();
        $viewedIds = $this->parseViewedIds($request->cookie('article_viewed', ''));
        $incrementView = !in_array((string) $articleId, $viewedIds, true);
        $guestAuthorized = $this->canManageGuestArticle($articleId);

        // 게시글 조회 (권한 체크 + 조회수 증가)
        $result = $this->articleService->getArticle($articleId, $context, $incrementView);

        // 조회수 증가했으면 쿠키에 기록 (당일 자정까지)
        if ($incrementView && $result->isSuccess()) {
            $viewedIds = $this->appendViewedId($viewedIds, $articleId);
            setcookie('article_viewed', implode('.', $viewedIds), [
                'expires'  => strtotime('tomorrow'),
                'path'     => '/',
                'secure'   => $request->isHttps(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }

        if ($result->isFailure()) {
            if ($this->authService->guest()) {
                $session = $this->session;
                $guestArticles = $session->get('guest_articles', []);

                if (in_array($articleId, $guestArticles)) {
                    // 세션에 허용된 글 → 권한 체크 우회 재조회
                    $result = $this->articleService->getArticleWithoutPermission(
                        $articleId,
                        $context,
                        $guestAuthorized
                    );
                    if ($result->isFailure()) {
                        return ViewResponse::view('error/notfound')
                            ->withStatusCode(404)
                            ->withData(['message' => '게시글을 찾을 수 없습니다.']);
                    }
                } else {
                    // 비회원 글이면 비밀번호 입력 폼, 아니면 로그인
                    $article = $this->articleService->findById($articleId);
                    if ($article && $article->getAuthorPassword()) {
                        $board = $this->boardConfigService->getBoardBySlug($domainId, $boardSlug);
                        $skin = $board ? $board->getBoardSkin() : 'basic';
                        return ViewResponse::absoluteView($this->skinView($skin, 'Password'))
                            ->withData([
                                'boardSlug' => $boardSlug,
                                'articleId' => $articleId,
                                'board' => $board ? $board->toArray() : [],
                            ]);
                    }
                    return RedirectResponse::to('/login');
                }
            } else {
                return ViewResponse::view('error/forbidden')
                    ->withStatusCode(403)
                    ->withData(['message' => $result->getMessage()]);
            }
        }

        $data = $result->getData();
        if ($guestAuthorized && empty($data['article']['member_id'])) {
            $data['can_modify'] = true;
            $data['can_delete'] = true;
        }

        // board_slug 검증 (URL 조작 방지)
        $board = $data['board'];
        if ($board['board_slug'] !== $boardSlug) {
            return ViewResponse::view('error/notfound')
                ->withStatusCode(404)
                ->withData(['message' => '게시글을 찾을 수 없습니다.']);
        }

        $isGuestArticle = empty($data['article']['member_id'])
            && !empty($data['article']['author_password']);
        if ($isGuestArticle && !$guestAuthorized && $request->query('manage') === '1') {
            $skin = $data['board_entity']->getBoardSkin();
            return ViewResponse::absoluteView($this->skinView($skin, 'Password'))
                ->withData([
                    'boardSlug' => $boardSlug,
                    'articleId' => $articleId,
                    'board' => $board,
                ]);
        }

        // Presenter: 상세용 데이터 변환
        $presenter = new ArticlePresenter($board);
        $article = $presenter->toView($data['article'], $boardSlug);
        $prev = $presenter->toAdjacent($data['prev'], $boardSlug);
        $next = $presenter->toAdjacent($data['next'], $boardSlug);

        // 첨부파일 + 링크
        $attachments = [];
        $links = [];
        if (!empty($board['use_file'])) {
            $attachments = $presenter->decorateAttachments(
                $this->fileService->getAttachmentsByArticle($articleId)
            );
        }
        if (!empty($board['use_link'])) {
            $links = $this->fileService->getLinksByArticle($articleId);
        }

        // 댓글 목록
        $comments = [];
        if ($board['use_comment']) {
            // 비밀댓글 열람 권한: 댓글 본인 / 글작성자·관리자(can_delete)만 본문을 본다.
            // 없으면 getCommentsByArticle이 is_secret 무시하고 content를 반환해 🔒가 장식일 뿐,
            // 익명 방문자에게도 비밀댓글 본문이 그대로 노출된다(기밀성 0).
            $viewerId = (int) ($this->authService->id() ?? 0);
            $canSeeAllSecret = (bool) ($data['can_delete'] ?? false);

            $commentEntities = $this->commentService->getCommentsByArticle($articleId);
            foreach ($commentEntities as $comment) {
                $c = array_intersect_key($comment->toArray(), array_flip([
                    'comment_id', 'article_id', 'parent_id', 'member_id', 'author_name',
                    'content', 'is_secret', 'status', 'reaction_count', 'depth',
                    'created_at', 'updated_at',
                ]));

                if (!empty($c['is_secret'])) {
                    $isOwnComment = $viewerId > 0 && (int) ($c['member_id'] ?? 0) === $viewerId;
                    if (!$isOwnComment && !$canSeeAllSecret) {
                        $c['content'] = '🔒 비밀 댓글입니다.';
                    }
                }

                $comments[] = $c;
            }
        }

        $currentUser = $this->authService->currentUser();

        // 글쓰기 권한 (Service가 반환한 Entity 사용, DB 재조회 불필요)
        $boardEntity = $data['board_entity'];
        $canWrite = $this->permissionService->canWrite($boardEntity, $context);

        // 반응 정보 (반응 기능 사용 시)
        $reactionInfo = null;
        $enabledReactions = [];
        if (!empty($board['use_reaction'])) {
            $reactionInfo = $this->reactionService->getReactionInfo('article', $articleId, $context);
            $enabledReactions = $boardEntity->getEnabledReactions();

            if (empty($enabledReactions)) {
                $enabledReactions = [
                    'like' => ['label' => '좋아요', 'icon' => '👍', 'color' => '#3B82F6', 'enabled' => true],
                ];
            }
        }

        $skin = $boardEntity->getBoardSkin();

        $seo = $this->buildArticleSeo($data['article'], $board, $boardSlug, $context);

        // 플러그인 액션 수집 (신고 등) — 스킨은 배열을 그리기만 한다
        $actionsEvent = $this->eventDispatcher->dispatch(new ArticleActionsCollectEvent(
            $data['article_entity'] ?? $this->articleService->findById($articleId),
            $currentUser?->memberId
        ));

        return ViewResponse::absoluteView($this->skinView($skin, 'View'))
            ->withData([
                'extraActions'     => $actionsEvent->getActions(),
                'article'          => $article,
                'board'            => $board,
                'prev'             => $prev,
                'next'             => $next,
                'canWrite'         => $canWrite,
                'canModify'        => $data['can_modify'],
                'canDelete'        => $data['can_delete'],
                'canComment'       => $data['can_comment'],
                'canReact'         => $data['can_react'],
                'canDownload'      => $data['can_download'],
                'isGuestArticle'   => $isGuestArticle,
                'attachments'      => $attachments,
                'links'            => $links,
                'comments'         => $comments,
                'reactionInfo'     => $reactionInfo,
                'enabledReactions' => $enabledReactions,
                // SEO
                'seoTitle'         => $seo['title'],
                'seoDescription'   => $seo['description'],
                'seoKeywords'      => $seo['keywords'],
                'pageOgImage'      => $seo['og_image'],
                'pageOgType'       => 'article',
                'articleMeta'      => $seo['article_meta'],
                'pageJsonLd'       => $seo['json_ld'],
                'breadcrumb'       => $seo['breadcrumb'],
            ]);
    }

    /**
     * 게시글 상세용 SEO 메타 데이터 빌드
     */
    private function buildArticleSeo(array $article, array $board, string $boardSlug, Context $context): array
    {
        $request = $context->getRequest();
        $origin = $request->getSchemeAndHost();

        $articleTitle = trim((string) ($article['title'] ?? ''));
        $boardName = trim((string) ($board['board_name'] ?? ''));
        $boardDescription = trim((string) ($board['board_description'] ?? ''));
        $siteName = trim((string) ($context->getDomainInfo()?->getSiteTitle() ?? ''));
        $content = (string) ($article['content'] ?? '');

        $videos = ArticleSeoHelper::extractVideoEmbeds($content);

        $title = ArticleSeoHelper::buildTitle($articleTitle, $boardName, $siteName);

        // description: 본문 텍스트가 빈약하면 제목/영상 안내/게시판 설명으로 보강
        $descriptionFallback = [];
        if ($articleTitle !== '') {
            $descriptionFallback[] = $articleTitle;
        }
        if (!empty($videos)) {
            $descriptionFallback[] = '영상으로 안내합니다.';
        }
        if ($boardDescription !== '') {
            $descriptionFallback[] = $boardDescription;
        } elseif ($boardName !== '') {
            $descriptionFallback[] = $boardName;
        }
        $description = ArticleSeoHelper::buildDescription($content, 160, $descriptionFallback);

        // og:image: thumbnail → 본문 첫 이미지 → 본문 첫 영상 썸네일
        $rawImage = ArticleSeoHelper::pickOgImage(
            $article['thumbnail'] ?? null,
            $content,
            $videos
        );
        $ogImage = ArticleSeoHelper::toAbsoluteUrl($rawImage, $origin);

        $articleId = (int) ($article['article_id'] ?? 0);
        $slug = $article['slug'] ?? '';
        $articleUrl = $origin . '/board/' . $boardSlug . '/view/' . $articleId
            . ($slug !== '' ? '/' . rawurlencode($slug) : '');

        $publishedAt = $article['published_at'] ?? $article['created_at'] ?? null;
        $updatedAt = $article['updated_at'] ?? null;
        $authorName = trim((string) ($article['author_name'] ?? ''));

        $commentCount = (int) ($article['comment_count'] ?? 0);
        $interactions = [
            'view' => (int) ($article['view_count'] ?? 0),
            'comment' => $commentCount,
            'reaction' => (int) ($article['reaction_count'] ?? 0),
        ];

        $blogPosting = ArticleSeoHelper::buildArticleJsonLd([
            'title' => $articleTitle,
            'description' => $description,
            'url' => $articleUrl,
            'image' => $ogImage,
            'author' => $authorName !== '' ? $authorName : null,
            'publisher' => $siteName !== '' ? $siteName : null,
            'published_at' => $publishedAt,
            'updated_at' => $updatedAt,
            'comment_count' => $commentCount,
            'interactions' => $interactions,
        ]);

        // breadcrumb: 홈 > {게시판명} > {글제목}
        $breadcrumb = [
            ['name' => '홈', 'url' => $origin . '/'],
            ['name' => $boardName, 'url' => $origin . '/board/' . $boardSlug],
            ['name' => $articleTitle, 'url' => $articleUrl],
        ];
        $breadcrumbJsonLd = ArticleSeoHelper::buildBreadcrumbJsonLd($breadcrumb);

        $jsonLd = [$blogPosting, $breadcrumbJsonLd];

        // 본문 영상 각각에 대해 VideoObject 추가
        foreach ($videos as $video) {
            $videoJsonLd = ArticleSeoHelper::buildVideoJsonLd($video, [
                'name' => $articleTitle,
                'description' => $description,
                'upload_date' => $publishedAt,
                // Vimeo 등 자체 썸네일이 없을 때 글 대표 이미지로 대체
                'thumbnail_fallback' => $ogImage,
                'publisher' => $siteName !== '' ? $siteName : null,
            ]);
            // VideoObject 는 thumbnailUrl·uploadDate 가 있어야 유효(없으면 리치결과 무효 경고)
            // — 필수 필드를 못 채우면 차라리 발행하지 않음
            if (isset($videoJsonLd['thumbnailUrl'], $videoJsonLd['uploadDate'])) {
                $jsonLd[] = $videoJsonLd;
            }
        }

        // keywords 자동 조합 (제목 + 게시판 + 사이트)
        $keywords = ArticleSeoHelper::buildKeywords([
            $articleTitle,
            $boardName,
            $siteName,
        ]);

        return [
            'title' => $title,
            'description' => $description,
            'keywords' => $keywords,
            'og_image' => $ogImage,
            'article_meta' => [
                'published_time' => $publishedAt,
                'modified_time' => $updatedAt,
                'author' => $authorName !== '' ? $authorName : null,
                'section' => $boardName,
            ],
            'json_ld' => $jsonLd,
            'breadcrumb' => $breadcrumb,
        ];
    }

    // ========================================
    // 댓글 CRUD (AJAX / JSON 응답)
    // ========================================

    /**
     * 댓글 작성
     */
    public function commentCreate(array $params, Context $context): JsonResponse
    {
        $request = $context->getRequest();
        $json = $request->getJsonInput();
        if (!is_array($json)) {
            return JsonResponse::error('잘못된 요청입니다.');
        }
        $articleId = (int) ($json['article_id'] ?? 0);

        if ($articleId <= 0) {
            return JsonResponse::error('잘못된 요청입니다.');
        }

        $content = $json['content'] ?? null;
        $data = [
            'content'         => is_string($content) ? $content : '',
            'parent_id'       => $json['parent_id'] ?? null,
            'is_secret'       => (bool) ($json['is_secret'] ?? false),
            'author_name'     => is_string($json['author_name'] ?? null) ? $json['author_name'] : '',
            'author_password' => is_string($json['author_password'] ?? null) ? $json['author_password'] : '',
        ];

        if (trim($data['content']) === '') {
            return JsonResponse::error('댓글 내용을 입력해주세요.');
        }

        $result = $this->commentService->create($articleId, $data, $context);

        if ($result->isFailure()) {
            return JsonResponse::error($result->getMessage());
        }

        return JsonResponse::success($result->getData(), $result->getMessage());
    }

    /**
     * 댓글 수정
     */
    public function commentUpdate(array $params, Context $context): JsonResponse
    {
        $commentId = (int) ($params['comment_id'] ?? 0);
        $request = $context->getRequest();
        $json = $request->getJsonInput();

        if ($commentId <= 0 || !is_array($json)) {
            return JsonResponse::error('잘못된 요청입니다.');
        }

        $content = $json['content'] ?? null;
        $data = [
            'content' => is_string($content) ? $content : '',
        ];
        if (array_key_exists('is_secret', $json)) {
            $data['is_secret'] = (bool) $json['is_secret'];
        }

        if (trim($data['content']) === '') {
            return JsonResponse::error('댓글 내용을 입력해주세요.');
        }

        $guestPassword = is_string($json['author_password'] ?? null) && $json['author_password'] !== ''
            ? $json['author_password']
            : null;
        if ($guestPassword !== null && $this->guestCommentPasswordRateLimited($commentId, $context)) {
            return JsonResponse::error('시도가 너무 많습니다. 잠시 후 다시 시도해주세요.');
        }
        $result = $this->commentService->update($commentId, $data, $context, $guestPassword);

        if ($result->isFailure()) {
            return JsonResponse::error($result->getMessage());
        }

        return JsonResponse::success(null, $result->getMessage());
    }

    /**
     * 댓글 삭제
     */
    public function commentDelete(array $params, Context $context): JsonResponse
    {
        $commentId = (int) ($params['comment_id'] ?? 0);
        $request = $context->getRequest();

        if ($commentId <= 0) {
            return JsonResponse::error('잘못된 요청입니다.');
        }

        $guestPassword = $request->json('author_password') ?: null;
        if ($guestPassword !== null && $this->guestCommentPasswordRateLimited($commentId, $context)) {
            return JsonResponse::error('시도가 너무 많습니다. 잠시 후 다시 시도해주세요.');
        }
        $result = $this->commentService->delete($commentId, $context, $guestPassword);

        if ($result->isFailure()) {
            return JsonResponse::error($result->getMessage());
        }

        return JsonResponse::success($result->getData(), $result->getMessage());
    }

    /**
     * 비회원 댓글 비밀번호 온라인 브루트포스 제한 (IP+댓글 단위 고정 윈도우).
     * 글 passwordCheck 와 동일 정책 — update/delete 두 진입점에 대칭 적용한다.
     */
    private function guestCommentPasswordRateLimited(int $commentId, Context $context): bool
    {
        if ($this->rateLimiter === null) {
            return false;
        }
        $ip = $context->getRequest()->getClientIp();
        return !$this->rateLimiter->attempt("board-guest-comment-pw:{$ip}:{$commentId}", 10, 300);
    }

    // ========================================
    // 반응 토글 (AJAX / JSON 응답)
    // ========================================

    /**
     * 반응 토글
     */
    public function reactionToggle(array $params, Context $context): JsonResponse
    {
        $request = $context->getRequest();
        $articleId = (int) ($request->json('article_id') ?? 0);
        $reactionType = trim($request->json('reaction_type') ?? '');

        if ($articleId <= 0) {
            return JsonResponse::error('잘못된 요청입니다.');
        }

        if ($reactionType === '') {
            return JsonResponse::error('반응 타입을 선택해주세요.');
        }

        $result = $this->reactionService->toggle('article', $articleId, $reactionType, $context);

        if (!$result['success']) {
            return JsonResponse::error($result['message']);
        }

        return JsonResponse::success([
            'action'      => $result['action'],
            'counts'      => $result['counts'],
            'my_reaction' => $result['my_reaction'],
        ], $result['message']);
    }

    // ========================================
    // 글쓰기 / 수정
    // ========================================

    /**
     * 글쓰기 폼
     */
    public function write(array $params, Context $context): ViewResponse|RedirectResponse
    {
        $slug = $params['board_id'] ?? '';
        $domainId = $context->getDomainId() ?? 1;

        $board = $this->boardConfigService->getBoardBySlug($domainId, $slug);
        if (!$board || !$board->isActive()) {
            return ViewResponse::view('error/notfound')
                ->withStatusCode(404)
                ->withData(['message' => '게시판을 찾을 수 없습니다.']);
        }

        if (!$this->permissionService->canWrite($board, $context)) {
            if ($this->authService->guest()) {
                return RedirectResponse::to('/login');
            }
            return ViewResponse::view('error/forbidden')
                ->withStatusCode(403)
                ->withData(['message' => '글쓰기 권한이 없습니다.']);
        }

        $isLoggedIn = $this->authService->check();
        $skin = $board->getBoardSkin();

        $categories = [];
        if ($board->useCategory()) {
            $categories = $this->categoryService->getCategoriesByBoard($board->getBoardId());
        }

        return ViewResponse::absoluteView($this->skinView($skin, 'Write'))
            ->withData([
                'board'      => $board->toArray(),
                'article'    => null,
                'isEdit'     => false,
                'isLoggedIn' => $isLoggedIn,
                'categories' => $categories,
            ]);
    }

    /**
     * 글쓰기 처리
     */
    public function writeProcess(array $params, Context $context): JsonResponse
    {
        $slug = $params['board_id'] ?? '';
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();

        $board = $this->boardConfigService->getBoardBySlug($domainId, $slug);
        if (!$board || !$board->isActive()) {
            return JsonResponse::error('게시판을 찾을 수 없습니다.');
        }

        $formData = $request->input('formData') ?? [];
        if (!is_array($formData)) {
            return JsonResponse::error('잘못된 폼 데이터입니다.');
        }

        // 본문(content)은 게시판 전용 정화기로 처리 — Core 공용 정화기를 우회해 동영상 임베드 허용
        $rawContent = is_string($formData['content'] ?? null) ? $formData['content'] : '';
        unset($formData['content']);

        $data = FormHelper::normalizeFormData($formData, $this->getFormSchema());
        $data['content'] = trim($rawContent) === '' ? '' : BoardContentSanitizer::sanitize($rawContent);

        if (empty($data['title'])) {
            return JsonResponse::error('제목을 입력해주세요.');
        }

        $result = $this->articleService->create($domainId, $board->getBoardId(), $data, $context);

        if ($result->isFailure()) {
            return JsonResponse::error($result->getMessage());
        }

        $articleId = $result->get('article_id');

        // 파일 첨부 처리 (거부/실패 파일은 경고로 수집)
        $fileWarnings = $board->isUseFile()
            ? $this->processUploadedFiles($articleId, $context, false)
            : [];

        // 링크 추가 처리
        if ($board->isUseLink() && !empty($data['links']) && is_array($data['links'])) {
            foreach ($data['links'] as $link) {
                if (!is_array($link)) {
                    continue;
                }
                $linkUrl = is_string($link['url'] ?? null) ? trim($link['url']) : '';
                if ($linkUrl !== '') {
                    $this->fileService->addLink($articleId, [
                        'link_url'   => $linkUrl,
                        'link_title' => is_string($link['title'] ?? null) ? trim($link['title']) : '',
                    ], $context, [$articleId]);
                }
            }
        }

        // 비회원 글 작성 시 세션에 글 ID 저장 (작성 직후 읽기 허용)
        if ($this->authService->guest() && $articleId) {
            $session = $this->session;
            $guestArticles = $session->get('guest_articles', []);
            $guestArticles[] = $articleId;
            $session->set('guest_articles', $guestArticles);
        }

        return JsonResponse::success(
            ['redirect' => '/board/' . $slug . '/view/' . $articleId, 'warnings' => $fileWarnings],
            $result->getMessage()
        );
    }

    /**
     * 업로드된 files[]를 처리하고 거부/실패한 파일의 사유를 경고 목록으로 반환.
     *
     * 글은 이미 저장된 뒤 호출되므로 차단하지 않고, 제외된 파일을 사용자에게 알린다.
     * - PHP 레벨 에러(UPLOAD_ERR_INI_SIZE 등): UploadedFile::getErrorMessage()
     * - 게시판 정책 위반(용량/확장자/개수): BoardFileService::uploadFile() 실패 메시지
     *
     * @return string[] 예: ['big.zip: 파일 첨부 개수를 초과했습니다.']
     */
    private function processUploadedFiles(
        int $articleId,
        Context $context,
        bool $requireModifyPermission = true,
        array $guestArticleIds = []
    ): array
    {
        $request = $context->getRequest();
        if (!$request->hasFile('files')) {
            return [];
        }

        $rawFiles = $request->getRawFile('files');
        if (!is_array($rawFiles['name'] ?? null)) {
            return [];
        }

        $warnings = [];
        $fileCount = count($rawFiles['name']);
        for ($i = 0; $i < $fileCount; $i++) {
            $error = $rawFiles['error'][$i] ?? UPLOAD_ERR_NO_FILE;
            $name = (string) ($rawFiles['name'][$i] ?? '');
            $prefix = $name !== '' ? $name . ': ' : '';

            if ($error === UPLOAD_ERR_NO_FILE) {
                continue; // 빈 슬롯
            }

            if ($error !== UPLOAD_ERR_OK) {
                $warnings[] = $prefix . (new UploadedFile(['name' => $name, 'error' => $error]))->getErrorMessage();
                continue;
            }

            $result = $this->fileService->uploadFile($articleId, [
                'name'     => $name,
                'type'     => $rawFiles['type'][$i] ?? '',
                'tmp_name' => $rawFiles['tmp_name'][$i] ?? '',
                'error'    => $error,
                'size'     => $rawFiles['size'][$i] ?? 0,
            ], $context, [
                'require_modify_permission' => $requireModifyPermission,
                'guest_article_ids' => $guestArticleIds,
            ]);

            if ($result->isFailure()) {
                $warnings[] = $prefix . $result->getMessage();
            }
        }

        return $warnings;
    }

    /**
     * 글수정 폼
     */
    public function edit(array $params, Context $context): ViewResponse|RedirectResponse
    {
        $slug = $params['board_id'] ?? '';
        $articleId = (int) ($params['post_no'] ?? 0);
        $domainId = $context->getDomainId() ?? 1;

        $result = $this->articleService->getArticle($articleId, $context, false);
        if ($result->isFailure()) {
            if ($this->canManageGuestArticle($articleId)) {
                $result = $this->articleService->getArticleWithoutPermission($articleId, $context, true);
            } elseif ($this->authService->guest()) {
                return RedirectResponse::to('/login');
            } else {
                return ViewResponse::view('error/forbidden')
                    ->withStatusCode(403)
                    ->withData(['message' => $result->getMessage()]);
            }
        }

        if ($result->isFailure()) {
            return ViewResponse::view('error/forbidden')
                ->withStatusCode(403)
                ->withData(['message' => $result->getMessage()]);
        }

        $data = $result->getData();
        if ($this->canManageGuestArticle($articleId) && empty($data['article']['member_id'])) {
            $data['can_modify'] = true;
            $data['can_delete'] = true;
        }
        $board = $data['board'];

        if ($board['board_slug'] !== $slug) {
            return ViewResponse::view('error/notfound')
                ->withStatusCode(404)
                ->withData(['message' => '게시글을 찾을 수 없습니다.']);
        }

        if (!$data['can_modify']) {
            return ViewResponse::view('error/forbidden')
                ->withStatusCode(403)
                ->withData(['message' => '수정 권한이 없습니다.']);
        }

        $boardEntity = $data['board_entity'];
        $skin = $boardEntity->getBoardSkin();

        $categories = [];
        if ($boardEntity->useCategory()) {
            $categories = $this->categoryService->getCategoriesByBoard($boardEntity->getBoardId());
        }

        $articleId = (int) ($data['article']['article_id'] ?? 0);
        $attachments = [];
        $links = [];
        if ($boardEntity->isUseFile()) {
            $attachments = $this->fileService->getAttachmentsByArticle($articleId);
        }
        if ($boardEntity->isUseLink()) {
            $links = $this->fileService->getLinksByArticle($articleId);
        }

        return ViewResponse::absoluteView($this->skinView($skin, 'Write'))
            ->withData([
                'board'       => $board,
                'article'     => $data['article'],
                'isEdit'      => true,
                'isLoggedIn'  => $this->authService->check(),
                'categories'  => $categories,
                'attachments' => $attachments,
                'links'       => $links,
            ]);
    }

    /**
     * 글수정 처리
     */
    public function editProcess(array $params, Context $context): JsonResponse
    {
        $slug = $params['board_id'] ?? '';
        $articleId = (int) ($params['post_no'] ?? 0);
        $request = $context->getRequest();

        if ($articleId <= 0) {
            return JsonResponse::error('잘못된 요청입니다.');
        }

        $formData = $request->input('formData') ?? [];
        if (!is_array($formData)) {
            return JsonResponse::error('잘못된 폼 데이터입니다.');
        }

        // 본문(content)은 게시판 전용 정화기로 처리 — Core 공용 정화기를 우회해 동영상 임베드 허용
        $rawContent = is_string($formData['content'] ?? null) ? $formData['content'] : '';
        unset($formData['content']);

        $data = FormHelper::normalizeFormData($formData, $this->getFormSchema());
        $data['content'] = trim($rawContent) === '' ? '' : BoardContentSanitizer::sanitize($rawContent);

        if (empty($data['title'])) {
            return JsonResponse::error('제목을 입력해주세요.');
        }

        $guestArticleIds = $this->getGuestArticleIds();
        $result = $this->articleService->update(
            $articleId,
            $data,
            $context,
            in_array($articleId, $guestArticleIds, true)
        );

        if ($result->isFailure()) {
            return JsonResponse::error($result->getMessage());
        }

        // 새로 추가된 파일 첨부 처리 (기존 파일 삭제는 프론트에서 AJAX로 즉시 처리됨)
        // 거부/실패 파일은 경고로 수집해 응답에 포함
        $fileWarnings = $this->processUploadedFiles($articleId, $context, true, $guestArticleIds);

        return JsonResponse::success(
            ['redirect' => '/board/' . $slug . '/view/' . $articleId, 'warnings' => $fileWarnings],
            $result->getMessage()
        );
    }

    /**
     * 게시글 삭제
     */
    public function delete(array $params, Context $context): JsonResponse
    {
        $slug = $params['board_id'] ?? '';
        $articleId = (int) ($params['post_no'] ?? 0);

        if ($articleId <= 0) {
            return JsonResponse::error('잘못된 요청입니다.');
        }

        $result = $this->articleService->delete(
            $articleId,
            $context,
            $this->canManageGuestArticle($articleId)
        );

        if ($result->isFailure()) {
            return JsonResponse::error($result->getMessage());
        }

        $this->forgetGuestArticle($articleId);

        return JsonResponse::success(
            ['redirect' => '/board/' . $slug],
            $result->getMessage()
        );
    }

    // ========================================
    // 파일 첨부 / 링크 (AJAX / JSON 응답)
    // ========================================

    /**
     * 파일 업로드
     */
    public function fileUpload(array $params, Context $context): JsonResponse
    {
        $request = $context->getRequest();
        $articleId = (int) ($request->input('article_id') ?? 0);

        if ($articleId <= 0) {
            return JsonResponse::error('잘못된 요청입니다.');
        }

        if (!$request->hasFile('file')) {
            return JsonResponse::error('파일을 선택해주세요.');
        }

        $file = $request->getRawFile('file');
        $result = $this->fileService->uploadFile($articleId, $file, $context, [
            'guest_article_ids' => $this->getGuestArticleIds(),
        ]);

        if ($result->isFailure()) {
            return JsonResponse::error($result->getMessage());
        }

        return JsonResponse::success($result->getData(), $result->getMessage());
    }

    /**
     * 파일 다운로드
     */
    public function fileDownload(array $params, Context $context): FileResponse|JsonResponse
    {
        $publicId = (string) ($params['public_id'] ?? '');

        // 라우트 패턴이 이미 걸러내지만, 다른 경로로 호출될 때를 대비해 여기서도 본다
        if (preg_match('/^[0-9a-f]{22}$/', $publicId) !== 1) {
            return JsonResponse::error('잘못된 요청입니다.');
        }

        $result = $this->fileService->download($publicId, $context);

        if ($result->isFailure()) {
            return JsonResponse::error($result->getMessage());
        }

        $fileName = $result->get('original_name');
        $encodedName = rawurlencode($fileName);

        return new FileResponse(
            $result->get('file_path'),
            200,
            [
                'Content-Type' => $result->get('mime_type'),
                'Content-Disposition' => "attachment; filename*=UTF-8''{$encodedName}",
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }

    /**
     * 파일 삭제
     */
    public function fileDelete(array $params, Context $context): JsonResponse
    {
        $request = $context->getRequest();
        $attachmentId = (int) ($request->json('attachment_id') ?? 0);

        if ($attachmentId <= 0) {
            return JsonResponse::error('잘못된 요청입니다.');
        }

        $result = $this->fileService->deleteFile(
            $attachmentId,
            $context,
            $this->getGuestArticleIds()
        );

        if ($result->isFailure()) {
            return JsonResponse::error($result->getMessage());
        }

        return JsonResponse::success(null, $result->getMessage());
    }

    /**
     * 링크 추가
     */
    public function linkAdd(array $params, Context $context): JsonResponse
    {
        $request = $context->getRequest();
        $json = $request->getJsonInput();
        if (!is_array($json)) {
            return JsonResponse::error('잘못된 요청입니다.');
        }
        $articleId = (int) ($json['article_id'] ?? 0);

        if ($articleId <= 0) {
            return JsonResponse::error('잘못된 요청입니다.');
        }

        $linkUrl = $json['link_url'] ?? null;
        $linkTitle = $json['link_title'] ?? null;
        $data = [
            'link_url'   => is_string($linkUrl) ? trim($linkUrl) : '',
            'link_title' => is_string($linkTitle) ? trim($linkTitle) : '',
        ];

        $result = $this->fileService->addLink(
            $articleId,
            $data,
            $context,
            $this->getGuestArticleIds()
        );

        if ($result->isFailure()) {
            return JsonResponse::error($result->getMessage());
        }

        return JsonResponse::success($result->getData(), $result->getMessage());
    }

    /**
     * 링크 삭제
     */
    public function linkDelete(array $params, Context $context): JsonResponse
    {
        $request = $context->getRequest();
        $linkId = (int) ($request->json('link_id') ?? 0);

        if ($linkId <= 0) {
            return JsonResponse::error('잘못된 요청입니다.');
        }

        $result = $this->fileService->deleteLink(
            $linkId,
            $context,
            $this->getGuestArticleIds()
        );

        if ($result->isFailure()) {
            return JsonResponse::error($result->getMessage());
        }

        return JsonResponse::success(null, $result->getMessage());
    }

    /**
     * 폼 스키마
     */
    /**
     * 비회원 비밀번호 확인
     * POST /board/{board_id}/password-check
     */
    public function passwordCheck(array $params, Context $context): JsonResponse
    {
        $articleId = (int) ($context->getRequest()->json('article_id') ?? 0);
        $password = $context->getRequest()->json('password') ?? '';
        $boardSlug = $params['board_id'] ?? '';

        if (!$articleId || $password === '') {
            return JsonResponse::error('비밀번호를 입력해주세요.');
        }

        // 비회원 비밀번호 온라인 브루트포스 제한 (IP+글 단위 고정 윈도우).
        // 4자리 숫자 비번이 흔한 대상층을 고려해 무제한 시도를 차단한다.
        if ($this->rateLimiter !== null) {
            $ip = $context->getRequest()->getClientIp();
            if (!$this->rateLimiter->attempt("board-guest-pw:{$ip}:{$articleId}", 10, 300)) {
                return JsonResponse::error('시도가 너무 많습니다. 잠시 후 다시 시도해주세요.');
            }
        }

        if ($this->articleService->verifyGuestPassword($articleId, $password, $context)) {
            // 세션에 글 ID 저장 (이후 읽기 허용)
            $session = $this->session;
            $guestArticles = $session->get('guest_articles', []);
            if (!in_array($articleId, $guestArticles)) {
                $guestArticles[] = $articleId;
                $session->set('guest_articles', $guestArticles);
            }
            return JsonResponse::success(
                ['redirect' => '/board/' . $boardSlug . '/view/' . $articleId],
                '확인되었습니다.'
            );
        }

        return JsonResponse::error('비밀번호가 일치하지 않습니다.');
    }

    private function getFormSchema(): array
    {
        return [
            'numeric' => ['category_id'],
            'bool' => ['is_notice', 'is_secret'],
            // content는 BoardContentSanitizer로 별도 처리(FormHelper 우회)하므로 html 목록에서 제외
            'html' => [],
        ];
    }

    /** @return int[] */
    private function getGuestArticleIds(): array
    {
        if ($this->authService->check()) {
            return [];
        }

        $ids = $this->session->get('guest_articles', []);
        if (!is_array($ids)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn(int $id): bool => $id > 0
        )));
    }

    private function canManageGuestArticle(int $articleId): bool
    {
        return in_array($articleId, $this->getGuestArticleIds(), true);
    }

    /**
     * article_viewed 쿠키 → 글 ID 목록.
     *
     * 값은 클라이언트가 보내는 것이라 숫자만 남기고, 들어올 때부터 상한을 적용한다.
     * 예전 쿠키가 이미 커진 방문자도 다음 조회에서 정리된다.
     *
     * @return string[]
     */
    private function parseViewedIds(string $cookie): array
    {
        if ($cookie === '') {
            return [];
        }

        $ids = array_values(array_filter(
            explode('.', $cookie),
            static fn(string $id): bool => $id !== '' && ctype_digit($id)
        ));

        return array_slice($ids, -self::VIEWED_COOKIE_MAX);
    }

    /**
     * 조회 기록에 글 ID 추가. 상한을 넘으면 오래된 것부터 밀어낸다.
     *
     * @param string[] $viewedIds
     * @return string[]
     */
    private function appendViewedId(array $viewedIds, int $articleId): array
    {
        $viewedIds[] = (string) $articleId;

        return array_slice($viewedIds, -self::VIEWED_COOKIE_MAX);
    }

    private function forgetGuestArticle(int $articleId): void
    {
        $ids = array_values(array_filter(
            $this->getGuestArticleIds(),
            static fn(int $id): bool => $id !== $articleId
        ));
        $this->session->set('guest_articles', $ids);
    }

    /** 스킨 파일이 없을 때 돌아갈 기본 스킨 */
    private const FALLBACK_SKIN = 'basic';

    /**
     * 스킨 뷰의 절대경로를 해석한다 (파일 단위 폴백).
     *
     * 스킨 폴더에 해당 파일이 없으면 basic 것을 쓴다. 게시판 스킨은 대개
     * 목록과 CSS 만 다르고 상세·작성·비밀번호 화면은 거의 같아서, 폴백이 없으면
     * 스킨을 만들 때마다 500 줄 가까운 파일을 복사해야 하고 폼에 필드 하나
     * 추가할 때 전 스킨을 고쳐야 한다. 코어 블록 스킨과 같은 규칙이다.
     *
     * 스킨명은 관리자 설정에서 오므로 경로 조작을 막는다.
     *
     * @param string $file 확장자 없는 뷰 이름 (List / View / Write / Password)
     */
    private function skinView(string $skin, string $file): string
    {
        $base = dirname(__DIR__, 2) . '/views/Front/Board/';

        if (preg_match('/^[A-Za-z0-9_-]+$/', $skin) !== 1) {
            $skin = self::FALLBACK_SKIN;
        }

        if ($skin !== self::FALLBACK_SKIN && !is_file($base . $skin . '/' . $file . '.php')) {
            $skin = self::FALLBACK_SKIN;
        }

        return $base . $skin . '/' . $file;
    }
}
