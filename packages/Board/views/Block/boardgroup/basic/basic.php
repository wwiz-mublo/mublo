<?php
/**
 * Block Skin: boardgroup/basic  (게시판 그룹 — 기본: 탭 없이 합친 목록)
 *
 * 여러 게시판의 글을 하나로 병합 → 최신순 정렬 → 개수 컷 해서 한 목록으로 보여준다.
 * 룩은 board/basic 과 동일(2줄: 제목·뱃지 / 작성자·작성일·댓글).
 *
 * 스코프 루트: .block-boardgroup--board-basic
 *
 * @var array  $titleConfig
 * @var string $titlePartial
 * @var array  $contentConfig
 * @var \Mublo\Entity\Block\BlockColumn $column
 * @var \Mublo\Entity\Board\BoardGroup|null $group
 * @var array  $boardsData  게시판별 데이터 [{board, articles}, ...]
 * @var \Mublo\Core\Rendering\AssetManager|null $assets
 */

$showDate = $contentConfig['show_date'] ?? true;
$showComment = $contentConfig['show_comment_count'] ?? true;

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
    $assets->addCss('/serve/package/Board/views/Block/boardgroup/basic/style.css');
}
?>
<div class="block-boardgroup block-boardgroup--board-basic">
    <?php include $titlePartial; ?>

    <!-- 콘텐츠 영역 -->
    <div class="block-boardgroup__content block-body">
        <?php if (empty($items)): ?>
        <p class="block-boardgroup__empty">
            <i class="bi bi-inbox block-boardgroup__empty-icon"></i>
            <span>등록된 글이 없습니다.</span>
        </p>
        <?php else: ?>
        <ul class="block-boardgroup__list">
            <?php foreach ($items as $item): ?>
            <li class="block-boardgroup__item">
                <a href="<?= htmlspecialchars($item['url']) ?>" class="block-boardgroup__link">
                    <!-- 1줄: 제목 · 뱃지 -->
                    <span class="block-boardgroup__head">
                        <span class="block-boardgroup__title">
                            <?php if (!empty($item['is_new'])): ?>
                            <span class="block-boardgroup__badge-new">N</span>
                            <?php endif; ?>
                            <?= $item['title_safe'] ?>
                        </span>
                    </span>
                    <!-- 2줄: 작성자 · 작성일 ····· 댓글수(끝) -->
                    <span class="block-boardgroup__meta">
                        <span class="block-boardgroup__author"><?= $item['author_name'] ?></span>
                        <?php if ($showDate && !empty($item['date_short'])): ?>
                        <span class="block-boardgroup__sep">·</span><span class="block-boardgroup__date"><?= $item['date_short'] ?></span>
                        <?php endif; ?>
                        <?php if ($showComment && ($item['comment_count'] ?? 0) > 0): ?>
                        <span class="block-boardgroup__comment">[<?= $item['comment_count'] ?>]</span>
                        <?php endif; ?>
                    </span>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>
</div>
