<?php
$entries = $entries ?? [];
$config = $config ?? [];
$pcCount = (int) ($pcCount ?? 12);
$moCount = (int) ($moCount ?? $pcCount);
$assets?->addCss('/serve/plugin/Manual/views/Block/_shared/manual.css');
?>
<nav class="manual-block manual-block--toc" aria-label="매뉴얼 목차">
    <?php include $titlePartial; ?>
    <div class="manual-block__body block-body">
        <?php if ($book === null): ?>
            <div class="manual-block__empty"><i class="bi bi-book"></i><span>표시할 매뉴얼을 선택해 주세요.</span></div>
        <?php else: ?>
            <div class="manual-toc-head">
                <div>
                    <strong><?= htmlspecialchars($book->title) ?></strong>
                    <?php if (!empty($config['show_description']) && $book->description): ?>
                        <p><?= htmlspecialchars($book->description) ?></p>
                    <?php endif; ?>
                </div>
                <?php if (!empty($config['show_root_link'])): ?>
                    <a href="/manual/<?= rawurlencode($book->slug) ?>" aria-label="전체 매뉴얼 보기"><i class="bi bi-box-arrow-up-right"></i></a>
                <?php endif; ?>
            </div>

            <?php if ($entries === []): ?>
                <div class="manual-block__empty"><i class="bi bi-list-nested"></i><span>등록된 페이지가 없습니다.</span></div>
            <?php else: ?>
                <div class="manual-toc-list">
                    <?php foreach ($entries as $index => $entry): ?>
                        <?php
                        $node = $entry['node'];
                        $classes = ['manual-toc-link'];
                        if ($index >= $pcCount) $classes[] = 'manual-block-item--pc-hidden';
                        if ($index >= $moCount) $classes[] = 'manual-block-item--mo-hidden';
                        ?>
                        <a class="<?= implode(' ', $classes) ?>"
                           style="--manual-depth:<?= (int) $entry['depth'] ?>"
                           href="/manual/<?= rawurlencode($book->slug) ?>/<?= rawurlencode($node->slug) ?>">
                            <span class="manual-toc-link__dot"></span>
                            <span><?= htmlspecialchars($node->title) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</nav>
