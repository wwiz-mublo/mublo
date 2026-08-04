<?php
/**
 * Block Skin: html/basic
 *
 * HTML 직접입력 기본 스킨
 *
 * 모드:
 * - single: 단일 HTML 출력 (MubloItemLayout 비적용)
 * - slide: 여러 슬라이드를 MubloItemLayout(Swiper)으로 출력
 *
 * @var array $titleConfig 타이틀 설정
 * @var string $titlePartial 타이틀 파셜 경로
 * @var array $contentConfig 콘텐츠 설정
 * @var \Mublo\Contract\Block\BlockColumnView $column 블록 칸 엔티티
 * @var string $html 정화된 HTML 콘텐츠 (single 모드)
 * @var string $css CSS 문자열
 * @var string $js JS 문자열
 * @var array|null $slides 슬라이드 배열 (slide 모드)
 * @var string $slideAttrs MubloItemLayout data 속성 (slide 모드)
 */
$css = $css ?? '';
$js = $js ?? '';
?>
<div class="block-html block-html--basic">
    <?php include $titlePartial; ?>

    <?php if (!empty($css)): ?>
    <style><?= $css ?></style>
    <?php endif; ?>

    <!-- 콘텐츠 영역 -->
    <div class="block-html__content block-body">
        <?php if (!empty($slides) && is_array($slides)): ?>
        <!-- 슬라이드 모드 -->
        <div class="mublo-item-layout" <?= $slideAttrs ?>>
            <ul class="block-html__slides">
                <?php foreach ($slides as $slide): ?>
                <li class="block-html__slide"><?= $slide['html'] ?? '' ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php else: ?>
        <!-- 단일 모드 -->
        <?= $html ?>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($js)): ?>
<script><?= $js ?></script>
<?php endif; ?>
