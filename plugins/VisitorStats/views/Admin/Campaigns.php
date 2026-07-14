<?php $currentTab = 'campaigns'; ?>
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

<div class="row g-3 mb-3">
    <!-- 그룹별 요약 -->
    <div class="col-12 col-lg-5">
        <div class="card h-100">
            <div class="card-hero">
                <i class="bi bi-collection text-chart-indigo"></i>
                <span>그룹별 요약</span>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover vs-table mb-0">
                        <thead>
                            <tr>
                                <th>그룹명</th>
                                <th class="text-end" style="width:70px;">키</th>
                                <th class="text-end" style="width:100px;">방문자</th>
                                <th class="text-end" style="width:100px;">PV</th>
                            </tr>
                        </thead>
                        <tbody id="cp-group-body">
                            <tr><td colspan="4" class="text-center text-muted py-3">로딩 중...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <!-- 그룹별 도넛 차트 -->
    <div class="col-12 col-lg-4">
        <div class="card h-100">
            <div class="card-hero">
                <i class="bi bi-pie-chart text-chart-emerald"></i>
                <span>그룹별 방문자 비율</span>
            </div>
            <div class="card-body text-center">
                <canvas id="cp-group-chart" class="mb-3" style="display:inline-block;"></canvas>
                <div id="cp-group-legend"></div>
            </div>
        </div>
    </div>
    <!-- 추이 차트 -->
    <div class="col-12 col-lg-3">
        <div class="card h-100">
            <div class="card-hero">
                <i class="bi bi-graph-up text-chart-amber"></i>
                <span>전체 캠페인 추이</span>
            </div>
            <div class="card-body">
                <canvas id="cp-trend-chart" style="width:100%; display:block;"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- 키별 상세 -->
<div class="card">
    <div class="card-hero">
        <i class="bi bi-key text-chart-rose"></i>
        <span>키별 상세</span>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover vs-table mb-0">
                <thead>
                    <tr>
                        <th style="width:40px;">#</th>
                        <th>캠페인 키</th>
                        <th>그룹명</th>
                        <th class="text-end" style="width:110px;">방문자 (UV)</th>
                        <th class="text-end" style="width:80px;">전환</th>
                        <th class="text-end" style="width:80px;">전환율</th>
                        <th style="width:60px;"></th>
                    </tr>
                </thead>
                <tbody id="cp-key-body">
                    <tr><td colspan="7" class="text-center text-muted py-3">로딩 중...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="/serve/plugin/VisitorStats/assets/js/visitor-stats.js?v=20260320"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var periodEl = document.getElementById('vs-period');

    function loadAll() {
        var period = periodEl.value;
        loadCampaigns(period);
        loadTrend(period);
    }

    periodEl.addEventListener('change', loadAll);
    loadAll();

    function loadCampaigns(period) {
        // campaign-summary API: 방문자 + 전환 + 전환율 통합
        MubloRequest.requestJson('/admin/visitor-stats/api/campaign-summary', { period: period }, { method: 'POST' })
            .then(function (res) {
                var d = res.data || {};
                renderKeys(d.items || [], d.totalConversions || 0, d.totalRate || 0);

                // 그룹별 요약 (방문 기반)
                var groups = {};
                (d.items || []).forEach(function (item) {
                    if (!item.campaign_key) return;
                    var gn = item.group_name || '(미분류)';
                    if (!groups[gn]) groups[gn] = { group_name: gn, keys: 0, visitors: 0, pageviews: 0, conversions: 0 };
                    groups[gn].keys++;
                    groups[gn].visitors += item.visitors;
                    groups[gn].pageviews += item.pageviews;
                    groups[gn].conversions += item.conversions;
                });
                var groupArr = Object.values(groups);
                renderGroups(groupArr);
                renderGroupChart(groupArr);
            });
    }

    function loadTrend(period) {
        MubloRequest.requestJson('/admin/visitor-stats/api/campaign-trend', { period: period }, { method: 'POST' })
            .then(function (res) {
                VisitorChart.lineChart('cp-trend-chart', res.data || [], {
                    height: 160,
                    labelKey: 'date',
                    series: [
                        { key: 'visitors', label: 'UV', color: VisitorChart.colors.primary },
                        { key: 'pageviews', label: 'PV', color: VisitorChart.colors.success },
                    ]
                });
            });
    }

    function renderGroups(groups) {
        var tbody = document.getElementById('cp-group-body');
        if (groups.length === 0) {
            tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-3">데이터가 없습니다.</td></tr>';
            return;
        }
        var html = '';
        groups.forEach(function (g) {
            html += '<tr>';
            html += '<td>' + escapeHtml(g.group_name) + '</td>';
            html += '<td class="text-end">' + g.keys + '</td>';
            html += '<td class="text-end fw-semibold">' + Number(g.visitors).toLocaleString('ko-KR') + '</td>';
            html += '<td class="text-end">' + Number(g.pageviews).toLocaleString('ko-KR') + '</td>';
            html += '</tr>';
        });
        tbody.innerHTML = html;
    }

    function renderKeys(items, totalConversions, totalRate) {
        var tbody = document.getElementById('cp-key-body');
        if (items.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-3">데이터가 없습니다.</td></tr>';
            return;
        }
        var html = '';
        items.forEach(function (item, i) {
            var ck = item.campaign_key || '(직접접속)';
            var filterKey = item.campaign_key || '__direct__';
            html += '<tr>';
            html += '<td class="text-muted">' + (i + 1) + '</td>';
            html += '<td><code>' + escapeHtml(ck) + '</code></td>';
            html += '<td>' + escapeHtml(item.group_name || '') + '</td>';
            html += '<td class="text-end fw-semibold">' + Number(item.visitors).toLocaleString('ko-KR') + '</td>';
            html += '<td class="text-end">' + Number(item.conversions).toLocaleString('ko-KR') + '</td>';
            html += '<td class="text-end">' + (item.rate || 0) + '%</td>';
            html += '<td><a href="/admin/visitor-stats/conversions?campaign=' + encodeURIComponent(filterKey) + '" class="btn btn-sm btn-outline-secondary">상세</a></td>';
            html += '</tr>';
        });

        // 합계행
        var totalVisitors = items.reduce(function (s, i) { return s + i.visitors; }, 0);
        html += '<tr class="table-light fw-semibold">';
        html += '<td></td><td>합계</td><td></td>';
        html += '<td class="text-end">' + Number(totalVisitors).toLocaleString('ko-KR') + '</td>';
        html += '<td class="text-end">' + Number(totalConversions).toLocaleString('ko-KR') + '</td>';
        html += '<td class="text-end">' + totalRate + '%</td>';
        html += '<td></td></tr>';

        tbody.innerHTML = html;
    }

    function renderGroupChart(groups) {
        if (groups.length === 0) {
            var canvas = document.getElementById('cp-group-chart');
            canvas.style.display = 'none';
            document.getElementById('cp-group-legend').innerHTML = '<span class="text-muted small">데이터 없음</span>';
            return;
        }
        var chartData = groups.map(function (g) {
            return { name: g.group_name, count: g.visitors };
        });
        VisitorChart.donutChart('cp-group-chart', chartData, {
            size: 160,
            valueKey: 'count',
            nameKey: 'name',
        });
        document.getElementById('cp-group-legend').innerHTML =
            VisitorChart.donutLegend(chartData, { valueKey: 'count', nameKey: 'name' });
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
});
</script>
