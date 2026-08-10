<?php $currentTab = 'conversions'; ?>
<?php include __DIR__ . '/_nav.php'; ?>

<!-- 필터 -->
<div class="d-flex justify-content-end mb-3 gap-2 flex-wrap">
    <select id="cv-source" class="form-select" style="width:auto;">
        <option value="">전체 소스</option>
    </select>
    <select id="cv-campaign" class="form-select" style="width:auto;">
        <option value="">전체 캠페인</option>
        <option value="__direct__">(직접접속)</option>
    </select>
    <select id="cv-period" class="form-select" style="width:auto;">
        <option value="today">오늘</option>
        <option value="last_7_days" selected>최근 7일</option>
        <option value="last_30_days">최근 30일</option>
        <option value="this_month">이번 달</option>
    </select>
</div>

<!-- 테이블 -->
<div class="card mb-3">
    <div class="card-hero">
        <i class="bi bi-check2-circle text-chart-emerald"></i>
        <span>전환 내역</span>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover vs-table mb-0">
                <thead>
                    <tr>
                        <th style="width:70px;">#</th>
                        <th>일시</th>
                        <th>소스</th>
                        <th>캠페인키</th>
                        <th style="width:90px;">상태</th>
                    </tr>
                </thead>
                <tbody id="cv-body">
                    <tr><td colspan="5" class="text-center text-muted py-3">로딩 중...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- 페이지네이션 -->
<div class="d-flex justify-content-center" id="cv-pagination"></div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var periodEl = document.getElementById('cv-period');
    var sourceEl = document.getElementById('cv-source');
    var campaignEl = document.getElementById('cv-campaign');
    var currentPage = 1;

    function load(page) {
        page = page || 1;
        currentPage = page;

        var params = { period: periodEl.value, page: page };
        if (sourceEl.value) {
            // 소스는 타입+라벨 한 쌍이 한 갈래다 (같은 타입 안의 폼별·상품군별 구분).
            var picked = sourceEl.options[sourceEl.selectedIndex];
            params.source_type = sourceEl.value;
            params.source_label = picked.dataset.label;
        }
        if (campaignEl.value === '__direct__') {
            params.campaign_key = '';
        } else if (campaignEl.value) {
            params.campaign_key = campaignEl.value;
        }

        MubloRequest.requestJson('/admin/visitor-stats/api/conversions', params, { method: 'POST' })
            .then(function (res) {
                var d = res.data || {};
                renderTable(d.items || []);
                renderPagination(d.totalItems || 0, d.currentPage || 1, d.totalPages || 1);

                // 소스 필터 옵션 채우기 (최초 1회)
                if (sourceEl.options.length <= 1 && d.sources) {
                    d.sources.forEach(function (s) {
                        var opt = document.createElement('option');
                        opt.value = s.source_type;
                        // 라벨 없이 기록된 갈래는 빈 문자열로 구분한다 (null 과 다름)
                        opt.dataset.label = s.has_label ? s.source_label : '';
                        opt.textContent = s.source_label || s.source_type;
                        sourceEl.appendChild(opt);
                    });
                }
            });
    }

    function renderTable(items) {
        var tbody = document.getElementById('cv-body');
        if (!items.length) {
            tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-3">전환 내역이 없습니다.</td></tr>';
            return;
        }

        tbody.innerHTML = items.map(function (item) {
            var ck = item.campaign_key
                ? MubloRequest.escapeHtml(item.campaign_key)
                : '<span class="text-muted">(직접접속)</span>';
            var isSuccess = item.status === 'success';
            var badge = '<span class="badge ' + (isSuccess ? 'text-bg-success' : 'text-bg-secondary') + '">'
                + MubloRequest.escapeHtml(item.status || '-') + '</span>';
            return '<tr>'
                + '<td>' + item.conversion_id + '</td>'
                + '<td>' + MubloRequest.escapeHtml(item.occurred_at || '') + '</td>'
                + '<td>' + MubloRequest.escapeHtml(item.source_label || item.source_type || '-') + '</td>'
                + '<td>' + ck + '</td>'
                + '<td>' + badge + '</td>'
                + '</tr>';
        }).join('');
    }

    function renderPagination(total, page, totalPages) {
        var el = document.getElementById('cv-pagination');
        if (totalPages <= 1) { el.innerHTML = ''; return; }

        var html = '<nav><ul class="pagination pagination-sm">';
        if (page > 1) html += '<li class="page-item"><a class="page-link" href="#" data-page="' + (page - 1) + '">&laquo;</a></li>';

        var start = Math.max(1, page - 2);
        var end = Math.min(totalPages, page + 2);
        for (var i = start; i <= end; i++) {
            html += '<li class="page-item' + (i === page ? ' active' : '') + '">'
                + '<a class="page-link" href="#" data-page="' + i + '">' + i + '</a></li>';
        }

        if (page < totalPages) html += '<li class="page-item"><a class="page-link" href="#" data-page="' + (page + 1) + '">&raquo;</a></li>';
        html += '</ul></nav>';

        el.innerHTML = html;
        el.querySelectorAll('[data-page]').forEach(function (a) {
            a.addEventListener('click', function (e) {
                e.preventDefault();
                load(parseInt(this.dataset.page));
            });
        });
    }

    periodEl.addEventListener('change', function () { load(1); });
    sourceEl.addEventListener('change', function () { load(1); });
    campaignEl.addEventListener('change', function () { load(1); });

    load(1);
});
</script>
