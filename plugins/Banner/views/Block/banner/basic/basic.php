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
 * 슬라이드 컨트롤 (콘텐츠 설정, 배너 2장 이상일 때만):
 *   - show_navigation: 이미지 좌우 가운데 이전/다음 버튼
 *   - show_pagination: 아래쪽 점 인디케이터
 *   값이 없는(설정을 저장한 적 없는) 블록은 예전처럼 표시하지 않는다.
 *   버튼·점은 Swiper 가 켜진 slide 모드에서만 보인다 (list 모드 기기에서는 CSS 로 숨김).
 *
 * @var array $titleConfig 타이틀 설정
 * @var string $titlePartial 타이틀 파셜 경로
 * @var array $contentConfig 콘텐츠 설정
 * @var \Mublo\Contract\Block\BlockColumnView $column 블록 칸 엔티티
 * @var string $skinDir 스킨 디렉토리 경로
 * @var \Mublo\Core\Rendering\AssetManager|null $assets 에셋 매니저
 * @var array $items 배너 아이템 배열
 */

$items = $items ?? [];

if ($assets) {
    $assets->addCss('/serve/plugin/Banner/views/Block/banner/basic/style.css');
}

// 체크박스 값('1'/'0')을 켬/끔으로 — 값이 없으면 끔 (기존 블록 동작 유지)
$isOn = static fn ($value): bool => in_array($value, [true, 1, '1', 'true', 'on'], true);
$contentConfig = is_array($contentConfig ?? null) ? $contentConfig : [];

$hasMultiple = count($items) > 1;
$showNavigation = $hasMultiple && $isOn($contentConfig['show_navigation'] ?? null);
$showPagination = $hasMultiple && $isOn($contentConfig['show_pagination'] ?? null);

$bannerId = 'block-banner-' . preg_replace('/[^A-Za-z0-9_-]/', '', $column->getRenderKey());
$swiperOptions = [];
if ($showNavigation) {
    $swiperOptions['navigation'] = [
        'prevEl' => '#' . $bannerId . '-prev',
        'nextEl' => '#' . $bannerId . '-next',
    ];
}
if ($showPagination) {
    $swiperOptions['pagination'] = [
        'el' => '#' . $bannerId . '-pagination',
        'clickable' => true,
    ];
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
        <div class="mublo-item-layout" <?= $column->getLayoutDataAttributes() ?><?php if ($swiperOptions !== []): ?>
             data-swiper="<?= htmlspecialchars(json_encode($swiperOptions, JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?>"<?php endif; ?>>
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

            <?php if ($showNavigation): ?>
            <button type="button" class="block-banner__nav block-banner__nav--prev" id="<?= htmlspecialchars($bannerId) ?>-prev" aria-label="이전 배너">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 6l-6 6 6 6"></path></svg>
            </button>
            <button type="button" class="block-banner__nav block-banner__nav--next" id="<?= htmlspecialchars($bannerId) ?>-next" aria-label="다음 배너">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6"></path></svg>
            </button>
            <?php endif; ?>
            <?php if ($showPagination): ?>
            <div class="block-banner__pagination" id="<?= htmlspecialchars($bannerId) ?>-pagination"></div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
