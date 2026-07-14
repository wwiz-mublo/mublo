<?php
/**
 * Block Skin: include/basic
 *
 * PHP 파일 포함 기본 스킨
 *
 * MubloItemLayout 비적용: 단일 콘텐츠 블록
 *
 * @var array $titleConfig 타이틀 설정
 * @var string $titlePartial 타이틀 파셜 경로
 * @var array $contentConfig 콘텐츠 설정
 * @var \Mublo\Entity\Block\BlockColumn $column 블록 칸 엔티티
 * @var string $includeHtml 포함된 파일의 출력 결과
 * @var string $includePath 포함된 파일 경로
 */
?>
<div class="block-include block-include--basic">
    <?php include $titlePartial; ?>

    <!-- 콘텐츠 영역 -->
    <div class="block-include__content block-body">
        <?= $includeHtml ?>
    </div>
</div>
