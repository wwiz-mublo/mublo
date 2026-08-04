<?php
/**
 * FAQ 프론트 스킨: basic
 *
 * 모던 아코디언 UI (라운드 카드 + 부드러운 전환)
 *
 * @var string $pageTitle
 * @var array $categories [{category_id, category_name, category_slug, item_count}, ...]
 * @var array $grouped [{category_name, category_slug, items: [{faq_id, question, answer}]}, ...]
 * @var string|null $activeSlug
 */
$categories = $categories ?? [];
$grouped = $grouped ?? [];
$activeSlug = $activeSlug ?? null;
$this->assets->addCss('/serve/plugin/Faq/views/Front/skins/basic/style.css');
?>


<div class="faq-page">
    <!-- 헤더 -->
    <div class="faq-page__header">
        <div class="faq-page__title-wrap">
            <i class="bi bi-patch-question faq-page__icon"></i>
            <h1 class="faq-page__title"><?= htmlspecialchars($pageTitle) ?></h1>
        </div>
        <p class="faq-page__subtitle">궁금한 점을 확인해보세요</p>
    </div>

    <!-- 카테고리 필터 -->
    <?php if (!empty($categories)): ?>
    <div class="faq-filter">
        <a href="/faq" class="faq-filter__btn <?= $activeSlug === null ? 'faq-filter__btn--active' : '' ?>">전체</a>
        <?php foreach ($categories as $cat): ?>
            <a href="/faq/<?= htmlspecialchars($cat['category_slug']) ?>"
               class="faq-filter__btn <?= $activeSlug === $cat['category_slug'] ? 'faq-filter__btn--active' : '' ?>">
                <?= htmlspecialchars($cat['category_name']) ?>
            </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- FAQ 목록 -->
    <?php if (empty($grouped)): ?>
        <div class="faq-empty">등록된 FAQ가 없습니다.</div>
    <?php else: ?>
        <?php foreach ($grouped as $group): ?>
            <?php if ($activeSlug === null && count($grouped) > 1): ?>
                <div class="faq-category-title"><?= htmlspecialchars($group['category_name']) ?></div>
            <?php endif; ?>

            <div class="faq-items">
                <?php foreach ($group['items'] as $item): ?>
                    <div class="faq-item" data-faq-id="<?= $item['faq_id'] ?>">
                        <button type="button" class="faq-item__question" onclick="this.closest('.faq-item').classList.toggle('faq-item--open')">
                            <div class="faq-item__question-inner">
                                <span class="faq-item__q-mark">Q.</span>
                                <span class="faq-item__q-text"><?= htmlspecialchars($item['question']) ?></span>
                            </div>
                            <svg class="faq-item__chevron" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                                 fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="m6 9 6 6 6-6"></path>
                            </svg>
                        </button>
                        <div class="faq-item__answer">
                            <div class="faq-item__answer-inner">
                                <div class="faq-item__answer-content">
                                    <span class="faq-item__a-mark">A.</span>
                                    <div class="faq-item__a-text"><?= $item['answer'] ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
