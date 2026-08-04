<?php
/**
 * Block Skin: visitor_stats/basic — 방문자 통계 숫자형(기본, 카드)
 * 그룹핑: (오늘 방문자) / (어제·전체).
 * - 오늘: 큰 숫자 + 페이지뷰·회원
 * - 어제/전체: 컴팩트 1행(방문자 + 페이지뷰). 회원은 표시하지 않음(백엔드 값은 유지).
 *
 * @var array $titleConfig
 * @var string $titlePartial
 * @var array $contentConfig
 * @var \Mublo\Contract\Block\BlockColumnView $column
 * @var array $groups  [ ['label','visitors','pageviews','members','change'], ... ]
 * @var \Mublo\Core\Rendering\AssetManager|null $assets
 */

$groups = $groups ?? [];
$today  = $groups[0] ?? null;
$rest   = array_slice($groups, 1);

if ($assets) {
    $assets->addCss('/serve/plugin/VisitorStats/views/Block/visitor_stats/basic/style.css');
}
?>
<div class="block-vstats block-vstats--basic">
    <?php include $titlePartial; ?>

    <div class="block-vstats__content block-body">
        <div class="block-vstats__card">
            <?php if ($today): $change = $today['change'] ?? null; ?>
            <div class="block-vstats__group">
                <div class="block-vstats__main">
                    <span class="block-vstats__label"><?= htmlspecialchars($today['label']) ?></span>
                    <span class="block-vstats__value">
                        <?= number_format((int) $today['visitors']) ?>
                        <?php if ($change !== null && $change != 0.0): ?>
                        <small class="block-vstats__change block-vstats__change--<?= $change > 0 ? 'up' : 'down' ?>">
                            <i class="bi bi-caret-<?= $change > 0 ? 'up' : 'down' ?>-fill"></i><?= abs($change) ?>%
                        </small>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="block-vstats__sub">
                    <span class="block-vstats__sub-item">
                        <i class="bi bi-eye"></i> 페이지뷰 <b><?= number_format((int) $today['pageviews']) ?></b>
                    </span>
                    <span class="block-vstats__sub-item">
                        <i class="bi bi-person"></i> 회원 <b><?= number_format((int) $today['members']) ?></b>
                    </span>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($rest): ?>
            <div class="block-vstats__rows">
                <?php foreach ($rest as $g): ?>
                <div class="block-vstats__row">
                    <span class="block-vstats__row-label"><?= htmlspecialchars($g['label']) ?></span>
                    <span class="block-vstats__row-value"><?= number_format((int) $g['visitors']) ?></span>
                    <span class="block-vstats__row-pv"><i class="bi bi-eye"></i> 페이지뷰 <b><?= number_format((int) $g['pageviews']) ?></b></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
