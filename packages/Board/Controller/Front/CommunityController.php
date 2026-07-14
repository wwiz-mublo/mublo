<?php
namespace Mublo\Packages\Board\Controller\Front;

use Mublo\Core\Response\ViewResponse;
use Mublo\Core\Response\RedirectResponse;
use Mublo\Core\Context\Context;
use Mublo\Packages\Board\Service\CommunityService;
use Mublo\Packages\Board\Helper\ArticlePresenter;

/**
 * Front 커뮤니티 컨트롤러
 *
 * /community 라우트 처리 (통합 게시글 피드)
 */
class CommunityController
{
    private CommunityService $communityService;

    public function __construct(CommunityService $communityService)
    {
        $this->communityService = $communityService;
    }

    /**
     * 커뮤니티 메인 (최신글 / 인기글)
     * GET /community
     */
    public function index(array $params, Context $context): ViewResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();

        $page = max(1, (int) ($request->get('page') ?? 1));
        $sortBy = $request->get('sort') ?? 'latest';
        $keyword = trim($request->get('keyword') ?? '');
        $searchField = $request->get('search_field') ?? 'title';
        $perPage = 20;

        $filters = [];
        if ($keyword !== '') {
            $filters['keyword'] = $keyword;
            $filters['search_field'] = $searchField;
        }

        if ($sortBy === 'popular') {
            $filters['days'] = (int) ($request->get('days') ?? 7);
            $result = $this->communityService->getPopularFeed(
                $domainId, $page, $perPage, $filters, $context
            );
        } else {
            $result = $this->communityService->getFeed(
                $domainId, $page, $perPage, $filters, $context
            );
        }

        return $this->buildResponse($result, $domainId, $keyword, $searchField, null, $sortBy);
    }

    /**
     * 인기글
     * GET /community/popular
     */
    public function popular(array $params, Context $context): ViewResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();

        $page = max(1, (int) ($request->get('page') ?? 1));
        $keyword = trim($request->get('keyword') ?? '');
        $searchField = $request->get('search_field') ?? 'title';
        $days = (int) ($request->get('days') ?? 7);
        $perPage = 20;

        $filters = ['days' => $days];
        if ($keyword !== '') {
            $filters['keyword'] = $keyword;
            $filters['search_field'] = $searchField;
        }

        $result = $this->communityService->getPopularFeed(
            $domainId, $page, $perPage, $filters, $context
        );

        return $this->buildResponse($result, $domainId, $keyword, $searchField, null, 'popular');
    }

    /**
     * 그룹별 필터링
     * GET /community/group/{slug}
     */
    public function group(array $params, Context $context): ViewResponse|RedirectResponse
    {
        $groupSlug = $params['slug'] ?? '';
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();

        $group = $this->communityService->getGroupBySlug($domainId, $groupSlug);
        if (!$group || !$group->isActive()) {
            return ViewResponse::view('error/notfound')
                ->withStatusCode(404)
                ->withData(['message' => '그룹을 찾을 수 없습니다.']);
        }

        $page = max(1, (int) ($request->get('page') ?? 1));
        $sortBy = $request->get('sort') ?? 'latest';
        $keyword = trim($request->get('keyword') ?? '');
        $searchField = $request->get('search_field') ?? 'title';
        $perPage = 20;

        $filters = ['group_id' => $group->getGroupId()];
        if ($keyword !== '') {
            $filters['keyword'] = $keyword;
            $filters['search_field'] = $searchField;
        }

        if ($sortBy === 'popular') {
            $filters['days'] = (int) ($request->get('days') ?? 7);
            $result = $this->communityService->getPopularFeed(
                $domainId, $page, $perPage, $filters, $context
            );
        } else {
            $result = $this->communityService->getFeed(
                $domainId, $page, $perPage, $filters, $context
            );
        }

        return $this->buildResponse($result, $domainId, $keyword, $searchField, $groupSlug, $sortBy);
    }

    /**
     * 공통 ViewResponse 빌드
     */
    private function buildResponse(
        \Mublo\Core\Result\Result $result,
        int $domainId,
        string $keyword,
        string $searchField,
        ?string $currentGroup,
        string $sortBy
    ): ViewResponse {
        $data = $result->getData();

        // Presenter: 커뮤니티 목록용 데이터 변환 (여러 게시판 혼합이므로 기본 설정)
        $presenter = new ArticlePresenter();
        $items = $presenter->toCommunityList($data['items']);

        $pagination = $data['pagination'];
        $pagination['pageNums'] = 10;

        $groups = $this->communityService->getActiveGroups($domainId);

        return ViewResponse::absoluteView(dirname(__DIR__, 2) . '/views/Front/Community/basic/Index')
            ->withData([
                'items'        => $items,
                'pagination'   => $pagination,
                'filters'      => [
                    'keyword'      => $keyword,
                    'search_field' => $searchField,
                ],
                'groups'       => $groups,
                'currentGroup' => $currentGroup,
                'sortBy'       => $sortBy,
            ]);
    }
}
