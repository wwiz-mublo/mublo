<?php
/**
 * 매뉴얼 목록 (프론트)
 *
 * @var string $pageTitle
 * @var list<\Mublo\Contract\Manual\ManualBook> $books  활성 책 목록
 * @var bool   $notFound
 */
$books = $books ?? [];
$notFound = $notFound ?? false;
$bookIcon = static function (string $slug): string {
    return match (true) {
        str_contains($slug, 'board') => 'bi-chat-square-text',
        str_contains($slug, 'shop') => 'bi-shop',
        str_contains($slug, 'skin') => 'bi-palette',
        str_contains($slug, 'admin') => 'bi-gear',
        str_contains($slug, 'start') => 'bi-rocket-takeoff',
        default => 'bi-journal-text',
    };
};
$this->assets->addCss('/serve/plugin/Manual/views/Front/skins/basic/style.css?v=20260722');
?>

<div class="manual-list-page">
    <div class="manual-list-page__header">
        <div class="manual-list-page__icon"><i class="bi bi-bookshelf"></i></div>
        <div class="manual-list-page__eyebrow">MUBLO GUIDE</div>
        <h1 class="manual-list-page__title"><?= htmlspecialchars($pageTitle) ?></h1>
        <p class="manual-list-page__subtitle">필요한 안내서를 선택해 운영 방법과 제작 가이드를 확인하세요.</p>
        <?php if (!empty($books)): ?>
            <div class="manual-list-page__count"><i class="bi bi-journals"></i> <?= count($books) ?>개의 매뉴얼</div>
        <?php endif; ?>
    </div>

    <?php if ($notFound): ?>
        <div class="manual-alert">요청하신 매뉴얼을 찾을 수 없습니다.</div>
    <?php endif; ?>

    <?php if (empty($books)): ?>
        <div class="manual-list-empty">
            <i class="bi bi-journal-x"></i>
            <strong>등록된 매뉴얼이 없습니다.</strong>
            <span>관리자에서 매뉴얼을 추가한 뒤 다시 확인해 주세요.</span>
        </div>
    <?php else: ?>
        <div class="manual-list-grid">
            <?php foreach ($books as $index => $book): ?>
                <a class="manual-card manual-card--tone-<?= ($index % 4) + 1 ?>" href="/manual/<?= htmlspecialchars($book->slug) ?>">
                    <div class="manual-card__top">
                        <div class="manual-card__icon"><i class="bi <?= $bookIcon($book->slug) ?>"></i></div>
                        <span class="manual-card__label">MANUAL</span>
                        <span class="manual-card__arrow"><i class="bi bi-arrow-up-right"></i></span>
                    </div>
                    <div class="manual-card__body">
                        <div class="manual-card__title"><?= htmlspecialchars($book->title) ?></div>
                        <?php if ($book->description !== null && $book->description !== ''): ?>
                            <div class="manual-card__desc"><?= htmlspecialchars($book->description) ?></div>
                        <?php else: ?>
                            <div class="manual-card__desc">운영 및 사용 방법을 확인할 수 있는 매뉴얼입니다.</div>
                        <?php endif; ?>
                    </div>
                    <div class="manual-card__footer">
                        <span>매뉴얼 열기</span>
                        <i class="bi bi-arrow-right"></i>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
