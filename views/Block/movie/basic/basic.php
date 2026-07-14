<?php
/**
 * Block Skin: movie/basic
 *
 * 동영상 기본 스킨
 *
 * MubloItemLayout 비적용: 단일 콘텐츠 블록
 *
 * @var array $titleConfig 타이틀 설정
 * @var string $titlePartial 타이틀 파셜 경로
 * @var array $contentConfig 콘텐츠 설정
 * @var \Mublo\Entity\Block\BlockColumn $column 블록 칸 엔티티
 * @var string $videoType youtube|vimeo|video
 * @var string $videoHtml 렌더링된 비디오 HTML
 * @var string $aspectRatio 비율 (16:9, 4:3 등)
 */

$aspectStyle = match ($aspectRatio ?? '16:9') {
    '16:9' => 'aspect-ratio: 16/9;',
    '4:3' => 'aspect-ratio: 4/3;',
    '1:1' => 'aspect-ratio: 1/1;',
    '21:9' => 'aspect-ratio: 21/9;',
    default => 'aspect-ratio: 16/9;',
};
?>
<div class="block-movie block-movie--basic block-movie--<?= $videoType ?>">
    <?php include $titlePartial; ?>

    <!-- 콘텐츠 영역 -->
    <div class="block-movie__content block-body" style="<?= $aspectStyle ?>">
        <?= $videoHtml ?>
    </div>
</div>
