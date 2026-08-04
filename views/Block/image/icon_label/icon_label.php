<?php
/**
 * Block Skin: image/icon_label — 아이콘 + 제목
 *
 * 동그란 아이콘 아래에 굵은 한 줄. 바로가기 묶음·카테고리 진입구처럼
 * **그림만으로는 어디로 가는지 모르는** 자리에 쓴다. 설명은 선택이라
 * 제목만 넣으면 제목만 나온다.
 *
 * 열 수는 칸 편집의 [출력 스타일]을 그대로 따른다(data-pc-cols/mo-cols).
 * skin.json 의 recommended_cols 로 스킨을 고르는 순간 PC 6 / 모바일 3 이
 * 자동으로 잡히지만, 운영자가 바꾸면 그 값이 이긴다.
 *
 * 정사각으로 잘라 원형으로 깎으므로 원본이 세로로 길어도 줄이 맞는다.
 *
 * 링크는 렌더러가 정규화한 값이다. 여기서는 이스케이프만 한다.
 *
 * @var array  $images 이미지 배열 [{pc_image, mo_image, link_url, link_target, alt, title, desc}]
 * @var string $titlePartial 타이틀 파셜 경로
 * @var \Mublo\Contract\Block\BlockColumnView $column 블록 칸 엔티티
 * @var \Mublo\Core\Rendering\AssetManager|null $assets
 */

$images = $images ?? [];

if ($assets ?? null) {
    $assets->addCss('/serve/block/image/icon_label/style.css');
}

$e = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<div class="block-image block-image--icon-label">
    <?php include $titlePartial; ?>

    <div class="block-image__content block-body">
        <?php if ($images === []): ?>
            <div class="block-empty"><p>이미지가 설정되지 않았습니다.</p></div>
        <?php else: ?>
        <div class="mublo-item-layout" <?= $column->getLayoutDataAttributes() ?>>
            <ul class="block-image__list icon-label">
                <?php foreach ($images as $image): ?>
                    <?php
                    $title = (string) ($image['title'] ?? '');
                    $desc = (string) ($image['desc'] ?? '');
                    $link = (string) ($image['link_url'] ?? '');
                    // alt 를 안 넣었으면 제목이 대신한다 — 낭독기에 이름은 있어야 한다.
                    $alt = ((string) ($image['alt'] ?? '')) !== '' ? $image['alt'] : $title;
                    $target = (string) ($image['link_target'] ?? '_self');
                    ?>
                <li class="block-image__item icon-label__item">
                    <?php if ($link !== ''): ?>
                    <a class="icon-label__link" href="<?= $e($link) ?>" target="<?= $e($target) ?>"
                       <?= $target === '_blank' ? 'rel="noopener noreferrer"' : '' ?>>
                    <?php endif; ?>

                    <span class="icon-label__thumb">
                        <picture>
                            <source media="(max-width: 767px)" srcset="<?= $e($image['mo_image']) ?>">
                            <img src="<?= $e($image['pc_image']) ?>" alt="<?= $e($alt) ?>" loading="lazy">
                        </picture>
                    </span>

                    <?php if ($title !== '' || $desc !== ''): ?>
                    <span class="icon-label__text">
                        <?php if ($title !== ''): ?>
                            <strong class="icon-label__title"><?= $e($title) ?></strong>
                        <?php endif; ?>
                        <?php if ($desc !== ''): ?>
                            <span class="icon-label__desc"><?= nl2br($e($desc)) ?></span>
                        <?php endif; ?>
                    </span>
                    <?php endif; ?>

                    <?php if ($link !== ''): ?>
                    </a>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
    </div>
</div>
