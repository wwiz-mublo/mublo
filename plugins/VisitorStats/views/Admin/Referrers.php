<?php $currentTab = 'referrers'; ?>
<?php include __DIR__ . '/_nav.php'; ?>

<div class="d-flex justify-content-end mb-3">
    <select id="vs-period" class="form-select" style="width:auto;">
        <option value="today">오늘</option>
        <option value="last_7_days" selected>최근 7일</option>
        <option value="last_30_days">최근 30일</option>
        <option value="this_month">이번 달</option>
    </select>
</div>

<div class="row g-3 mb-3">
    <div class="col-12 col-lg-5">
        <div class="card h-100">
            <div class="card-hero">
                <i class="bi bi-pie-chart text-chart-indigo"></i>
                <span>유입 유형 비율</span>
            </div>
            <div class="card-body text-center">
                <canvas id="vs-ref-type-chart" class="mb-3" style="display:inline-block;"></canvas>
                <div id="vs-ref-type-legend"></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-7">
        <div class="card h-100">
            <div class="card-hero">
                <i class="bi bi-globe text-chart-emerald"></i>
                <span>유입 도메인 TOP 30</span>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover vs-table mb-0">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>유형</th>
                                <th>도메인</th>
                                <th class="text-end">방문자</th>
                            </tr>
                        </thead>
                        <tbody id="vs-ref-domain-body">
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
    var periodEl = document.getElementById('vs-period');

    var typeLabels = {
        direct: '직접 방문',
        search: '검색 엔진',
        social: 'SNS',
        external: '외부 링크',
    };

    function loadData() {
        var period = periodEl.value;

        MubloRequest.requestJson('/admin/visitor-stats/api/referrers', { period: period }, { method: 'POST' })
            .then(function (res) {
                var d = res.data || {};
                renderTypeChart(d.types || []);
                renderDomainTable(d.domains || []);
            });
    }

    function renderTypeChart(types) {
        var data = types.map(function (t) {
            return {
                name: typeLabels[t.referer_type] || t.referer_type,
                count: Number(t.visitors || 0)
            };
        });

        VisitorChart.donutChart('vs-ref-type-chart', data, {
            size: 200,
            valueKey: 'count',
            nameKey: 'name',
        });
        document.getElementById('vs-ref-type-legend').innerHTML =
            VisitorChart.donutLegend(data, { valueKey: 'count', nameKey: 'name' });
    }

    function renderDomainTable(domains) {
        var tbody = document.getElementById('vs-ref-domain-body');
        if (domains.length === 0) {
            tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-3">데이터가 없습니다.</td></tr>';
            return;
        }

        var html = '';
        domains.forEach(function (d, i) {
            var type = typeLabels[d.referer_type] || d.referer_type;
            html += '<tr>';
            html += '<td class="text-muted">' + (i + 1) + '</td>';
            html += '<td><span class="badge text-bg-' + typeBadge(d.referer_type) + '">' + escapeHtml(type) + '</span></td>';
            html += '<td>' + escapeHtml(d.referer_domain || '-') + '</td>';
            html += '<td class="text-end fw-semibold">' + Number(d.visitors || 0).toLocaleString('ko-KR') + '</td>';
            html += '</tr>';
        });
        tbody.innerHTML = html;
    }

    function typeBadge(type) {
        return { direct: 'secondary', search: 'primary', social: 'danger', external: 'success' }[type] || 'secondary';
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    periodEl.addEventListener('change', loadData);
    loadData();
});
</script>
