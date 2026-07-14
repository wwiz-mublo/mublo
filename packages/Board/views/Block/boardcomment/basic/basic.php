<?php
/**
 * Block Skin: boardcomment/basic
 *
 * 게시판 최신댓글 기본 스킨
 *
 * @var array $titleConfig 타이틀 설정
 * @var string $titlePartial 타이틀 파셜 경로
 * @var array $contentConfig 콘텐츠 설정
 * @var \Mublo\Entity\Block\BlockColumn $column 블록 칸 엔티티
 * @var array $items CommentPresenter::toList() 변환 결과
 * @var \Mublo\Core\Rendering\AssetManager|null $assets 에셋 매니저
 */

$items = $items ?? [];
$showDate  = $contentConfig['show_date'] ?? true;
$showBoard = $contentConfig['show_board'] ?? true;

if ($assets) {
    $assets->addCss('/serve/package/Board/views/Block/boardcomment/basic/style.css');
}
?>
<div class="block-boardcomment block-boardcomment--board-basic">
    <?php include $titlePartial; ?>

    <!-- 콘텐츠 영역 -->
    <div class="block-boardcomment__content block-body">
        <?php if (empty($items)): ?>
        <p class="block-boardcomment__empty">
            <i class="bi bi-inbox block-boardcomment__empty-icon"></i>
            <span>등록된 댓글이 없습니다.</span>
        </p>
        <?php else: ?>
        <ul class="block-boardcomment__list">
            <?php foreach ($items as $item): ?>
            <li class="block-boardcomment__item">
                <a href="<?= htmlspecialchars($item['url']) ?>" class="block-boardcomment__link">
                    <!-- 1줄: 댓글 내용 -->
                    <span class="block-boardcomment__body"><?= $item['content_excerpt'] ?></span>
                    <!-- 2줄: 작성자 · 작성일 ····· 게시판명(끝) -->
                    <span class="block-boardcomment__meta">
                        <span class="block-boardcomment__author"><?= $item['author_name'] ?></span>
                        <?php if ($showDate && !empty($item['date_relative'])): ?>
                        <span class="block-boardcomment__sep">·</span><span class="block-boardcomment__date"><?= $item['date_relative'] ?></span>
                        <?php endif; ?>
                        <?php if ($showBoard && !empty($item['board_name'])): ?>
                        <span class="block-boardcomment__board"><?= $item['board_name'] ?></span>
                        <?php endif; ?>
                    </span>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>
</div>
