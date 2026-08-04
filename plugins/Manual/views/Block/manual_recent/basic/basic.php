<?php
$items = $items ?? [];
$excerpts = $excerpts ?? [];
$config = $config ?? [];
$pcCount = (int) ($pcCount ?? 6);
$moCount = (int) ($moCount ?? $pcCount);
$assets?->addCss('/serve/plugin/Manual/views/Block/_shared/manual.css');
?>
<section class="manual-block manual-block--recent manual-block--recent-<?= htmlspecialchars($config['layout'] ?? 'list') ?>">
    <?php include $titlePartial; ?>
    <div class="manual-block__body block-body">
        <?php if ($items === []): ?>
            <div class="manual-block__empty"><i class="bi bi-clock-history"></i><span>최근 수정된 매뉴얼이 없습니다.</span></div>
        <?php else: ?>
            <div class="manual-recent-list">
                <?php foreach ($items as $index => $item): ?>
                    <?php
                    $classes = ['manual-recent-item'];
                    if ($index >= $pcCount) $classes[] = 'manual-block-item--pc-hidden';
                    if ($index >= $moCount) $classes[] = 'manual-block-item--mo-hidden';
                    try {
                        $updatedAt = (new DateTimeImmutable($item->updatedAt))->format('Y.m.d');
                    } catch (Throwable) {
                        $updatedAt = '';
                    }
                    ?>
                    <a class="<?= implode(' ', $classes) ?>"
                       href="/manual/<?= rawurlencode($item->bookSlug) ?>/<?= rawurlencode($item->pageSlug) ?>">
                        <span class="manual-recent-item__content">
                            <?php if (!empty($config['show_book_title'])): ?>
                                <span class="manual-recent-item__book"><?= htmlspecialchars($item->bookTitle) ?></span>
                            <?php endif; ?>
                            <strong class="manual-recent-item__title"><?= htmlspecialchars($item->pageTitle) ?></strong>
                            <?php if (!empty($config['show_excerpt']) && !empty($excerpts[$item->pageId])): ?>
                                <span class="manual-recent-item__excerpt"><?= htmlspecialchars($excerpts[$item->pageId]) ?></span>
                            <?php endif; ?>
                        </span>
                        <?php if (!empty($config['show_updated_at']) && $updatedAt !== ''): ?>
                            <time class="manual-recent-item__date" datetime="<?= htmlspecialchars($item->updatedAt) ?>"><?= $updatedAt ?></time>
                        <?php endif; ?>
                        <i class="bi bi-chevron-right manual-recent-item__arrow" aria-hidden="true"></i>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
