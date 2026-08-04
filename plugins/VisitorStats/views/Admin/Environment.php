<?php $currentTab = 'environment'; ?>
<?php include __DIR__ . '/_nav.php'; ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div></div>
    <div class="d-flex gap-2">
        <select id="vs-period" class="form-select" style="width:auto;">
            <option value="today">오늘</option>
            <option value="last_7_days" selected>최근 7일</option>
            <option value="last_30_days">최근 30일</option>
            <option value="this_month">이번 달</option>
        </select>
        <button type="button" class="btn btn-outline-danger" id="btn-purge">
            <i class="bi bi-trash"></i> 로그 정리
        </button>
    </div>
</div>

<!-- 브라우저/OS/디바이스 -->
<div class="row g-3 mb-3">
    <div class="col-12 col-lg-4">
        <div class="card h-100">
            <div class="card-hero">
                <i class="bi bi-browser-chrome text-chart-amber"></i>
                <span>브라우저</span>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-5 d-flex align-items-center justify-content-center">
                        <canvas id="vs-browser-chart" style="width:100%; max-width:140px; display:block;"></canvas>
                    </div>
                    <div class="col-7">
                        <div class="table-responsive">
                            <table class="table table-sm mb-0"><tbody id="vs-browser-body"></tbody></table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-4">
        <div class="card h-100">
            <div class="card-hero">
                <i class="bi bi-cpu text-chart-rose"></i>
                <span>운영체제</span>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-5 d-flex align-items-center justify-content-center">
                        <canvas id="vs-os-chart" style="width:100%; max-width:140px; display:block;"></canvas>
                    </div>
                    <div class="col-7">
                        <div class="table-responsive">
                            <table class="table table-sm mb-0"><tbody id="vs-os-body"></tbody></table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-4">
        <div class="card h-100">
            <div class="card-hero">
                <i class="bi bi-display text-chart-violet"></i>
                <span>디바이스</span>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-5 d-flex align-items-center justify-content-center">
                        <canvas id="vs-device-chart" style="width:100%; max-width:140px; display:block;"></canvas>
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
</div>

<script src="/serve/plugin/VisitorStats/assets/js/visitor-stats.js?v=20260320"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var periodEl = document.getElementById('vs-period');
    var VC = typeof VisitorChart !== 'undefined' ? VisitorChart : null;
    var pastelPalette = ['#818cf8','#34d399','#fbbf24','#f472b6','#38bdf8','#a78bfa','#fb923c','#2dd4bf','#f87171','#a3e635'];

    function fmt(n) { return VC ? VC.formatNum(n) : (n || 0).toLocaleString(); }

    function loadData() {
        var period = periodEl.value;

        MubloRequest.requestJson('/admin/visitor-stats/api/environment', { period: period }, { method: 'POST' })
            .then(function (res) {
                var d = res.data || {};
                renderDonutTable('vs-browser-chart', 'vs-browser-body', d.browser || []);
                renderDonutTable('vs-os-chart', 'vs-os-body', d.os || []);
                renderDonutTable('vs-device-chart', 'vs-device-body', d.device || []);
            });
    }

    function renderDonutTable(chartId, tbodyId, items) {
        var itemColors = items.map(function (_, i) { return pastelPalette[i % pastelPalette.length]; });

        var donutData = [];
        var donutPalette = [];
        items.forEach(function (item, i) {
            var count = parseInt(item.count || 0);
            if (count > 0) {
                donutData.push({ name: item.name || '-', count: count });
                donutPalette.push(itemColors[i]);
            }
        });
        if (VC) VC.donutChart(chartId, donutData, { size: 140, valueKey: 'count', nameKey: 'name', palette: donutPalette });

        document.getElementById(tbodyId).innerHTML = items.map(function (item, i) {
            return '<tr>'
                + '<td><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:'
                + itemColors[i] + ';margin-right:6px;vertical-align:middle;"></span>'
                + escHtml(item.name || '-') + '</td>'
                + '<td class="text-end">' + fmt(parseInt(item.count || 0)) + '</td>'
                + '</tr>';
        }).join('');
    }

    function escHtml(str) {
        var d = document.createElement('div');
        d.textContent = str;
        return d.innerHTML;
    }

    periodEl.addEventListener('change', loadData);
    loadData();

    // 로그 정리
    document.getElementById('btn-purge').addEventListener('click', function () {
        var days = prompt('몇 일 이전 로그를 삭제하시겠습니까? (기본: 30)', '30');
        if (days === null) return;
        days = parseInt(days, 10);
        if (isNaN(days) || days < 7) {
            MubloRequest.showAlert('최소 7일 이상이어야 합니다.');
            return;
        }
        if (!confirm(days + '일 이전 방문 로그를 삭제합니다.\n삭제된 로그는 복구할 수 없습니다.')) {
            return;
        }

        MubloRequest.requestJson('/admin/visitor-stats/api/purge', { days: days }, { method: 'POST' })
            .then(function (res) {
                MubloRequest.showAlert(res.message || '로그가 삭제되었습니다.');
            });
    });
});
</script>
