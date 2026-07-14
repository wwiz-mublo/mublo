<?php
/**
 * Block Skin: banner/basic
 *
 * 배너 기본 스킨
 *
 * MubloItemLayout 적용:
 *   배너 1개 = 아이템 1개 → .mublo-item-layout + <ul><li> 구조
 *   - list 모드: CSS grid 열 배치 (data-pc-cols, data-mo-cols)
 *   - slide 모드: Swiper 슬라이드 (관리자 설정에 따라)
 *   - PC/MO 이미지: CSS display 전환 (picture 엘리먼트 미사용)
 *
 * @var array $titleConfig 타이틀 설정
 * @var string $titlePartial 타이틀 파셜 경로
 * @var array $contentConfig 콘텐츠 설정
 * @var \Mublo\Entity\Block\BlockColumn $column 블록 칸 엔티티
 * @var string $skinDir 스킨 디렉토리 경로
 * @var \Mublo\Core\Rendering\AssetManager|null $assets 에셋 매니저
 * @var array $items 배너 아이템 배열
 */

$items = $items ?? [];

if ($assets) {
    $assets->addCss('/serve/plugin/Banner/views/Block/banner/basic/style.css');
}
?>
<div class="block-banner block-banner--basic">
    <?php include $titlePartial; ?>

    <div class="block-banner__content block-body">
        <?php if (empty($items)): ?>
        <div class="block-empty">
            <p>등록된 배너가 없습니다.</p>
        </div>
        <?php else: ?>
        <div class="mublo-item-layout" <?= $column->getLayoutDataAttributes() ?>>
            <ul class="block-banner__list">
                <?php foreach ($items as $item):
                    $pcImage = htmlspecialchars($item['pc_image_url'] ?? '');
                    $moImage = !empty($item['mo_image_url']) ? htmlspecialchars($item['mo_image_url']) : '';
                    $hasMo = ($moImage && $moImage !== $pcImage);
                    $alt = htmlspecialchars($item['title'] ?? '');
                    $linkUrl = !empty($item['link_url']) ? htmlspecialchars($item['link_url']) : null;
                    $linkTarget = htmlspecialchars($item['link_target'] ?? '_self');
                ?>
                <li class="block-banner__item<?= $hasMo ? ' block-banner__item--has-mo' : '' ?>">
                    <div class="block-banner__inner">
                        <?php if ($linkUrl): ?>
                        <a href="<?= $linkUrl ?>" target="<?= $linkTarget ?>"<?= $linkTarget === '_blank' ? ' rel="noopener noreferrer"' : '' ?> class="block-banner__link">
                        <?php endif; ?>

                            <div class="block-banner__image block-banner__image--pc">
                                <img src="<?= $pcImage ?>" alt="<?= $alt ?>" class="block-banner__img" loading="lazy">
                            </div>
                            <?php if ($hasMo): ?>
                            <div class="block-banner__image block-banner__image--mo">
                                <img src="<?= $moImage ?>" alt="<?= $alt ?>" class="block-banner__img" loading="lazy">
                            </div>
                            <?php endif; ?>

                        <?php if ($linkUrl): ?>
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
