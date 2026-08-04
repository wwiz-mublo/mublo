<?php
/**
 * Block Skin: board/thumb  (게시판 최신글 — 썸네일 목록)
 *
 * 좌측 썸네일 + 우측 본문(2줄). 사진이 없으면 플레이스홀더로 자리를 채워 행 높이를 일관되게 유지.
 * board/basic 의 2줄 콘텐츠에 좌측 고정 썸네일을 더한 형태.
 *
 * 스코프 루트: .block-board--board-thumb
 *
 * @var array  $titleConfig
 * @var string $titlePartial
 * @var array  $contentConfig
 * @var \Mublo\Contract\Block\BlockColumnView $column
 * @var array  $items   ArticlePresenter::toList() 결과
 * @var \Mublo\Entity\Board\BoardConfig $board
 * @var \Mublo\Core\Rendering\AssetManager|null $assets
 */

$items = $items ?? [];
$showDate = $contentConfig['show_date'] ?? true;
$showComment = $contentConfig['show_comment_count'] ?? true;

if ($assets) {
    $assets->addCss('/serve/package/Board/views/Block/board/thumb/style.css');
}
?>
<div class="block-board block-board--board-thumb">
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
            <?php foreach ($items as $item): $thumb = $item['thumbnail'] ?? ''; ?>
            <li class="block-board__item">
                <a href="<?= htmlspecialchars($item['url']) ?>" class="block-board__link">
                    <!-- 좌측 썸네일 (없으면 플레이스홀더) -->
                    <span class="block-board__thumb">
                        <?php if ($thumb): ?>
                        <img src="<?= htmlspecialchars($thumb) ?>" alt="" loading="lazy">
                        <?php else: ?>
                        <span class="block-board__thumb-empty">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                <rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/>
                            </svg>
                        </span>
                        <?php endif; ?>
                    </span>
                    <!-- 우측 본문 (2줄) -->
                    <span class="block-board__body">
                        <span class="block-board__head">
                            <span class="block-board__title">
                                <?php if (!empty($item['is_new'])): ?>
                                <span class="block-board__badge-new">N</span>
                                <?php endif; ?>
                                <?= $item['title_safe'] ?>
                            </span>
                        </span>
                        <span class="block-board__meta">
                            <span class="block-board__author"><?= $item['author_name'] ?></span>
                            <?php if ($showDate && !empty($item['date_short'])): ?>
                            <span class="block-board__sep">·</span><span class="block-board__date"><?= $item['date_short'] ?></span>
                            <?php endif; ?>
                            <?php if ($showComment && ($item['comment_count'] ?? 0) > 0): ?>
                            <span class="block-board__comment">[<?= $item['comment_count'] ?>]</span>
                            <?php endif; ?>
                        </span>
                    </span>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>
</div>
