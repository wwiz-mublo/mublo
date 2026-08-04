<?php
/**
 * Block Skin: product/basic
 *
 * 상품 기본 카드 그리드 스킨
 *
 * MubloItemLayout 적용:
 *   상품 1개 = 아이템 1개 → .mublo-item-layout + <ul><li> 구조
 *   - list 모드: CSS grid 열 배치 (data-pc-cols, data-mo-cols)
 *   - slide 모드: Swiper 슬라이드 (관리자 설정에 따라)
 *
 * @var array $titleConfig 타이틀 설정
 * @var string $titlePartial 타이틀 파셜 경로
 * @var array $contentConfig 콘텐츠 설정
 * @var \Mublo\Contract\Block\BlockColumnView $column 블록 칸 엔티티
 * @var string $skinDir 스킨 디렉토리 경로
 * @var \Mublo\Core\Rendering\AssetManager|null $assets 에셋 매니저
 * @var array $items 상품 아이템 배열 (ProductPresenter 변환 완료)
 * @var array $config content_config
 */

$items = $items ?? [];
$config = $config ?? [];
$showPrice = (bool) ($config['show_price'] ?? true);
$showReward = (bool) ($config['show_reward'] ?? false);
$showBadge = (bool) ($config['show_badge'] ?? true);

if ($assets) {
    $assets->addCss('/serve/package/Shop/views/Block/product/basic/style.css');
}
?>
<div class="block-product block-product--shop-basic">
    <?php include $titlePartial; ?>

    <div class="block-product__content">
        <?php if (empty($items)): ?>
        <div class="block-empty">
            <p>등록된 상품이 없습니다.</p>
        </div>
        <?php else: ?>
        <div class="mublo-item-layout" <?= $column->getLayoutDataAttributes() ?>>
            <ul class="block-product__list">
                <?php foreach ($items as $item):
                    $isSoldout = $item['is_soldout'] ?? false;
                    $imageUrl = $item['main_image_url'] ?? '';
                    $thumbUrl = $item['main_thumbnail_url'] ?? $imageUrl;
                    $name = $item['goods_name_safe'] ?? '';
                    $url = $item['url'] ?? '#';
                ?>
                <li class="block-product__item<?= $isSoldout ? ' block-product__item--soldout' : '' ?>">
                    <a href="<?= $url ?>" class="block-product__card">
                        <div class="block-product__image-wrap">
                            <?php if ($thumbUrl): ?>
                                <img src="<?= htmlspecialchars($thumbUrl) ?>"
                                     alt="<?= $name ?>"
                                     class="block-product__image"
                                     loading="lazy">
                            <?php else: ?>
                                <div class="block-product__image block-product__image--placeholder">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                        <rect x="3" y="3" width="18" height="18" rx="2"/>
                                        <circle cx="8.5" cy="8.5" r="1.5"/>
                                        <path d="m21 15-5-5L5 21"/>
                                    </svg>
                                </div>
                            <?php endif; ?>

                            <?php if ($isSoldout): ?>
                                <div class="block-product__overlay">품절</div>
                            <?php endif; ?>

                            <?php if ($showBadge && !empty($item['badges'])): ?>
                                <div class="block-product__badges">
                                    <?php foreach ($item['badges'] as $badge): ?>
                                        <?php if ($badge === 'new'): ?>
                                            <span class="block-product__badge block-product__badge--new">NEW</span>
                                        <?php elseif ($badge === 'sale'): ?>
                                            <span class="block-product__badge block-product__badge--sale">SALE</span>
                                        <?php elseif ($badge === 'soldout'): ?>
                                        <?php else: ?>
                                            <span class="block-product__badge block-product__badge--custom"><?= $badge ?></span>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="block-product__info">
                            <div class="block-product__name"><?= $name ?></div>

                            <?php if ($showPrice): ?>
                                <div class="block-product__price">
                                    <?php if ($item['has_discount'] ?? false): ?>
                                        <span class="block-product__discount"><?= $item['discount_percent'] ?>%</span>
                                    <?php endif; ?>
                                    <span class="block-product__current-price"><?= $item['sales_price_formatted'] ?>원</span>
                                    <?php if ($item['has_discount'] ?? false): ?>
                                        <del class="block-product__origin-price"><?= $item['display_price_formatted'] ?>원</del>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($showReward && ($item['has_reward'] ?? false)): ?>
                                <div class="block-product__reward">
                                    <?= number_format($item['point_amount'] ?? 0) ?>P 적립
                                </div>
                            <?php endif; ?>
                        </div>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
    </div>
</div>
