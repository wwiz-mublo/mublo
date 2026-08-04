<?php $currentTab = 'dashboard'; ?>
<?php include __DIR__ . '/_nav.php'; ?>

<!-- 기간 선택 -->
<div class="d-flex justify-content-end mb-3">
    <select id="vs-period" class="form-select" style="width:auto;">
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
                <div class="text-muted small">방문자 (UV)</div>
                <div class="vs-metric-val" id="m-visitors">-</div>
                <div class="vs-metric-change small" id="m-visitors-change"></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card h-100 vs-metric">
            <div class="card-body">
                <div class="text-muted small">페이지뷰 (PV)</div>
                <div class="vs-metric-val" id="m-pageviews">-</div>
                <div class="vs-metric-change small" id="m-pageviews-change"></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card h-100 vs-metric">
            <div class="card-body">
                <div class="text-muted small">신규 방문</div>
                <div class="vs-metric-val" id="m-new">-</div>
                <div class="vs-metric-change small" id="m-new-change"></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card h-100 vs-metric">
            <div class="card-body">
                <div class="text-muted small">전환 (폼 제출)</div>
                <div class="vs-metric-val" id="m-conversions">-</div>
                <div class="vs-metric-change small" id="m-conversions-change"></div>
            </div>
        </div>
    </div>
</div>

<!-- 일별 추이 + 시간대별 -->
<div class="row g-3 mb-3">
    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-hero">
                <i class="bi bi-graph-up text-chart-indigo"></i>
                <span>일별 방문 현황</span>
            </div>
            <div class="card-body">
                <canvas id="vs-trend-chart" style="width:100%; display:block;"></canvas>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-hero">
                <i class="bi bi-clock text-chart-sky"></i>
                <span>시간대별 방문자</span>
            </div>
            <div class="card-body">
                <canvas id="vs-hourly-chart" style="width:100%; display:block;"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- 디바이스 + 유입경로 도넛 -->
<div class="row g-3 mb-3">
    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-hero">
                <i class="bi bi-display text-chart-violet"></i>
                <span>디바이스</span>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-5 d-flex align-items-center justify-content-center">
                        <canvas id="vs-device-chart" style="width:100%; max-width:160px; display:block;"></canvas>
                    </div>
                    <div class="col-7">
                        <div class="table-responsive">
                            <table class="table table-sm mb-0"><tbody id="vs-device-body"></tbody></table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-hero">
                <i class="bi bi-signpost-split text-chart-emerald"></i>
                <span>유입 경로</span>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-5 d-flex align-items-center justify-content-center">
                        <canvas id="vs-referer-chart" style="width:100%; max-width:160px; display:block;"></canvas>
                    </div>
                    <div class="col-7">
                        <div class="table-responsive">
                            <table class="table table-sm mb-0"><tbody id="vs-referer-body"></tbody></table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="/serve/plugin/VisitorStats/assets/js/visitor-stats.js?v=20260320"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var periodEl = document.getElementById('vs-period');
    var VC = typeof VisitorChart !== 'undefined' ? VisitorChart : null;
    var pastelPalette = ['#818cf8','#34d399','#fbbf24','#f472b6','#38bdf8','#a78bfa','#fb923c','#2dd4bf','#f87171','#a3e635'];

    function fmt(n) { return VC ? VC.formatNum(n) : (n || 0).toLocaleString(); }

    function loadAll() {
        var period = periodEl.value;
        loadSummary(period);
        loadTrend(period);
        loadHourly(period);
        loadDevice(period);
        loadReferer(period);
        loadConversions(period);
    }

    periodEl.addEventListener('change', loadAll);
    loadAll();

    function loadConversions(period) {
        MubloRequest.requestJson('/admin/visitor-stats/api/dashboard-conversions', { period: period }, { method: 'POST' })
            .then(function (res) {
                var d = res.data || {};
                document.getElementById('m-conversions').textContent = fmt(d.conversions || 0);
                showChange('m-conversions-change', d.conversions || 0, d.prevConversions);
            }).catch(function () {
                document.getElementById('m-conversions').textContent = '-';
            });
    }

    function loadSummary(period) {
        MubloRequest.requestJson('/admin/visitor-stats/api/summary', { period: period }, { method: 'POST' })
            .then(function (res) {
                var d = res.data || {};
                document.getElementById('m-visitors').textContent = fmt(d.visitors || 0);
                document.getElementById('m-pageviews').textContent = fmt(d.pageviews || 0);
                document.getElementById('m-new').textContent = fmt(d.newVisitors || 0);

                var change = d.change || {};
                showChange('m-visitors-change', d.visitors || 0, change.visitors !== undefined ? (d.visitors || 0) - Math.round((d.visitors || 0) * change.visitors / 100) : null);
                showChange('m-pageviews-change', d.pageviews || 0, change.pageviews !== undefined ? (d.pageviews || 0) - Math.round((d.pageviews || 0) * change.pageviews / 100) : null);
                showChange('m-new-change', d.newVisitors || 0, change.newVisitors !== undefined ? (d.newVisitors || 0) - Math.round((d.newVisitors || 0) * change.newVisitors / 100) : null);
            });
    }

    function loadTrend(period) {
        MubloRequest.requestJson('/admin/visitor-stats/api/trend', { period: period }, { method: 'POST' })
            .then(function (res) {
                if (!VC) return;
                VC.lineChart('vs-trend-chart', res.data || [], {
                    height: 200,
                    labelKey: 'date',
                    series: [
                        { key: 'visitors', label: '방문자', color: '#818cf8' },
                        { key: 'pageviews', label: '페이지뷰', color: '#34d399' },
                        { key: 'newVisitors', label: '신규', color: '#fbbf24' },
                    ]
                });
            });
    }

    function loadHourly(period) {
        MubloRequest.requestJson('/admin/visitor-stats/api/hourly', { period: period }, { method: 'POST' })
            .then(function (res) {
                if (!VC) return;
                VC.barChart('vs-hourly-chart', res.data || [], {
                    height: 200,
                    labelKey: 'hour',
                    valueKey: 'visitors',
                    color: '#818cf8',
                });
            });
    }

    function loadDevice(period) {
        MubloRequest.requestJson('/admin/visitor-stats/api/environment', { period: period }, { method: 'POST' })
            .then(function (res) {
                var d = res.data || {};
                renderDonutTable('vs-device-chart', 'vs-device-body', d.device || [], 'name', 'count');
            });
    }

    function loadReferer(period) {
        MubloRequest.requestJson('/admin/visitor-stats/api/referrers', { period: period }, { method: 'POST' })
            .then(function (res) {
                var d = res.data || {};
                renderDonutTable('vs-referer-chart', 'vs-referer-body', d.types || [], 'type', 'count');
            });
    }

    function renderDonutTable(chartId, tbodyId, items, nameKey, valKey) {
        var itemColors = items.map(function (_, i) { return pastelPalette[i % pastelPalette.length]; });

        var donutData = [];
        var donutPalette = [];
        items.forEach(function (item, i) {
            var count = parseInt(item[valKey] || 0);
            if (count > 0) {
                donutData.push({ name: item[nameKey] || '-', count: count });
                donutPalette.push(itemColors[i]);
            }
        });
        if (VC) VC.donutChart(chartId, donutData, { size: 140, valueKey: 'count', nameKey: 'name', palette: donutPalette });

        document.getElementById(tbodyId).innerHTML = items.map(function (item, i) {
            return '<tr>'
                + '<td><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:'
                + itemColors[i] + ';margin-right:6px;vertical-align:middle;"></span>'
                + escHtml(item[nameKey] || '-') + '</td>'
                + '<td class="text-end">' + fmt(parseInt(item[valKey] || 0)) + '</td>'
                + '</tr>';
        }).join('');
    }

    function showChange(elId, current, previous) {
        var el = document.getElementById(elId);
        if (!el) return;
        if (previous === null || previous === undefined) { el.innerHTML = ''; return; }
        var diff = current - previous;
        var cls, icon, text;
        if (diff > 0) {
            cls = 'up'; icon = 'bi-caret-up-fill';
            text = previous > 0 ? '+' + Math.round(diff / previous * 100) + '%' : '+' + diff;
        } else if (diff < 0) {
            cls = 'down'; icon = 'bi-caret-down-fill';
            text = previous > 0 ? '-' + Math.round(Math.abs(diff) / previous * 100) + '%' : diff;
        } else {
            cls = 'flat'; icon = 'bi-dash'; text = '동일';
        }
        el.innerHTML = '<span class="' + cls + '" title="이전 기간: ' + previous + '"><i class="bi ' + icon + '"></i> ' + text + '</span>';
    }

    function escHtml(str) {
        var d = document.createElement('div');
        d.textContent = str;
        return d.innerHTML;
    }
});
</script>
