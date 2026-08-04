<?php
/**
 * Block Skin: board/basic
 *
 * 게시판 최신글 기본 스킨
 *
 * MubloItemLayout 비적용:
 *   게시판 1개 = 아이템 1개이므로 글 목록에 레이아웃 모듈 불필요.
 *   복수 게시판 배치가 필요하면 boardgroup 타입 사용.
 *
 * @var array $titleConfig 타이틀 설정
 * @var string $titlePartial 타이틀 파셜 경로
 * @var array $contentConfig 콘텐츠 설정
 * @var \Mublo\Contract\Block\BlockColumnView $column 블록 칸 엔티티
 * @var array $items ArticlePresenter::toList() 변환 결과
 * @var \Mublo\Entity\Board\BoardConfig $board 게시판 설정
 * @var \Mublo\Core\Rendering\AssetManager|null $assets 에셋 매니저
 */

$items = $items ?? [];
$showDate = $contentConfig['show_date'] ?? true;
$showComment = $contentConfig['show_comment_count'] ?? true;

if ($assets) {
    $assets->addCss('/serve/package/Board/views/Block/board/basic/style.css');
}
?>
<div class="block-board block-board--board-basic">
    <?php include $titlePartial; ?>

    <!-- 콘텐츠 영역 -->
    <div class="block-board__content block-body">
        <?php if (empty($items)): ?>
        <p class="block-board__empty">
            <i class="bi bi-inbox block-board__empty-icon"></i>
            <span>등록된 글이 없습니다.</span>
        </p>
        <?php else: ?>
        <ul class="block-board__list">
            <?php foreach ($items as $item): ?>
            <li class="block-board__item">
                <a href="<?= htmlspecialchars($item['url']) ?>" class="block-board__link">
                    <!-- 1줄: 제목 · 뱃지 -->
                    <span class="block-board__head">
                        <span class="block-board__title">
                            <?php if (!empty($item['is_new'])): ?>
                            <span class="block-board__badge-new">N</span>
                            <?php endif; ?>
                            <?= $item['title_safe'] ?>
                        </span>
                    </span>
                    <!-- 2줄: 작성자 · 작성일 ····· 댓글수(끝) -->
                    <span class="block-board__meta">
                        <span class="block-board__author"><?= $item['author_name'] ?></span>
                        <?php if ($showDate && !empty($item['date_short'])): ?>
                        <span class="block-board__sep">·</span><span class="block-board__date"><?= $item['date_short'] ?></span>
                        <?php endif; ?>
                        <?php if ($showComment && ($item['comment_count'] ?? 0) > 0): ?>
                        <span class="block-board__comment">[<?= $item['comment_count'] ?>]</span>
                        <?php endif; ?>
                    </span>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>
</div>
