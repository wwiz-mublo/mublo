<?php $currentTab = 'pages'; ?>
<?php include __DIR__ . '/_nav.php'; ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div></div>
    <select id="vs-period" class="form-select" style="width:auto;">
        <option value="today">오늘</option>
        <option value="last_7_days" selected>최근 7일</option>
        <option value="last_30_days">최근 30일</option>
        <option value="this_month">이번 달</option>
    </select>
</div>

<div class="card">
    <div class="card-hero">
        <i class="bi bi-file-earmark-text text-chart-emerald"></i>
        <span>페이지별 통계</span>
        <span class="text-muted small fw-normal ms-auto" id="vs-page-info"></span>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover mb-0 vs-table">
                <thead>
                    <tr>
                        <th style="width:50px;">#</th>
                        <th>페이지 URL</th>
                        <th style="width:120px;" class="text-end">페이지뷰</th>
                        <th style="width:120px;" class="text-end">방문자</th>
                    </tr>
                </thead>
                <tbody id="vs-page-body">
                    <tr><td colspan="4" class="text-center text-muted py-3">로딩 중...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
    <div class="card-footer" id="vs-page-pagination"></div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var periodEl = document.getElementById('vs-period');
    var currentPage = 1;

    function loadPages(page) {
        currentPage = page || 1;
        var period = periodEl.value;

        MubloRequest.requestJson('/admin/visitor-stats/api/pages', {
            period: period,
            page: currentPage
        }, { method: 'POST' })
            .then(function (res) {
                var d = res.data || {};
                renderTable(d.items || [], d);
                renderPagination(d);
            });
    }

    function renderTable(items, meta) {
        var tbody = document.getElementById('vs-page-body');
        var infoEl = document.getElementById('vs-page-info');

        if (items.length === 0) {
            tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-3">데이터가 없습니다.</td></tr>';
            infoEl.textContent = '';
            return;
        }

        var perPage = meta.perPage || 20;
        var offset = ((meta.currentPage || 1) - 1) * perPage;
        var html = '';

        items.forEach(function (item, i) {
            html += '<tr>';
            html += '<td class="text-muted">' + (offset + i + 1) + '</td>';
            html += '<td><code>' + escapeHtml(item.page_url || '') + '</code></td>';
            html += '<td class="text-end fw-semibold">' + Number(item.pageviews || 0).toLocaleString('ko-KR') + '</td>';
            html += '<td class="text-end">' + Number(item.visitors || 0).toLocaleString('ko-KR') + '</td>';
            html += '</tr>';
        });

        tbody.innerHTML = html;
        infoEl.textContent = '전체 ' + (meta.totalItems || 0).toLocaleString('ko-KR') + '개 페이지';
    }

    function renderPagination(meta) {
        var el = document.getElementById('vs-page-pagination');
        var total = meta.totalPages || 1;
        var current = meta.currentPage || 1;

        if (total <= 1) {
            el.innerHTML = '';
            return;
        }

        var html = '<nav><ul class="pagination pagination-sm mb-0 justify-content-center">';

        if (current > 1) {
            html += '<li class="page-item"><a class="page-link" href="#" data-page="' + (current - 1) + '">&laquo;</a></li>';
        }

        var start = Math.max(1, current - 4);
        var end = Math.min(total, start + 9);
        if (end - start < 9) start = Math.max(1, end - 9);

        for (var p = start; p <= end; p++) {
            var active = p === current ? ' active' : '';
            html += '<li class="page-item' + active + '"><a class="page-link" href="#" data-page="' + p + '">' + p + '</a></li>';
        }

        if (current < total) {
            html += '<li class="page-item"><a class="page-link" href="#" data-page="' + (current + 1) + '">&raquo;</a></li>';
        }

        html += '</ul></nav>';
        el.innerHTML = html;

        el.querySelectorAll('a[data-page]').forEach(function (a) {
            a.addEventListener('click', function (e) {
                e.preventDefault();
                loadPages(parseInt(this.dataset.page, 10));
            });
        });
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    periodEl.addEventListener('change', function () { loadPages(1); });
    loadPages(1);
});
</script>
