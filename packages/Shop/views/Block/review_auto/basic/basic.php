<?php
/**
 * Block Skin: review_auto/basic
 *
 * 쇼핑몰 구매후기 자동 진열 기본 스킨 (카드 그리드).
 *
 * MubloItemLayout 적용: 후기 1개 = 아이템 1개 → .mublo-item-layout + <ul><li>
 *   - list 모드: CSS grid 열 배치 (data-pc-cols, data-mo-cols)
 *   - slide 모드: Swiper 슬라이드 (관리자 출력 스타일 설정에 따라)
 *
 * @var array $titleConfig
 * @var string $titlePartial
 * @var \Mublo\Entity\Block\BlockColumn $column
 * @var \Mublo\Core\Rendering\AssetManager|null $assets
 * @var array $items  후기 배열 (ReviewAutoRenderer::formatItem 변환 완료)
 * @var array $config content_config
 */

$items = $items ?? [];
if ($assets) {
    $assets->addCss('/serve/package/Shop/views/Block/review_auto/basic/style.css');
}
?>
<div class="block-review block-review--shop-basic">
    <?php include $titlePartial; ?>

    <div class="block-review__content">
        <?php if (empty($items)): ?>
        <div class="block-empty">
            <p>등록된 구매후기가 없습니다.</p>
        </div>
        <?php else: ?>
        <div class="mublo-item-layout" <?= $column->getLayoutDataAttributes() ?>>
            <ul class="block-review__list">
                <?php foreach ($items as $item):
                    $rating = (int) ($item['rating'] ?? 5);
                    $images = $item['images'] ?? [];
                    $thumb = $item['thumbnail'] ?? '';
                ?>
                <li class="block-review__item">
                    <div class="block-review__card">
                        <!-- 1) 별점 + 작성자 -->
                        <div class="block-review__head">
                            <span class="block-review__rating">
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                <i class="bi bi-star<?= $i <= $rating ? '-fill' : '' ?>"></i>
                                <?php endfor; ?>
                            </span>
                            <?php if (($item['author'] ?? '') !== ''): ?>
                            <span class="block-review__author"><?= e($item['author']) ?></span>
                            <?php endif; ?>
                        </div>

                        <!-- 2) 뱃지 -->
                        <?php if (!empty($item['is_best']) || ($item['review_type'] ?? '') === 'PHOTO'): ?>
                        <div class="block-review__badges">
                            <?php if (!empty($item['is_best'])): ?>
                            <span class="block-review__badge block-review__badge--best">BEST</span>
                            <?php endif; ?>
                            <?php if (($item['review_type'] ?? '') === 'PHOTO'): ?>
                            <span class="block-review__badge block-review__badge--photo">포토</span>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <!-- 3) 사진 -->
                        <?php if (!empty($images)): ?>
                        <div class="block-review__images">
                            <?php foreach (array_slice($images, 0, 3) as $img): ?>
                            <img src="<?= e($img) ?>" alt="후기 이미지" class="block-review__img" loading="lazy">
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>

                        <!-- 4) 내용 -->
                        <div class="block-review__text"><?= e($item['content'] ?? '') ?></div>

                        <!-- 5) 날짜 -->
                        <div class="block-review__date"><?= e($item['date'] ?? '') ?></div>

                        <!-- 상품 -->
                        <?php if (!empty($item['goods_name'])): ?>
                        <a href="<?= e($item['url'] ?? '#') ?>" class="block-review__product">
                            <?php if ($thumb): ?>
                            <img src="<?= e($thumb) ?>" alt="" class="block-review__product-thumb" loading="lazy">
                            <?php endif; ?>
                            <span class="block-review__product-name"><?= e($item['goods_name']) ?></span>
                        </a>
                        <?php endif; ?>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
    </div>
</div>
