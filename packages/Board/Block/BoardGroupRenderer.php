<?php
namespace Mublo\Packages\Board\Block;

use Mublo\Core\Block\Renderer\RendererInterface;
use Mublo\Core\Block\Renderer\SkinRendererTrait;
use Mublo\Entity\Block\BlockColumn;
use Mublo\Packages\Board\Helper\ArticlePresenter;
use Mublo\Packages\Board\Helper\BoardListAssembler;
use Mublo\Packages\Board\Repository\BoardArticleRepository;
use Mublo\Packages\Board\Repository\BoardConfigRepository;
use Mublo\Packages\Board\Repository\BoardGroupRepository;

/**
 * BoardGroupRenderer
 *
 * 게시판 그룹 콘텐츠 렌더러
 *
 * 스킨에 전달되는 변수:
 * - $titleConfig: 타이틀 설정
 * - $contentConfig: 콘텐츠 설정
 * - $column: BlockColumn 엔티티
 * - $group: 게시판 그룹 엔티티 (nullable)
 * - $boardsData: 게시판별 데이터 배열 [{board, articles}, ...]
 *   - articles: ArticlePresenter::toList() 변환 결과
 * - $displayType: tab|list|grid
 */
class BoardGroupRenderer implements RendererInterface
{
    use SkinRendererTrait;

    private BoardArticleRepository $articleRepository;
    private BoardConfigRepository $boardRepository;
    private BoardGroupRepository $groupRepository;

    public function __construct(
        BoardArticleRepository $articleRepository,
        BoardConfigRepository $boardRepository,
        BoardGroupRepository $groupRepository
    ) {
        $this->articleRepository = $articleRepository;
        $this->boardRepository = $boardRepository;
        $this->groupRepository = $groupRepository;
    }

    /**
     * 스킨 타입 반환
     */
    protected function getSkinType(): string
    {
        return 'boardgroup';
    }

    /**
     * 스킨 기본 경로 (Package 내부)
     */
    protected function getSkinBasePath(): string
    {
        return MUBLO_PACKAGE_PATH . '/Board/views/Block/';
    }

    /**
     * {@inheritdoc}
     */
    public function render(BlockColumn $column): string
    {
        $config = $column->getContentConfig() ?? [];
        // boardgroup 의 content_items 는 "그룹 ID 목록"이다 (BlockContentItemsSubscriber 참조).
        $items = $column->getContentItems() ?? [];
        $count = max($column->getPcCount(), $column->getMoCount());
        $skin = $column->getContentSkin() ?: 'basic';
        $displayType = $config['display_type'] ?? 'tab';

        // 그룹 ID 결정: 콘텐츠 아이템(그룹 셀렉터) 우선, 없으면 config.group_id 폴백
        $groupIds = !empty($items)
            ? array_map('intval', $items)
            : (isset($config['group_id']) ? [(int) $config['group_id']] : []);

        $group = null;
        $boardsData = [];

        if (!empty($groupIds)) {
            // 그룹 정보 조회 (첫 그룹 — 타이틀 등 표시용)
            $group = $this->groupRepository->findByIdForDomain($column->getDomainId(), $groupIds[0]);

            // 선택된 그룹(들)에 속한 활성 게시판 ID 수집
            $boardIds = [];
            foreach ($groupIds as $gid) {
                if (!$this->groupRepository->findByIdForDomain($column->getDomainId(), $gid)) {
                    continue;
                }
                foreach ($this->getBoardIdsByGroup($gid, $column->getDomainId()) as $bid) {
                    $boardIds[] = $bid;
                }
            }
            $boardIds = array_values(array_unique($boardIds));

            if (!empty($boardIds)) {
                // 게시판별 최신글 조회
                $boardsData = $this->getBoardsWithArticles($boardIds, $column->getDomainId(), $count);
            }
        }

        // 그룹 미지정/게시판 없음도 빈 데이터로 스킨 렌더 (게시글 없는 것처럼 동작)
        return $this->renderSkin($column, $skin, [
            'group' => $group,
            'boardsData' => $boardsData,
            'displayType' => $displayType,
        ]);
    }

    /**
     * 그룹 내 게시판 ID 목록 조회
     */
    private function getBoardIdsByGroup(int $groupId, int $domainId): array
    {
        $boards = $this->boardRepository->findByGroup($groupId);

        return array_map(
            fn($b) => $b->getBoardId(),
            array_filter($boards, fn($b) => $b->getDomainId() === $domainId && $b->isActive())
        );
    }

    /**
     * 게시판별 최신글 데이터 조회
     */
    private function getBoardsWithArticles(array $boardIds, int $domainId, int $count): array
    {
        $result = [];

        foreach ($boardIds as $boardId) {
            $board = $this->boardRepository->findAccessibleById($domainId, $boardId);

            if (!$board || !$board->isActive()) {
                continue;
            }

            $articlesResult = $this->articleRepository->getPaginatedList(
                $domainId,
                $boardId,
                1,
                $count,
                ['status' => 'published'],
                $board->isGlobal()
            );

            // 관계 데이터(첨부/링크/카테고리) 흡수 후 ArticlePresenter로 스킨용 변환
            $boardSlug = $board->getBoardSlug();
            $rawArticles = BoardListAssembler::assemble(
                $articlesResult['items'] ?? [],
                $articlesResult['relations'] ?? []
            );
            $presenter = new ArticlePresenter($board->toArray());
            $articles = $presenter->toList($rawArticles, $boardSlug);

            $result[] = [
                'board' => $board,
                'articles' => $articles,
            ];
        }

        return $result;
    }
}
