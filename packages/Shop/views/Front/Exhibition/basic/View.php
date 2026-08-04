<?php
/**
 * 기획전 상세 (프론트 기본 스킨)
 *
 * @var string $pageTitle
 * @var array  $exhibition
 * @var array  $items          연결된 상품/카테고리 아이템(원본)
 * @var array  $products        연결 상품 (ProductPresenter 변환 완료 — 상품 목록과 동일 규격)
 * @var array  $wishlistedIds   회원 찜 상품 goods_id 목록
 */
$exhibition = $exhibition ?? [];
$products   = $products ?? [];
// ── 뷰 데이터 정리 ─────────────────────────────────────────────
$bannerImage = $exhibition['banner_image'] ?? '';
$title       = $exhibition['title'] ?? '';
$description  = trim((string) ($exhibition['description'] ?? ''));

$startDate = !empty($exhibition['start_date']) ? substr($exhibition['start_date'], 0, 10) : '';
$endDate   = !empty($exhibition['end_date'])   ? substr($exhibition['end_date'], 0, 10)   : '';
$period    = trim($startDate . (($startDate && $endDate) ? ' ~ ' : '') . $endDate);
if ($period === '') {
    $period = '상시';  // 시작·종료일 모두 미설정 시 상시 진행
}

// 찜 상태 조회용 집합
$wishlistedSet = array_flip(array_map('intval', $wishlistedIds ?? []));

// 상품 목록과 동일한 카드 스타일 재사용
$this->assets->addCss('/serve/package/Shop/views/Front/Exhibition/basic/_assets/css/exhibition-view.css');
$this->assets->addCss('/serve/package/Shop/views/Front/Product/basic/_assets/css/product-list.css');
?>

<article class="shop-exhibition-view">

    <header class="shop-exhibition-view__header">
        <?php if ($bannerImage !== ''): ?>
        <div class="shop-exhibition-view__banner">
            <img src="<?= e($bannerImage) ?>" alt="<?= e($title) ?>">
        </div>
        <?php endif; ?>

        <h1 class="shop-exhibition-view__title"><?= e($title) ?></h1>

        <?php if ($description !== ''): ?>
        <div class="shop-exhibition-view__desc"><?= e($description) ?></div>
        <?php endif; ?>

        <p class="shop-exhibition-view__period">
            <i class="bi bi-calendar3"></i><span><?= e($period) ?></span>
        </p>
    </header>

    <section class="shop-exhibition-view__products">
        <?php if (empty($products)): ?>
        <div class="shop-exhibition-view__empty">
            <i class="bi bi-bag"></i>
            <p>등록된 상품이 없습니다.</p>
        </div>
        <?php else: ?>
        <div class="spl spl-grid">
            <?php foreach ($products as $product): ?>
            <div class="spl-card <?= ($product['is_soldout'] ?? false) ? 'spl-card--soldout' : '' ?>">
                <a href="<?= $product['url'] ?>" class="spl-card__thumb">
                    <?php if (!empty($product['main_image_url'])): ?>
                        <img src="<?= e($product['main_image_url']) ?>"
                             class="spl-card__img"
                             alt="<?= $product['goods_name_safe'] ?>"
                             loading="lazy">
                    <?php else: ?>
                        <div class="spl-card__img spl-card__img--empty"><i class="bi bi-image"></i></div>
                    <?php endif; ?>

                    <?php if ($product['is_soldout'] ?? false): ?>
                        <div class="spl-card__soldout">SOLD OUT</div>
                    <?php endif; ?>

                    <?php if (!empty($product['badges'])): ?>
                        <div class="spl-card__badges">
                            <?php foreach ($product['badges'] as $badge): ?>
                                <?php if ($badge === 'new'): ?>
                                    <span class="spl-badge spl-badge--new">NEW</span>
                                <?php elseif ($badge === 'sale'): ?>
                                    <span class="spl-badge spl-badge--sale">SALE</span>
                                <?php elseif ($badge === 'soldout'): ?>
                                <?php else: ?>
                                    <span class="spl-badge spl-badge--custom"><?= $badge ?></span>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (($product['has_discount'] ?? false) && !($product['is_soldout'] ?? false)): ?>
                        <span class="spl-card__discount-tag"><?= $product['discount_percent'] ?>%</span>
                    <?php endif; ?>

                    <?php $isWished = isset($wishlistedSet[(int) $product['goods_id']]); ?>
                    <button type="button"
                            class="spl-card__wish<?= $isWished ? ' spl-card__wish--active' : '' ?>"
                            title="찜하기"
                            data-goods-id="<?= $product['goods_id'] ?>">
                        <i class="bi <?= $isWished ? 'bi-heart-fill' : 'bi-heart' ?>"></i>
                    </button>
                </a>

                <div class="spl-card__body">
                    <a href="<?= $product['url'] ?>" class="spl-card__name"><?= $product['goods_name_safe'] ?></a>

                    <div class="spl-card__price">
                        <?php if ($product['has_discount'] ?? false): ?>
                            <del class="spl-card__price-origin"><?= $product['display_price_formatted'] ?>원</del>
                        <?php endif; ?>
                        <span class="spl-card__price-current"><?= $product['sales_price_formatted'] ?>원</span>
                    </div>

                    <?php if ($product['has_reward'] ?? false): ?>
                        <div class="spl-card__reward">
                            <i class="bi bi-coin"></i> <?= number_format($product['point_amount']) ?>P 적립
                        </div>
                    <?php endif; ?>

                    <?php if ($product['has_reviews'] ?? false): ?>
                        <div class="spl-card__review">
                            <span class="spl-card__stars">
                                <?php
                                $rating = $product['average_rating'] ?? 0;
                                for ($i = 1; $i <= 5; $i++):
                                    if ($i <= floor($rating)): ?>
                                        <i class="bi bi-star-fill"></i>
                                    <?php elseif ($i - $rating < 1 && $i - $rating > 0): ?>
                                        <i class="bi bi-star-half"></i>
                                    <?php else: ?>
                                        <i class="bi bi-star"></i>
                                    <?php endif;
                                endfor; ?>
                            </span>
                            <span class="spl-card__review-count">(<?= $product['review_count_formatted'] ?>)</span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </section>

    <footer class="shop-exhibition-view__footer">
        <a href="/shop/exhibitions" class="shop-exhibition-view__back">
            <i class="bi bi-arrow-left"></i> 기획전 목록
        </a>
    </footer>

</article>

<script src="<?= asset('/serve/package/Shop/views/Front/Product/basic/_assets/js/product-list.js') ?>"></script>
