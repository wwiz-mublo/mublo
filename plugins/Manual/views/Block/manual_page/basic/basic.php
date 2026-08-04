<?php
$config = $config ?? [];
$mode = $config['display_mode'] ?? 'full';
$assets?->addCss('/serve/plugin/Manual/views/Block/_shared/manual.css');
?>
<article class="manual-block manual-block--page manual-block--page-<?= htmlspecialchars($mode) ?>">
    <?php include $titlePartial; ?>
    <div class="manual-block__body block-body">
        <?php if ($book === null || $page === null): ?>
            <div class="manual-block__empty"><i class="bi bi-file-earmark-text"></i><span>표시할 매뉴얼 페이지를 선택해 주세요.</span></div>
        <?php else: ?>
            <?php $url = '/manual/' . rawurlencode($book->slug) . '/' . rawurlencode($page->slug); ?>
            <div class="manual-page-card">
                <?php if (!empty($config['show_book_title'])): ?>
                    <a class="manual-page-card__book" href="/manual/<?= rawurlencode($book->slug) ?>">
                        <i class="bi bi-journal-text"></i><?= htmlspecialchars($book->title) ?>
                    </a>
                <?php endif; ?>
                <h3 class="manual-page-card__title"><a href="<?= $url ?>"><?= htmlspecialchars($page->title) ?></a></h3>

                <?php if ($mode === 'full'): ?>
                    <div class="manual-page-card__content manual-page-content"><?= (string) $page->content ?></div>
                <?php elseif ($mode === 'excerpt' && $excerpt !== ''): ?>
                    <p class="manual-page-card__excerpt"><?= htmlspecialchars($excerpt) ?></p>
                <?php endif; ?>

                <?php if (!empty($config['show_more_link'])): ?>
                    <a class="manual-block__more" href="<?= $url ?>">문서 전체 보기 <i class="bi bi-arrow-right"></i></a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</article>
