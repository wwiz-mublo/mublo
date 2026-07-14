<?php
/**
 * Block Skin: board/gallery  (게시판 최신글 — 갤러리 스킨)
 *
 * 썸네일 카드 그리드. board/basic 과 동일 데이터($items)를 사용한다.
 * 썸네일이 없으면 플레이스홀더 아이콘 표시.
 *
 * 스코프 루트: .block-board--board-gallery
 *
 * @var array  $titleConfig
 * @var string $titlePartial
 * @var array  $contentConfig
 * @var \Mublo\Entity\Block\BlockColumn $column
 * @var array  $items   ArticlePresenter::toList() 결과
 * @var \Mublo\Entity\Board\BoardConfig $board
 * @var \Mublo\Core\Rendering\AssetManager|null $assets
 */

$items = $items ?? [];
$showDate = $contentConfig['show_date'] ?? true;

if ($assets) {
    $assets->addCss('/serve/package/Board/views/Block/board/gallery/style.css');
}
?>
<div class="block-board block-board--board-gallery">
    <?php include $titlePartial; ?>

    <!-- 콘텐츠 영역 -->
    <div class="block-board__content block-body">
        <?php if (empty($items)): ?>
        <p class="block-board__empty">
            <i class="bi bi-inbox block-board__empty-icon"></i>
            <span>등록된 글이 없습니다.</span>
        </p>
        <?php else: ?>
        <!-- 열 수/모드는 칸 설정값을 따름 (data-pc-cols/mo-cols, list|slide) -->
        <div class="mublo-item-layout" <?= $column->getLayoutDataAttributes() ?>>
            <ul class="block-board__cards">
                <?php foreach ($items as $item): $thumb = $item['thumbnail'] ?? ''; ?>
                <li class="block-board__card">
                    <a href="<?= htmlspecialchars($item['url']) ?>" class="block-board__card-link">
                        <span class="block-board__thumb">
                            <?php if ($thumb): ?>
                            <img src="<?= htmlspecialchars($thumb) ?>" alt="" loading="lazy">
                            <?php else: ?>
                            <span class="block-board__thumb-empty">
                                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                    <rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/>
                                </svg>
                            </span>
                            <?php endif; ?>
                            <?php if (!empty($item['is_new'])): ?>
                            <span class="block-board__badge-new">N</span>
                            <?php endif; ?>
                        </span>
                        <span class="block-board__card-body">
                            <span class="block-board__title"><?= $item['title_safe'] ?></span>
                            <span class="block-board__meta">
                                <span class="block-board__author"><?= $item['author_name'] ?></span>
                                <?php if ($showDate && !empty($item['date_short'])): ?>
                                <span class="block-board__sep">·</span><span class="block-board__date"><?= $item['date_short'] ?></span>
                                <?php endif; ?>
                            </span>
                        </span>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
    </div>
</div>
