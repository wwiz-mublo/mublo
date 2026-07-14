<?php $currentTab = 'conversions'; ?>
<?php include __DIR__ . '/_nav.php'; ?>

<!-- 필터 -->
<div class="d-flex justify-content-end mb-3 gap-2 flex-wrap">
    <select id="cv-form" class="form-select" style="width:auto;">
        <option value="">전체 폼</option>
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
                        <th>폼 제목</th>
                        <th>캠페인키</th>
                        <th style="width:120px;">IP</th>
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
    var formEl = document.getElementById('cv-form');
    var campaignEl = document.getElementById('cv-campaign');
    var currentPage = 1;

    function load(page) {
        page = page || 1;
        currentPage = page;

        var params = { period: periodEl.value, page: page };
        if (formEl.value) params.form_id = parseInt(formEl.value);
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

                // 폼 필터 옵션 채우기 (최초 1회)
                if (formEl.options.length <= 1 && d.forms) {
                    d.forms.forEach(function (f) {
                        var opt = document.createElement('option');
                        opt.value = f.form_id;
                        opt.textContent = f.form_name;
                        formEl.appendChild(opt);
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
            var ck = item.campaign_key || '<span class="text-muted">(직접접속)</span>';
            return '<tr>'
                + '<td>' + item.submission_id + '</td>'
                + '<td>' + item.created_at + '</td>'
                + '<td>' + (item.form_name || '<span class="text-muted">-</span>') + '</td>'
                + '<td>' + ck + '</td>'
                + '<td>' + (item.ip_address || '-') + '</td>'
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
    formEl.addEventListener('change', function () { load(1); });
    campaignEl.addEventListener('change', function () { load(1); });

    load(1);
});
</script>
