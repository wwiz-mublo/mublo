<?php
declare(strict_types=1);
namespace Mublo\Packages\Board\Block;

use Mublo\Core\Block\Renderer\RendererInterface;
use Mublo\Core\Block\Renderer\SkinRendererTrait;
use Mublo\Contract\Block\BlockColumnView;
use Mublo\Packages\Board\Helper\CommentPresenter;
use Mublo\Packages\Board\Repository\BoardCommentRepository;
use Mublo\Packages\Board\Repository\BoardConfigRepository;

/**
 * BoardCommentRenderer
 *
 * 게시판 최신댓글 콘텐츠 렌더러
 *
 * 게시판 지정:
 * - 지정한 게시판의 최신 댓글을 표시. 미지정/삭제/비활성이면 댓글 없는 것처럼 동작.
 *
 * 스킨에 전달되는 변수:
 * - $titleConfig: 타이틀 설정 (SkinRendererTrait에서 추출)
 * - $contentConfig: 콘텐츠 설정
 * - $column: BlockColumnView 엔티티
 * - $items: CommentPresenter::toList() 변환 결과
 */
class BoardCommentRenderer implements RendererInterface
{
    use SkinRendererTrait;

    public function __construct(
        private BoardCommentRepository $commentRepository,
        private BoardConfigRepository $boardRepository
    ) {}

    protected function getSkinType(): string
    {
        return 'boardcomment';
    }

    protected function getSkinBasePath(): string
    {
        return MUBLO_PACKAGE_PATH . '/Board/views/Block/';
    }

    /**
     * {@inheritdoc}
     */
    public function render(BlockColumnView $column): string
    {
        $config       = $column->getContentConfig() ?? [];
        $contentItems = $column->getContentItems() ?? [];
        $count        = max($column->getPcCount(), $column->getMoCount());
        $skin         = $column->getContentSkin() ?: 'basic';

        // 게시판 지정 여부 결정 (board 블록과 동일 규칙: config.board_id 또는 items[0])
        $boardIdOrSlug = $config['board_id'] ?? ($contentItems[0] ?? null);

        $rows = [];

        if ($boardIdOrSlug) {
            $board = is_numeric($boardIdOrSlug)
                ? $this->boardRepository->findAccessibleById($column->getDomainId(), (int) $boardIdOrSlug)
                : $this->boardRepository->findBySlug($column->getDomainId(), (string) $boardIdOrSlug);

            // 지정한 게시판이 있고 활성일 때만 댓글 조회.
            // 미지정/삭제/비활성은 조회하지 않아 댓글 없는 것처럼 동작.
            if ($board && $board->isActive()) {
                $rows = $this->commentRepository->getRecentForBlock(
                    $column->getDomainId(),
                    $count,
                    $board->getBoardId(),
                    $board->isGlobal()
                );
            }
        }

        $excerptLength = (int) ($config['excerpt_length'] ?? 60);
        if ($excerptLength < 10) {
            $excerptLength = 60;
        }

        $items = (new CommentPresenter())->toList($rows, $excerptLength);

        return $this->renderSkin($column, $skin, [
            'items' => $items,
        ]);
    }
}
