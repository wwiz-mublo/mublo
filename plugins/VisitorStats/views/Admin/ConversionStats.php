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
                <div class="text-muted small">최다 소스</div>
                <div class="vs-metric-val fs-6" id="cs-top-source">-</div>
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
                                <th style="width:120px;">최다 소스</th>
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
    <!-- 소스별 전환 (주문·상담·폼 접수 등 확장이 통보한 전환) -->
    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-hero">
                <i class="bi bi-bag-check text-chart-emerald"></i>
                <span>소스별 전환</span>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover vs-table mb-0">
                        <thead>
                            <tr>
                                <th>소스</th>
                                <th class="text-end" style="width:80px;">전환</th>
                                <th class="text-end" style="width:90px;">전체 통보</th>
                                <th class="text-end" style="width:120px;">금액 합계</th>
                            </tr>
                        </thead>
                        <tbody id="cs-source-body">
                            <tr><td colspan="4" class="text-center text-muted py-3">로딩 중...</td></tr>
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

                var ts = d.topSource;
                document.getElementById('cs-top-source').textContent = ts
                    ? ts.source_label + ' (' + ts.conversions + '건)'
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

                // 소스별 테이블
                renderSources(d.bySource || []);
            });
    }

    function renderSources(items) {
        var tbody = document.getElementById('cs-source-body');
        if (!items.length) {
            tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-3">데이터 없음</td></tr>';
            return;
        }
        tbody.innerHTML = items.map(function (item) {
            return '<tr>'
                + '<td>' + MubloRequest.escapeHtml(item.source_label || item.source_type) + '</td>'
                + '<td class="text-end fw-semibold">' + VisitorChart.formatNum(item.conversions) + '</td>'
                + '<td class="text-end text-muted">' + VisitorChart.formatNum(item.recorded) + '</td>'
                + '<td class="text-end">' + (item.value_sum ? VisitorChart.formatNum(Math.round(item.value_sum)) : '-') + '</td>'
                + '</tr>';
        }).join('');
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
                + '<td>' + MubloRequest.escapeHtml(ck) + '</td>'
                + '<td class="text-end">' + VisitorChart.formatNum(parseInt(item.conversions)) + '</td>'
                + '<td class="text-truncate" style="max-width:120px;">'
                + MubloRequest.escapeHtml(item.top_source || '-') + '</td>'
                + '</tr>';
        }).join('');
    }

    periodEl.addEventListener('change', loadAll);
    loadAll();
});
</script>
