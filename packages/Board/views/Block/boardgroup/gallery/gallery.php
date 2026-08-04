<?php
/**
 * Block Skin: boardgroup/gallery  (게시판 그룹 — 갤러리: 탭 없이 합친 카드)
 *
 * 여러 게시판 글을 하나로 병합 → 최신순 → 개수 컷 해서 단일 카드 그리드로 보여준다.
 * 룩은 board/gallery 와 동일. 열 수/모드는 칸 설정값(MubloItemLayout)을 따름.
 *
 * 스코프 루트: .block-boardgroup--board-gallery
 *
 * @var array  $titleConfig
 * @var string $titlePartial
 * @var array  $contentConfig
 * @var \Mublo\Contract\Block\BlockColumnView $column
 * @var \Mublo\Entity\Board\BoardGroup|null $group
 * @var array  $boardsData  게시판별 데이터 [{board, articles}, ...]
 * @var \Mublo\Core\Rendering\AssetManager|null $assets
 */

$showDate = $contentConfig['show_date'] ?? true;

// 여러 게시판 글 병합 → 최신순 → 개수 컷
$merged = [];
foreach (($boardsData ?? []) as $data) {
    foreach ($data['articles'] as $article) {
        $merged[] = $article;
    }
}
usort($merged, fn($a, $b) => strcmp($b['date_raw'] ?? '', $a['date_raw'] ?? ''));
$count = max($column->getPcCount(), $column->getMoCount());
$items = array_slice($merged, 0, $count);

if ($assets) {
    $assets->addCss('/serve/package/Board/views/Block/boardgroup/gallery/style.css');
}
?>
<div class="block-boardgroup block-boardgroup--board-gallery">
    <?php include $titlePartial; ?>

    <!-- 콘텐츠 영역 -->
    <div class="block-boardgroup__content block-body">
        <?php if (empty($items)): ?>
        <p class="block-boardgroup__empty">
            <i class="bi bi-inbox block-boardgroup__empty-icon"></i>
            <span>등록된 글이 없습니다.</span>
        </p>
        <?php else: ?>
        <!-- 열 수/모드는 칸 설정값을 따름 (data-pc-cols/mo-cols, list|slide) -->
        <div class="mublo-item-layout" <?= $column->getLayoutDataAttributes() ?>>
            <ul class="block-boardgroup__cards">
                <?php foreach ($items as $item): $thumb = $item['thumbnail'] ?? ''; ?>
                <li class="block-boardgroup__card">
                    <a href="<?= htmlspecialchars($item['url']) ?>" class="block-boardgroup__card-link">
                        <span class="block-boardgroup__thumb">
                            <?php if ($thumb): ?>
                            <img src="<?= htmlspecialchars($thumb) ?>" alt="" loading="lazy">
                            <?php else: ?>
                            <span class="block-boardgroup__thumb-empty">
                                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                    <rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/>
                                </svg>
                            </span>
                            <?php endif; ?>
                            <?php if (!empty($item['is_new'])): ?>
                            <span class="block-boardgroup__badge-new">N</span>
                            <?php endif; ?>
                        </span>
                        <span class="block-boardgroup__card-body">
                            <span class="block-boardgroup__title"><?= $item['title_safe'] ?></span>
                            <span class="block-boardgroup__meta">
                                <span class="block-boardgroup__author"><?= $item['author_name'] ?></span>
                                <?php if ($showDate && !empty($item['date_short'])): ?>
                                <span class="block-boardgroup__sep">·</span><span class="block-boardgroup__date"><?= $item['date_short'] ?></span>
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
