<?php
namespace Mublo\Packages\Board\Service;

use Mublo\Packages\Board\Repository\BoardArticleRepository;
use Mublo\Packages\Board\Repository\BoardConfigRepository;
use Mublo\Packages\Board\Repository\BoardGroupRepository;
use Mublo\Core\Context\Context;
use Mublo\Core\Result\Result;

/**
 * CommunityService
 *
 * 커뮤니티 통합 피드 조회 (전체/인기/그룹별)
 * 각 게시판의 권한을 확인하여 접근 가능한 게시글만 반환
 */
class CommunityService
{
    private BoardArticleRepository $articleRepository;
    private BoardConfigRepository $boardConfigRepository;
    private BoardGroupRepository $groupRepository;
    private BoardPermissionService $permissionService;

    public function __construct(
        BoardArticleRepository $articleRepository,
        BoardConfigRepository $boardConfigRepository,
        BoardGroupRepository $groupRepository,
        BoardPermissionService $permissionService
    ) {
        $this->articleRepository = $articleRepository;
        $this->boardConfigRepository = $boardConfigRepository;
        $this->groupRepository = $groupRepository;
        $this->permissionService = $permissionService;
    }

    /**
     * 최신글 피드
     */
    public function getFeed(
        int $domainId,
        int $page,
        int $perPage,
        array $filters,
        Context $context
    ): Result {
        $allowedBoardIds = $this->getAllowedBoardIds($domainId, $context);
        if (empty($allowedBoardIds)) {
            return Result::success('', $this->emptyResult($perPage));
        }

        $filters['board_ids'] = $allowedBoardIds;
        $filters['order_by'] = 'created_at';
        $filters['order_dir'] = 'DESC';

        $result = $this->articleRepository->getAllByDomain(
            $domainId, $page, $perPage, $filters
        );

        return Result::success('', $result);
    }

    /**
     * 인기글 피드
     */
    public function getPopularFeed(
        int $domainId,
        int $page,
        int $perPage,
        array $filters,
        Context $context
    ): Result {
        $allowedBoardIds = $this->getAllowedBoardIds($domainId, $context);
        if (empty($allowedBoardIds)) {
            return Result::success('', $this->emptyResult($perPage));
        }

        $filters['board_ids'] = $allowedBoardIds;

        $result = $this->articleRepository->getPopularPaginated(
            $domainId, $page, $perPage, $filters
        );

        return Result::success('', $result);
    }

    /**
     * 활성 그룹 목록 (탭 UI용)
     */
    public function getActiveGroups(int $domainId): array
    {
        $groups = $this->groupRepository->findActiveByDomain($domainId);
        return array_map(fn($g) => $g->toArray(), $groups);
    }

    /**
     * 슬러그로 그룹 조회
     */
    public function getGroupBySlug(int $domainId, string $slug): ?\Mublo\Packages\Board\Entity\BoardGroup
    {
        return $this->groupRepository->findBySlug($domainId, $slug);
    }

    /**
     * 허용된 게시판 ID 목록 (권한 필터링)
     */
    private function getAllowedBoardIds(int $domainId, Context $context): array
    {
        $activeBoards = $this->boardConfigRepository->findActiveByDomain($domainId);
        $allowedIds = [];

        foreach ($activeBoards as $board) {
            if ($this->permissionService->canList($board, $context)) {
                $allowedIds[] = $board->getBoardId();
            }
        }

        return $allowedIds;
    }

    /**
     * 빈 결과 반환
     */
    private function emptyResult(int $perPage): array
    {
        return [
            'items' => [],
            'pagination' => [
                'total' => 0,
                'per_page' => $perPage,
                'current_page' => 1,
                'last_page' => 1,
            ],
        ];
    }
}
