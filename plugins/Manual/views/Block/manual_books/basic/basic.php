<?php
/** @var list<\Mublo\Contract\Manual\ManualBook> $books */
$books = $books ?? [];
$config = $config ?? [];
$pcCount = (int) ($pcCount ?? 4);
$moCount = (int) ($moCount ?? $pcCount);
$assets?->addCss('/serve/plugin/Manual/views/Block/_shared/manual.css');
?>
<section class="manual-block manual-block--books manual-block--<?= htmlspecialchars($config['layout'] ?? 'grid') ?>">
    <?php include $titlePartial; ?>
    <div class="manual-block__body block-body">
        <?php if ($books === []): ?>
            <div class="manual-block__empty"><i class="bi bi-journal-x"></i><span>표시할 매뉴얼이 없습니다.</span></div>
        <?php else: ?>
            <div class="manual-books">
                <?php foreach ($books as $index => $book): ?>
                    <?php
                    $classes = ['manual-book-card'];
                    if ($index >= $pcCount) $classes[] = 'manual-block-item--pc-hidden';
                    if ($index >= $moCount) $classes[] = 'manual-block-item--mo-hidden';
                    ?>
                    <a class="<?= implode(' ', $classes) ?>" href="/manual/<?= rawurlencode($book->slug) ?>">
                        <span class="manual-book-card__icon"><i class="bi bi-journal-text"></i></span>
                        <span class="manual-book-card__content">
                            <strong class="manual-book-card__title"><?= htmlspecialchars($book->title) ?></strong>
                            <?php if (!empty($config['show_description']) && $book->description): ?>
                                <span class="manual-book-card__description"><?= htmlspecialchars($book->description) ?></span>
                            <?php endif; ?>
                        </span>
                        <?php if (!empty($config['show_link'])): ?>
                            <span class="manual-book-card__arrow" aria-hidden="true"><i class="bi bi-arrow-right"></i></span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
