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
 * @var \Mublo\Contract\Block\BlockColumnView $column 블록 칸 엔티티
 * @var array $images 이미지 배열 [{pc_image, mo_image, link_url, link_target, alt, title, desc}, ...]
 *                    title·desc 는 선택 입력 — 비어 있으면 캡션 자체가 생기지 않아
 *                    기존에 만들어 둔 이미지 행의 화면은 그대로다.
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
                    $linkUrl = $image['link_url'] ? htmlspecialchars($image['link_url']) : null;
                    $linkTarget = htmlspecialchars($image['link_target']);

                    // 제목·설명은 선택 입력 — 비어 있으면 아래에서 아무것도 출력하지 않는다.
                    $title = (string) ($image['title'] ?? '');
                    $desc = (string) ($image['desc'] ?? '');

                    // alt 를 따로 안 넣었어도 제목이 있으면 그걸 대체 텍스트로 쓴다.
                    // 화면 낭독기에 "이미지"만 읽히는 것보다 낫다.
                    $alt = htmlspecialchars($image['alt'] !== '' ? $image['alt'] : $title);

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

                    // 제목·설명 — 하나라도 있을 때만 캡션 블록이 생긴다.
                    $captionHtml = '';
                    if ($title !== '' || $desc !== '') {
                        $captionHtml = '<div class="block-image__caption">';
                        if ($title !== '') {
                            $captionHtml .= '<strong class="block-image__title">'
                                . htmlspecialchars($title) . '</strong>';
                        }
                        if ($desc !== '') {
                            $captionHtml .= '<span class="block-image__desc">'
                                . nl2br(htmlspecialchars($desc)) . '</span>';
                        }
                        $captionHtml .= '</div>';
                    }

                    // 링크 래핑 — 캡션이 없으면 예전과 똑같이 이미지만 감싼다.
                    // 캡션이 있으면 글자까지 눌리는 게 자연스러워 함께 감싼다.
                    if ($linkUrl) {
                        $rel = $linkTarget === '_blank' ? ' rel="noopener noreferrer"' : '';
                        echo '<a href="' . $linkUrl . '" target="' . $linkTarget . '"' . $rel . ' class="block-image__link">'
                            . $imageHtml . $captionHtml . '</a>';
                    } else {
                        echo $imageHtml . $captionHtml;
                    }
                    ?>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
    </div>
</div>
