<?php
/**
 * 상품 상세 (프론트)
 *
 * @var array|null $product 상품 데이터 (ProductPresenter::toView() 변환 완료)
 * @var string|null $message 에러 메시지 (404 등)
 *
 * [Presenter 제공 필드]
 * 기본: goods_name_safe, url, goods_id, option_mode
 * 가격: sales_price, sales_price_formatted, display_price_formatted, origin_price_formatted
 *       discount_percent, discount_amount_formatted, has_discount
 *       point_amount, point_amount_formatted, has_reward
 * 이미지: images[], main_image_url, main_thumbnail_url
 * 옵션: options[], combos[] (JS에서 소비)
 * 상세: details[]
 * 상태: is_soldout, stock_label, is_new, badges[]
 * 통계: hit_formatted, review_count, average_rating_formatted, has_reviews
 *
 * 구매후기적립: reward_review_formatted, has_reward_review
 * 태그: tags_array[]
 */

$this->assets->addCss('/serve/package/Shop/views/Front/Product/basic/_assets/css/product-view.css');
?>

<?php if (empty($product)): ?>
<div class="shop-product-view shop-product-view--error">
    <div class="shop-product-view__error-icon" aria-hidden="true">
        <svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="10"/>
            <line x1="12" y1="8" x2="12" y2="12"/>
            <line x1="12" y1="16" x2="12.01" y2="16"/>
        </svg>
    </div>
    <h2 class="shop-product-view__error-title"><?= htmlspecialchars($message ?? '상품을 찾을 수 없습니다.') ?></h2>
    <p class="shop-product-view__error-desc">상품이 삭제되었거나 판매가 중지되었을 수 있습니다.</p>
    <a href="/shop/products" class="shop-product-view__error-btn">상품 목록으로 돌아가기</a>
</div>
<?php return; endif; ?>

<div class="shop-product-view">
    <nav class="shop-product-view__breadcrumb" aria-label="카테고리 경로">
        <?php if (!empty($categoryPath)): ?>
            <a href="/shop/products">쇼핑</a>
            <?php foreach ($categoryPath as $crumb): ?>
                <i class="bi bi-chevron-right shop-product-view__breadcrumb-sep" aria-hidden="true"></i>
                <a href="<?= htmlspecialchars($crumb['link']) ?>"><?= htmlspecialchars($crumb['label']) ?></a>
            <?php endforeach; ?>
        <?php endif; ?>
    </nav>
    <div class="shop-product-view__top">
        <!-- ========== 갤러리 ========== -->
        <div class="shop-product-view__gallery">
            <div class="shop-product-view__main-image">
                <?php if ($product['main_image_url']): ?>
                    <img src="<?= $product['main_image_url'] ?>"
                         id="spv-main-img"
                         alt="<?= $product['goods_name_safe'] ?>">
                    <span class="shop-product-view__zoom-hint" aria-hidden="true">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>
                        </svg>
                    </span>
                <?php else: ?>
                    <div class="shop-product-view__no-image">
                        <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                            <rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/>
                        </svg>
                    </div>
                <?php endif; ?>

                <!-- 배지 -->
                <?php if (!empty($product['badges'])): ?>
                    <div class="shop-product-view__badges">
                        <?php foreach ($product['badges'] as $badge): ?>
                            <?php
                                $cls = match ($badge) {
                                    'new' => 'shop-badge--new',
                                    'sale' => 'shop-badge--sale',
                                    'soldout' => 'shop-badge--soldout',
                                    default => 'shop-badge--custom',
                                };
                                $label = match ($badge) {
                                    'new' => 'NEW',
                                    'sale' => $product['discount_percent'] . '%',
                                    'soldout' => '품절',
                                    default => $badge,
                                };
                            ?>
                            <span class="shop-badge <?= $cls ?>"><?= htmlspecialchars($label) ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (count($product['images'] ?? []) > 1): ?>
                <div class="shop-product-view__thumbs">
                    <?php foreach ($product['images'] as $img): ?>
                        <button type="button"
                                class="shop-product-view__thumb <?= $img['is_main'] ? 'shop-product-view__thumb--active' : '' ?>"
                                data-image="<?= htmlspecialchars($img['image_url']) ?>">
                            <img src="<?= htmlspecialchars($img['thumbnail_url'] ?: $img['image_url']) ?>"
                                 alt="" loading="lazy">
                        </button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- ========== 상품 정보 ========== -->
        <div class="shop-product-view__info">
            <h1 class="shop-product-view__name"><?= $product['goods_name_safe'] ?></h1>

            <?php if (!empty($product['goods_manufacturer_safe'])): ?>
                <p class="shop-product-view__manufacturer"><?= $product['goods_manufacturer_safe'] ?></p>
            <?php endif; ?>

            <!-- 구매후기 요약 -->
            <?php if ($product['has_reviews'] ?? false): ?>
                <div class="shop-product-view__rating">
                    <span class="shop-product-view__stars"><?= str_repeat('★', (int) round($product['average_rating'])) . str_repeat('☆', 5 - (int) round($product['average_rating'])) ?></span>
                    <span class="shop-product-view__rating-text"><?= $product['average_rating_formatted'] ?></span>
                    <a href="#spv-reviews" class="shop-product-view__review-link">구매후기 <?= $product['review_count_formatted'] ?>건</a>
                </div>
            <?php endif; ?>

            <!-- 가격 -->
            <div class="shop-product-view__price-area">
                <div class="shop-product-view__price-row">
                    <div class="shop-product-view__price-final">
                        <span class="shop-product-view__price-value" id="spv-base-price"><?= $product['sales_price_formatted'] ?></span>
                        <span class="shop-product-view__price-unit">원</span>
                    </div>
                    <?php if ($product['has_discount']): ?>
                        <div class="shop-product-view__price-original">
                            <del><?= $product['display_price_formatted'] ?>원</del>
                            <span class="shop-product-view__discount-rate">-<?= $product['discount_percent'] ?>%</span>
                        </div>
                    <?php endif; ?>
                </div>
                <?php if ($product['has_reward']): ?>
                    <p class="shop-product-view__reward">
                        <span class="shop-product-view__reward-icon">P</span>
                        <?= $product['point_amount_formatted'] ?>원 적립
                    </p>
                <?php endif; ?>
            </div>

            <!-- 상품 정보 테이블 -->
            <dl class="shop-product-view__meta">
                <?php if (!empty($product['goods_origin_safe'])): ?>
                    <div class="shop-product-view__meta-row">
                        <dt>원산지</dt><dd><?= $product['goods_origin_safe'] ?></dd>
                    </div>
                <?php endif; ?>
                <?php if ($product['stock_label']): ?>
                    <div class="shop-product-view__meta-row">
                        <dt>재고</dt><dd class="<?= $product['is_soldout'] ? 'shop-product-view__soldout' : '' ?>"><?= $product['stock_label'] ?></dd>
                    </div>
                <?php endif; ?>
                <?php if ($product['allowed_coupon_label']): ?>
                    <div class="shop-product-view__meta-row">
                        <dt>쿠폰</dt><dd><?= $product['allowed_coupon_label'] ?></dd>
                    </div>
                <?php endif; ?>
                <?php if ($product['has_reward_review']): ?>
                    <div class="shop-product-view__meta-row">
                        <dt>구매후기적립</dt><dd><?= $product['reward_review_formatted'] ?>원</dd>
                    </div>
                <?php endif; ?>
            </dl>

            <!-- 옵션 영역 (ShopProductOption.js가 렌더링) -->
            <div id="spv-option-area" class="shop-product-view__options"></div>

            <!-- 총 금액 -->
            <?php $hasOptions = ($product['option_mode'] ?? 'NONE') !== 'NONE'; ?>
            <div class="shop-product-view__total">
                <span class="shop-product-view__total-label">총 상품금액</span>
                <span class="shop-product-view__total-qty">(<span id="spv-total-qty"><?= $hasOptions ? '0' : '1' ?></span>개)</span>
                <span class="shop-product-view__total-price">
                    <strong id="spv-total-price"><?= $hasOptions ? '0' : $product['sales_price_formatted'] ?></strong>원
                </span>
            </div>

            <!-- 구매 버튼 -->
            <div class="shop-product-view__actions">
                <?php if ($product['is_soldout']): ?>
                    <button class="shop-product-view__btn shop-product-view__btn--soldout" disabled>품절</button>
                <?php else: ?>
                    <button type="button"
                            class="shop-product-view__btn shop-product-view__btn--cart"
                            id="spv-btn-cart">장바구니</button>
                    <button type="button"
                            class="shop-product-view__btn shop-product-view__btn--buy"
                            id="spv-btn-buy">바로구매</button>
                <?php endif; ?>
                <button type="button"
                        class="shop-product-view__btn shop-product-view__btn--wish<?= !empty($isWishlisted) ? ' shop-product-view__btn--wished' : '' ?>"
                        id="spv-btn-wish"
                        aria-label="찜하기"><?= !empty($isWishlisted) ? '♥' : '♡' ?></button>
            </div>

            <!-- 태그 -->
            <?php if (!empty($product['tags_array'])): ?>
                <div class="shop-product-view__tags">
                    <?php foreach ($product['tags_array'] as $tag): ?>
                        <a href="/shop/products?keyword=<?= urlencode($tag) ?>" class="shop-product-view__tag">#<?= htmlspecialchars($tag) ?></a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ========== 상세 탭 ========== -->
    <?php $tabs = !empty($tabs) ? $tabs : [['type' => 'detail', 'key' => 'detail', 'label' => '상세설명']]; ?>
    <div class="shop-product-view__tabs" id="spv-tabs">
        <div class="shop-product-view__tab-nav">
            <?php foreach ($tabs as $i => $tab): ?>
            <button type="button"
                    class="shop-product-view__tab-btn<?= $i === 0 ? ' shop-product-view__tab-btn--active' : '' ?>"
                    data-tab="<?= htmlspecialchars($tab['key']) ?>"<?= $tab['type'] === 'review' ? ' id="spv-tab-reviews"' : '' ?>>
                <?= htmlspecialchars($tab['label']) ?>
                <?php if (!empty($tab['has'])): ?>
                    <span class="shop-product-view__tab-count"><?= $tab['count'] ?></span>
                <?php endif; ?>
            </button>
            <?php endforeach; ?>
        </div>

        <?php foreach ($tabs as $i => $tab): $activeCls = $i === 0 ? ' shop-product-view__tab-content--active' : ''; ?>
            <?php if ($tab['type'] === 'detail'): ?>
        <div class="shop-product-view__tab-content<?= $activeCls ?>" data-tab-content="detail">
            <?php if (!empty($product['details'])): ?>
                <?php foreach ($product['details'] as $detail): ?>
                    <?php if (count($product['details']) > 1): ?>
                        <h3 class="shop-product-view__detail-title"><?= htmlspecialchars($detail['detail_type'] ?? '상세설명') ?></h3>
                    <?php endif; ?>
                    <div class="shop-product-view__detail-content">
                        <?= $detail['detail_value'] ?? '' ?>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="shop-product-view__empty">등록된 상세설명이 없습니다.</p>
            <?php endif; ?>
            <?php $productNotice = $product['product_notice'] ?? null; ?>
            <?php if (is_array($productNotice) && !empty($productNotice['fields'])): ?>
                <section class="shop-product-view__product-notice">
                    <h3 class="shop-product-view__detail-title">상품정보제공고시</h3>
                    <div class="shop-product-view__product-notice-wrap">
                        <table class="shop-product-view__product-notice-table">
                            <tbody>
                                <?php foreach ($productNotice['fields'] as $field): ?>
                                    <?php
                                        $fieldCode = (string) ($field['field_code'] ?? '');
                                        if ($fieldCode === '') continue;
                                        $fieldValue = trim((string) ($productNotice['values'][$fieldCode] ?? ''));
                                    ?>
                                    <tr>
                                        <th scope="row"><?= htmlspecialchars((string) ($field['label'] ?? ''), ENT_QUOTES, 'UTF-8') ?></th>
                                        <td><?= nl2br(htmlspecialchars($fieldValue !== '' ? $fieldValue : '상세설명 참조', ENT_QUOTES, 'UTF-8')) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            <?php endif; ?>
        </div>
            <?php elseif ($tab['type'] === 'template'): ?>
        <div class="shop-product-view__tab-content<?= $activeCls ?>" data-tab-content="<?= htmlspecialchars($tab['key']) ?>">
            <?php foreach ($tab['items'] as $item): ?>
                <?php if (count($tab['items']) > 1 && ($item['subject'] ?? '') !== ''): ?>
                    <h3 class="shop-product-view__detail-title"><?= htmlspecialchars($item['subject']) ?></h3>
                <?php endif; ?>
                <div class="shop-product-view__detail-content"><?= $item['content'] ?? '' ?></div>
            <?php endforeach; ?>
        </div>
            <?php elseif ($tab['type'] === 'review'): ?>
        <div class="shop-product-view__tab-content<?= $activeCls ?>" data-tab-content="reviews" id="spv-reviews">
            <p class="shop-product-view__empty">구매후기를 불러오는 중...</p>
        </div>
            <?php elseif ($tab['type'] === 'inquiry'): ?>
        <div class="shop-product-view__tab-content<?= $activeCls ?>" data-tab-content="inquiry" id="spv-inquiry">
            <p class="shop-product-view__empty">상품문의를 불러오는 중...</p>
        </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
</div>

<!--
  ShopProductOption.js: 아래 인라인 <script>의 `new ShopProductOption(...)`보다 먼저 로드돼야 한다.
  $this->assets->addJs()는 AssetManager가 body 끝에 렌더하므로 타이밍이 늦어, asset() 헬퍼로 직접 로드.
-->
<script src="<?= asset('/serve/package/Shop/assets/js/ShopProductOption.js') ?>"></script>

<script type="application/json" id="spv-product-data"><?= json_encode([
    'goodsId'    => (int) $product['goods_id'],
    'basePrice'  => (int) $product['sales_price'],
    'optionMode' => $product['option_mode'] ?? 'NONE',
    'options'    => $product['options'] ?? [],
    'combos'     => $product['combos'] ?? [],
], JSON_UNESCAPED_UNICODE) ?></script>
<script src="<?= asset('/serve/package/Shop/views/Front/Product/basic/_assets/js/product-view.js') ?>"></script>
