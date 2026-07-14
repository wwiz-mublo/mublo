<?php $currentTab = 'conversion-stats'; ?>
<?php include __DIR__ . '/_nav.php'; ?>

<!-- 기간 선택 -->
<div class="d-flex justify-content-end mb-3">
    <select id="cs-period" class="form-select" style="width:auto;">
        <option value="today">오늘</option>
        <option value="last_7_days" selected>최근 7일</option>
        <option value="last_30_days">최근 30일</option>
        <option value="this_month">이번 달</option>
    </select>
</div>

<!-- 요약 카드 -->
<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3">
        <div class="card h-100 vs-metric">
            <div class="card-body">
                <div class="text-muted small">총 전환</div>
                <div class="vs-metric-val" id="cs-total">-</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card h-100 vs-metric">
            <div class="card-body">
                <div class="text-muted small">일평균</div>
                <div class="vs-metric-val" id="cs-avg">-</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card h-100 vs-metric">
            <div class="card-body">
                <div class="text-muted small">최다 캠페인</div>
                <div class="vs-metric-val fs-6" id="cs-top-campaign">-</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card h-100 vs-metric">
            <div class="card-body">
                <div class="text-muted small">최다 폼</div>
                <div class="vs-metric-val fs-6" id="cs-top-form">-</div>
            </div>
        </div>
    </div>
</div>

<!-- 일별 전환 추이 -->
<div class="card mb-3">
    <div class="card-hero">
        <i class="bi bi-graph-up text-chart-indigo"></i>
        <span>일별 전환 추이</span>
    </div>
    <div class="card-body">
        <canvas id="cs-trend-chart" style="width:100%; display:block;"></canvas>
    </div>
</div>

<div class="row g-3 mb-3">
    <!-- 캠페인별 전환 -->
    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-hero">
                <i class="bi bi-megaphone text-chart-amber"></i>
                <span>캠페인별 전환</span>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover vs-table mb-0">
                        <thead>
                            <tr>
                                <th>캠페인키</th>
                                <th class="text-end" style="width:80px;">전환</th>
                                <th style="width:120px;">최다 폼</th>
                            </tr>
                        </thead>
                        <tbody id="cs-campaign-body">
                            <tr><td colspan="3" class="text-center text-muted py-3">로딩 중...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <!-- 폼별 전환 -->
    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-hero">
                <i class="bi bi-ui-radios-grid text-chart-emerald"></i>
                <span>폼별 전환</span>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover vs-table mb-0">
                        <thead>
                            <tr>
                                <th>폼 제목</th>
                                <th class="text-end" style="width:80px;">전환</th>
                                <th class="text-end" style="width:100px;">캠페인 경유</th>
                            </tr>
                        </thead>
                        <tbody id="cs-form-body">
                            <tr><td colspan="3" class="text-center text-muted py-3">로딩 중...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="/serve/plugin/VisitorStats/assets/js/visitor-stats.js?v=20260320"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var periodEl = document.getElementById('cs-period');

    function loadAll() {
        var period = periodEl.value;
        MubloRequest.requestJson('/admin/visitor-stats/api/conversion-stats', { period: period }, { method: 'POST' })
            .then(function (res) {
                var d = res.data || {};
                document.getElementById('cs-total').textContent = VisitorChart.formatNum(d.total || 0);
                document.getElementById('cs-avg').textContent = (d.avgDaily || 0) + '건/일';

                var tc = d.topCampaign;
                document.getElementById('cs-top-campaign').textContent = tc
                    ? tc.campaign_key + ' (' + tc.conversions + '건)'
                    : '-';

                var tf = d.topForm;
                document.getElementById('cs-top-form').textContent = tf
                    ? tf.form_name + ' (' + tf.conversions + '건)'
                    : '-';

                // 일별 추이 차트
                VisitorChart.lineChart('cs-trend-chart', d.dailyTrend || [], {
                    height: 200,
                    labelKey: 'date',
                    series: [
                        { key: 'conversions', label: '전환', color: VisitorChart.colors.danger }
                    ]
                });

                // 캠페인별 테이블
                renderCampaigns(d.byCampaign || []);

                // 폼별 테이블
                renderForms(d.byForm || []);
            });
    }

    function renderCampaigns(items) {
        var tbody = document.getElementById('cs-campaign-body');
        if (!items.length) {
            tbody.innerHTML = '<tr><td colspan="3" class="text-center text-muted py-3">데이터 없음</td></tr>';
            return;
        }
        tbody.innerHTML = items.map(function (item) {
            var ck = item.campaign_key || '(직접접속)';
            return '<tr>'
                + '<td>' + ck + '</td>'
                + '<td class="text-end">' + VisitorChart.formatNum(parseInt(item.conversions)) + '</td>'
                + '<td class="text-truncate" style="max-width:120px;">' + (item.top_form || '-') + '</td>'
                + '</tr>';
        }).join('');
    }

    function renderForms(items) {
        var tbody = document.getElementById('cs-form-body');
        if (!items.length) {
            tbody.innerHTML = '<tr><td colspan="3" class="text-center text-muted py-3">데이터 없음</td></tr>';
            return;
        }
        tbody.innerHTML = items.map(function (item) {
            return '<tr>'
                + '<td>' + (item.form_name || '(삭제된 폼)') + '</td>'
                + '<td class="text-end">' + VisitorChart.formatNum(parseInt(item.conversions)) + '</td>'
                + '<td class="text-end">' + VisitorChart.formatNum(parseInt(item.campaign_conversions || 0)) + '</td>'
                + '</tr>';
        }).join('');
    }

    periodEl.addEventListener('change', loadAll);
    loadAll();
});
</script>
