<?php
/**
 * Block Skin: image/basic
 *
 * 이미지 기본 스킨
 *
 * MubloItemLayout 적용:
 *   이미지 1장 = 아이템 1개 → .mublo-item-layout + <ul><li> 구조
 *   - list 모드: CSS grid 열 배치 (data-pc-cols, data-mo-cols)
 *   - slide 모드: Swiper 슬라이드 (data-pc-style="slide")
 *   - 설정값: $column->getPcStyle(), getMoStyle(), getPcCols(), getMoCols()
 *
 * @var array $titleConfig 타이틀 설정
 * @var string $titlePartial 타이틀 파셜 경로
 * @var array $contentConfig 콘텐츠 설정
 * @var \Mublo\Entity\Block\BlockColumn $column 블록 칸 엔티티
 * @var array $images 이미지 배열 [{pc_image, mo_image, link_url, link_target, alt}, ...]
 */

$images = $images ?? [];
?>
<div class="block-image block-image--basic">
    <?php include $titlePartial; ?>

    <!-- 콘텐츠 영역 -->
    <div class="block-image__content block-body">
        <?php if (empty($images)): ?>
        <div class="block-empty">
            <p>이미지가 설정되지 않았습니다.</p>
        </div>
        <?php else: ?>
        <div class="mublo-item-layout" <?= $column->getLayoutDataAttributes() ?>>
            <ul class="block-image__list">
                <?php foreach ($images as $image): ?>
                <li class="block-image__item">
                    <?php
                    $pcImage = htmlspecialchars($image['pc_image']);
                    $moImage = htmlspecialchars($image['mo_image']);
                    $alt = htmlspecialchars($image['alt']);
                    $linkUrl = $image['link_url'] ? htmlspecialchars($image['link_url']) : null;
                    $linkTarget = htmlspecialchars($image['link_target']);

                    // 이미지 HTML
                    if ($moImage !== $pcImage) {
                        $imageHtml = <<<HTML
<picture class="block-image__picture">
    <source media="(max-width: 767px)" srcset="{$moImage}">
    <source media="(min-width: 768px)" srcset="{$pcImage}">
    <img src="{$pcImage}" alt="{$alt}" class="block-image__img" loading="lazy">
</picture>
HTML;
                    } else {
                        $imageHtml = '<img src="' . $pcImage . '" alt="' . $alt . '" class="block-image__img" loading="lazy">';
                    }

                    // 링크 래핑
                    if ($linkUrl) {
                        $rel = $linkTarget === '_blank' ? ' rel="noopener noreferrer"' : '';
                        echo '<a href="' . $linkUrl . '" target="' . $linkTarget . '"' . $rel . ' class="block-image__link">' . $imageHtml . '</a>';
                    } else {
                        echo $imageHtml;
                    }
                    ?>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
    </div>
</div>
