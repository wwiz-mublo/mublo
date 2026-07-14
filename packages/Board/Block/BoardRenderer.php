<?php
namespace Mublo\Packages\Board\Block;

use Mublo\Core\Block\Renderer\RendererInterface;
use Mublo\Core\Block\Renderer\SkinRendererTrait;
use Mublo\Entity\Block\BlockColumn;
use Mublo\Packages\Board\Helper\ArticlePresenter;
use Mublo\Packages\Board\Helper\BoardListAssembler;
use Mublo\Packages\Board\Repository\BoardArticleRepository;
use Mublo\Packages\Board\Repository\BoardConfigRepository;

/**
 * BoardRenderer
 *
 * 게시판 최신글 콘텐츠 렌더러
 *
 * 스킨에 전달되는 변수:
 * - $titleConfig: 타이틀 설정 (SkinRendererTrait에서 추출)
 * - $contentConfig: 콘텐츠 설정
 * - $column: BlockColumn 엔티티
 * - $items: ArticlePresenter::toList() 변환 결과
 * - $board: BoardConfig 엔티티
 */
class BoardRenderer implements RendererInterface
{
    use SkinRendererTrait;

    private BoardArticleRepository $articleRepository;
    private BoardConfigRepository $boardRepository;

    public function __construct(
        BoardArticleRepository $articleRepository,
        BoardConfigRepository $boardRepository
    ) {
        $this->articleRepository = $articleRepository;
        $this->boardRepository = $boardRepository;
    }

    /**
     * 스킨 타입 반환
     */
    protected function getSkinType(): string
    {
        return 'board';
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
        $contentItems = $column->getContentItems() ?? [];
        $count = max($column->getPcCount(), $column->getMoCount());
        $skin = $column->getContentSkin() ?: 'basic';

        // 게시판 ID 또는 슬러그 결정
        $boardIdOrSlug = $config['board_id'] ?? ($contentItems[0] ?? null);

        $board = null;
        $items = [];

        if ($boardIdOrSlug) {
            // 게시판 정보 조회 (ID 또는 슬러그로)
            $board = is_numeric($boardIdOrSlug)
                ? $this->boardRepository->findAccessibleById($column->getDomainId(), (int) $boardIdOrSlug)
                : $this->boardRepository->findBySlug($column->getDomainId(), (string) $boardIdOrSlug);

            if ($board && $board->isActive()) {
                // 최신글 조회 (전역 게시판이면 도메인 필터 생략)
                $result = $this->articleRepository->getPaginatedList(
                    $column->getDomainId(),
                    $board->getBoardId(),
                    1,
                    $count,
                    ['status' => 'published'],
                    $board->isGlobal()
                );

                // 관계 데이터(첨부/링크/카테고리)를 $item에 흡수 후 ArticlePresenter로 스킨용 변환
                $rawItems = BoardListAssembler::assemble($result['items'] ?? [], $result['relations'] ?? []);
                $presenter = new ArticlePresenter($board->toArray());
                $items = $presenter->toList($rawItems, $board->getBoardSlug());
            } else {
                // 지정한 게시판이 삭제/비활성 → 게시글 없는 것처럼
                $board = null;
            }
        }

        // 게시판 미지정/미발견도 빈 목록으로 스킨 렌더 (게시글 없는 것처럼 동작)
        return $this->renderSkin($column, $skin, [
            'items' => $items,
            'board' => $board,
        ]);
    }
}
