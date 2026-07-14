<?php
/**
 * Block Skin: visitor_trend/basic — 방문자 추이(최근 7일, 카드)
 *
 * @var array $titleConfig
 * @var string $titlePartial
 * @var array $contentConfig
 * @var \Mublo\Entity\Block\BlockColumn $column
 * @var array $trend  VisitorStatsService::getTrend(domainId,'last_7_days') — [{date,visitors,...}, ...]
 * @var int   $total  누적(전체) 방문자
 * @var \Mublo\Core\Rendering\AssetManager|null $assets
 */

$trend = $trend ?? [];
$total = (int) ($total ?? 0);   // 누적(전체) 방문자
$max = 0;
$weekTotal = 0;
foreach ($trend as $d) {
    $v = (int) ($d['visitors'] ?? 0);
    $max = max($max, $v);
    $weekTotal += $v;
}
$weekdays = ['일', '월', '화', '수', '목', '금', '토'];
$todayStr = date('Y-m-d');

if ($assets) {
    $assets->addCss('/serve/plugin/VisitorStats/views/Block/visitor_trend/basic/style.css');
}
?>
<div class="block-vtrend block-vtrend--basic">
    <?php include $titlePartial; ?>

    <div class="block-vtrend__content block-body">
        <div class="block-vtrend__card">
            <div class="block-vtrend__caption">
                <span class="block-vtrend__caption-label">최근 7일 방문자</span>
                <span class="block-vtrend__caption-total"><?= number_format($weekTotal) ?></span>
                <span class="block-vtrend__caption-sub"><i class="bi bi-stack"></i> 전체 <b><?= number_format($total) ?></b></span>
            </div>

            <?php if (empty($trend)): ?>
            <p class="block-vtrend__empty">표시할 데이터가 없습니다.</p>
            <?php else: ?>
            <div class="block-vtrend__chart">
                <?php foreach ($trend as $d):
                    $v  = (int) ($d['visitors'] ?? 0);
                    $h  = $max > 0 ? round($v / $max * 100) : 0;
                    $ts = strtotime($d['date'] ?? 'now');
                    $isToday = ($d['date'] ?? '') === $todayStr;
                ?>
                <div class="block-vtrend__bar-col<?= $isToday ? ' is-today' : '' ?>">
                    <div class="block-vtrend__bar-track">
                        <div class="block-vtrend__bar" style="height: <?= max($h, 3) ?>%">
                            <span class="block-vtrend__bar-val"><?= number_format($v) ?></span>
                        </div>
                    </div>
                    <span class="block-vtrend__bar-label"><?= $weekdays[(int) date('w', $ts)] ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
