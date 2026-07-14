<?php
/**
 * 방문자 통계 탭 네비게이션
 *
 * @var string $currentTab 현재 활성 탭 (dashboard|realtime|pages|referrers|environment)
 */
$currentTab = $currentTab ?? 'dashboard';
$activeCode = $_GET['activeCode'] ?? 'P_VisitorStats_001';

$tabs = [
    'dashboard'         => ['label' => '대시보드',  'url' => '/admin/visitor-stats/dashboard',         'icon' => 'bi-speedometer2',        'desc' => '방문자 추이와 핵심 지표를 한눈에 확인합니다.'],
    'realtime'          => ['label' => '실시간',    'url' => '/admin/visitor-stats/realtime',           'icon' => 'bi-lightning',           'desc' => '현재 접속 중인 방문자와 최근 활동을 실시간으로 확인합니다.'],
    'pages'             => ['label' => '페이지별',  'url' => '/admin/visitor-stats/pages',              'icon' => 'bi-file-earmark-text',   'desc' => '페이지별 조회수와 방문 분포를 분석합니다.'],
    'referrers'         => ['label' => '유입 경로', 'url' => '/admin/visitor-stats/referrers',          'icon' => 'bi-signpost-split',      'desc' => '방문자가 어디에서 유입되는지 분석합니다.'],
    'campaigns'         => ['label' => '캠페인',    'url' => '/admin/visitor-stats/campaigns',          'icon' => 'bi-megaphone',           'desc' => '캠페인별 유입과 성과를 분석합니다.'],
    'conversions'       => ['label' => '전환 목록', 'url' => '/admin/visitor-stats/conversions',        'icon' => 'bi-check2-circle',       'desc' => '발생한 전환 내역을 조회합니다.'],
    'conversion-stats'  => ['label' => '전환 통계', 'url' => '/admin/visitor-stats/conversion-stats',   'icon' => 'bi-graph-up-arrow',      'desc' => '전환율과 전환 추이를 분석합니다.'],
    'environment'       => ['label' => '환경',      'url' => '/admin/visitor-stats/environment',        'icon' => 'bi-display',             'desc' => '방문자의 기기·브라우저·OS 환경을 분석합니다.'],
    'campaign-settings' => ['label' => '키 설정',   'url' => '/admin/visitor-stats/campaign-settings',  'icon' => 'bi-key',                 'desc' => '캠페인 추적에 사용할 키를 관리합니다.'],
];
$tabDesc = $tabs[$currentTab]['desc'] ?? '';
?>
<div class="page-title">
    <div class="page-title-text">
        <h3><?= htmlspecialchars($pageTitle ?? '방문자 통계', ENT_QUOTES, 'UTF-8') ?></h3>
        <?php if ($tabDesc !== ''): ?>
        <p><?= htmlspecialchars($tabDesc, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
    </div>
</div>

<ul class="nav nav-tabs mb-3">
    <?php foreach ($tabs as $key => $tab): ?>
    <li class="nav-item">
        <a class="nav-link<?= $currentTab === $key ? ' active' : '' ?>"
           href="<?= $tab['url'] ?>?activeCode=<?= htmlspecialchars($activeCode, ENT_QUOTES, 'UTF-8') ?>">
            <i class="bi <?= $tab['icon'] ?>"></i> <?= $tab['label'] ?>
        </a>
    </li>
    <?php endforeach; ?>
</ul>

<link rel="stylesheet" href="/serve/plugin/VisitorStats/assets/css/visitor-stats.css?v=20260522">

<?php // 캠페인키 인터랙티브 가이드 (탭 강조 + 설정 페이지 투어) — serve max-age 24h 대응 filemtime 버전 ?>
<script src="/serve/plugin/VisitorStats/assets/js/campaign-tour.js?<?= @filemtime(MUBLO_PLUGIN_PATH . '/VisitorStats/assets/js/campaign-tour.js') ?: 1 ?>" defer></script>
