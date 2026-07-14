<?php $currentTab = 'realtime'; ?>
<?php include __DIR__ . '/_nav.php'; ?>

<div class="row g-3 mb-3">
    <div class="col-6 col-md-4">
        <div class="card h-100 vs-metric">
            <div class="card-body text-center">
                <div class="text-muted small">최근 5분</div>
                <div class="vs-metric-val text-primary" id="rt-recent">-</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="card h-100 vs-metric">
            <div class="card-body text-center">
                <div class="text-muted small">오늘 방문자</div>
                <div class="vs-metric-val" id="rt-today-visitors">-</div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="card h-100 vs-metric">
            <div class="card-body text-center">
                <div class="text-muted small">오늘 페이지뷰</div>
                <div class="vs-metric-val" id="rt-today-pv">-</div>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-hero">
        <i class="bi bi-list-ul text-chart-indigo"></i>
        <span>최근 방문 로그</span>
        <span class="badge text-bg-default ms-auto" id="rt-refresh-timer">30초 후 새로고침</span>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover mb-0 vs-table">
                <thead>
                    <tr>
                        <th>시간</th>
                        <th>IP</th>
                        <th>페이지</th>
                        <th>브라우저</th>
                        <th>디바이스</th>
                        <th>유입</th>
                        <th>k</th>
                    </tr>
                </thead>
                <tbody id="rt-log-body">
                    <tr><td colspan="7" class="text-center text-muted py-3">로딩 중...</td></tr>
                </tbody>
            </table>
        </div>
        <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center pt-3 mt-3 border-top">
            <div class="small text-muted" id="rt-log-page-info">-</div>
            <div class="btn-group btn-group-sm" role="group" aria-label="실시간 로그 페이지">
                <button type="button" class="btn btn-outline-secondary" id="rt-log-prev">이전</button>
                <button type="button" class="btn btn-outline-secondary" id="rt-log-next">다음</button>
            </div>
        </div>
    </div>
</div>

<div class="card mt-3 d-none" id="rt-ip-history-card">
    <div class="card-hero">
        <i class="bi bi-clock-history text-chart-indigo"></i>
        <span id="rt-ip-history-title">IP 접속 기록</span>
        <span class="text-muted small ms-2" id="rt-ip-history-meta">최근 1개월</span>
        <button type="button" class="btn btn-sm btn-outline-secondary ms-auto" id="rt-ip-history-close" title="닫기" aria-label="닫기">
            <i class="bi bi-x-lg"></i>
        </button>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover mb-0 vs-table">
                <thead>
                    <tr>
                        <th>접속 시간</th>
                        <th>페이지</th>
                        <th>브라우저</th>
                        <th>OS</th>
                        <th>디바이스</th>
                        <th>유입</th>
                        <th>캠페인</th>
                        <th>구분</th>
                    </tr>
                </thead>
                <tbody id="rt-ip-history-body">
                    <tr><td colspan="8" class="text-center text-muted py-3">IP를 선택해 주세요.</td></tr>
                </tbody>
            </table>
        </div>
        <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center pt-3 mt-3 border-top">
            <div class="small text-muted" id="rt-ip-page-info">-</div>
            <div class="btn-group btn-group-sm" role="group" aria-label="IP 접속 기록 페이지">
                <button type="button" class="btn btn-outline-secondary" id="rt-ip-prev">이전</button>
                <button type="button" class="btn btn-outline-secondary" id="rt-ip-next">다음</button>
            </div>
        </div>
    </div>
</div>

<style>
.vs-ip-link { text-decoration: none; vertical-align: baseline; }
.vs-ip-link:hover code { text-decoration: underline; }
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var refreshInterval = 30;
    var countdown = refreshInterval;
    var timerEl = document.getElementById('rt-refresh-timer');
    var logPage = 1;
    var logTotalPages = 1;
    var logPerPage = 30;
    var logRequestId = 0;
    var logPageInfo = document.getElementById('rt-log-page-info');
    var logPrevBtn = document.getElementById('rt-log-prev');
    var logNextBtn = document.getElementById('rt-log-next');
    var ipHistoryCard = document.getElementById('rt-ip-history-card');
    var ipHistoryTitle = document.getElementById('rt-ip-history-title');
    var ipHistoryMeta = document.getElementById('rt-ip-history-meta');
    var ipHistoryBody = document.getElementById('rt-ip-history-body');
    var ipHistoryClose = document.getElementById('rt-ip-history-close');
    var ipPageInfo = document.getElementById('rt-ip-page-info');
    var ipPrevBtn = document.getElementById('rt-ip-prev');
    var ipNextBtn = document.getElementById('rt-ip-next');
    var selectedIp = '';
    var ipPage = 1;
    var ipTotalPages = 1;
    var ipPerPage = 30;
    var ipRequestId = 0;

    function loadRealtime() {
        var requestId = ++logRequestId;

        MubloRequest.requestJson('/admin/visitor-stats/api/realtime', {
            page: logPage,
            per_page: logPerPage
        }, { method: 'POST' })
            .then(function (res) {
                if (requestId !== logRequestId) {
                    return;
                }

                var d = res.data || {};
                document.getElementById('rt-recent').textContent = (d.recent5min || 0).toLocaleString('ko-KR');
                document.getElementById('rt-today-visitors').textContent = (d.todayVisitors || 0).toLocaleString('ko-KR');
                document.getElementById('rt-today-pv').textContent = (d.todayPageviews || 0).toLocaleString('ko-KR');
                renderLogs(d.recentLogs || []);
                renderPagination(d.pagination || {}, 'log');
            });
    }

    function renderLogs(logs) {
        var tbody = document.getElementById('rt-log-body');
        if (logs.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-3">오늘 방문 기록이 없습니다.</td></tr>';
            return;
        }

        var html = '';
        logs.forEach(function (log) {
            var time = (log.created_at || '').substring(11, 19);
            var ipRaw = log.ip_address || '';
            var ip = escapeHtml(ipRaw);
            var ipAttr = escapeAttr(ipRaw);
            var page = escapeHtml(truncate(log.landing_url || '/', 40));
            var browser = escapeHtml(log.browser || '');
            var device = escapeHtml(log.device || '');
            var refType = escapeHtml(log.referer_type || 'direct');
            var campaignRaw = log.campaign_key || '';
            var campaign = escapeHtml(campaignRaw);
            var campaignAttr = escapeAttr(campaignRaw);

            html += '<tr>';
            html += '<td class="text-nowrap">' + time + '</td>';
            html += '<td class="text-nowrap"><button type="button" class="btn btn-link btn-sm p-0 vs-ip-link" data-ip-log="1" data-ip="' + ipAttr + '" title="이 IP의 최근 1개월 접속 기록 보기"><code>' + ip + '</code></button></td>';
            html += '<td title="' + escapeAttr(log.landing_url || '') + '">' + page + '</td>';
            html += '<td>' + browser + '</td>';
            html += '<td>' + deviceBadge(device) + '</td>';
            html += '<td>' + refTypeBadge(refType) + '</td>';
            html += '<td class="text-nowrap">' + (campaign ? '<span class="badge text-bg-light" title="k=' + campaignAttr + '">' + campaign + '</span>' : '-') + '</td>';
            html += '</tr>';
        });
        tbody.innerHTML = html;
    }

    function renderPagination(pagination, type) {
        var currentPage = Number(pagination.currentPage || 1);
        var totalPages = Number(pagination.totalPages || 1);
        var totalItems = Number(pagination.totalItems || 0);
        var perPage = Number(pagination.perPage || 30);
        var info = '총 ' + totalItems.toLocaleString('ko-KR') + '건 · '
            + currentPage.toLocaleString('ko-KR') + ' / ' + totalPages.toLocaleString('ko-KR') + ' 페이지'
            + ' · ' + perPage.toLocaleString('ko-KR') + '건씩';

        if (type === 'ip') {
            ipPage = currentPage;
            ipTotalPages = totalPages;
            ipPageInfo.textContent = info;
            ipPrevBtn.disabled = ipPage <= 1;
            ipNextBtn.disabled = ipPage >= ipTotalPages;
            return;
        }

        logPage = currentPage;
        logTotalPages = totalPages;
        logPageInfo.textContent = info;
        logPrevBtn.disabled = logPage <= 1;
        logNextBtn.disabled = logPage >= logTotalPages;
    }

    function moveLogPage(nextPage) {
        nextPage = Math.max(1, Math.min(logTotalPages, nextPage));
        if (nextPage === logPage) {
            return;
        }

        logPage = nextPage;
        resetRefreshCountdown();
        loadRealtime();
    }

    function loadIpLogs(ip, page) {
        if (!ip) {
            return;
        }

        selectedIp = ip;
        ipPage = Math.max(1, page || 1);
        var requestId = ++ipRequestId;
        ipHistoryCard.classList.remove('d-none');
        ipHistoryTitle.textContent = ip + ' 접속 기록';
        ipHistoryMeta.textContent = '최근 1개월 조회 중...';
        ipHistoryBody.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-3">조회 중...</td></tr>';
        ipPageInfo.textContent = '-';
        ipPrevBtn.disabled = true;
        ipNextBtn.disabled = true;

        MubloRequest.requestJson('/admin/visitor-stats/api/ip-logs', {
            ip_address: ip,
            page: ipPage,
            per_page: ipPerPage
        }, { method: 'POST' })
            .then(function (res) {
                if (requestId !== ipRequestId) {
                    return;
                }
                renderIpLogs(res.data || {});
            })
            .catch(function (error) {
                if (requestId !== ipRequestId) {
                    return;
                }
                var message = escapeHtml((error && error.message) ? error.message : '접속 기록을 불러오지 못했습니다.');
                ipHistoryMeta.textContent = '최근 1개월';
                ipHistoryBody.innerHTML = '<tr><td colspan="8" class="text-center text-danger py-3">' + message + '</td></tr>';
            });
    }

    function renderIpLogs(data) {
        var items = data.items || [];
        var period = (data.startDate || '') + ' ~ ' + (data.endDate || '');
        ipHistoryMeta.textContent = period;
        renderPagination(data.pagination || {}, 'ip');

        if (items.length === 0) {
            ipHistoryBody.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-3">최근 1개월 접속 기록이 없습니다.</td></tr>';
            return;
        }

        var html = '';
        items.forEach(function (log) {
            var createdAt = escapeHtml(log.created_at || '');
            var pageTitle = escapeAttr(log.landing_url || '');
            var page = escapeHtml(truncate(log.landing_url || '/', 56));
            var browser = escapeHtml(log.browser || '');
            var os = escapeHtml(log.os || '');
            var device = escapeHtml(log.device || '');
            var refType = escapeHtml(log.referer_type || 'direct');
            var campaign = escapeHtml(log.campaign_key || '');
            var visitType = Number(log.is_new || 0) === 1 ? '신규' : '재방문';
            var visitClass = visitType === '신규' ? 'primary' : 'secondary';

            html += '<tr>';
            html += '<td class="text-nowrap">' + createdAt + '</td>';
            html += '<td title="' + pageTitle + '">' + page + '</td>';
            html += '<td>' + browser + '</td>';
            html += '<td>' + os + '</td>';
            html += '<td>' + deviceBadge(device) + '</td>';
            html += '<td>' + refTypeBadge(refType) + '</td>';
            html += '<td>' + (campaign ? '<span class="badge text-bg-light">' + campaign + '</span>' : '-') + '</td>';
            html += '<td><span class="badge text-bg-' + visitClass + '">' + visitType + '</span></td>';
            html += '</tr>';
        });
        ipHistoryBody.innerHTML = html;
    }

    function moveIpPage(nextPage) {
        nextPage = Math.max(1, Math.min(ipTotalPages, nextPage));
        if (!selectedIp || nextPage === ipPage) {
            return;
        }
        loadIpLogs(selectedIp, nextPage);
    }

    function resetRefreshCountdown() {
        countdown = refreshInterval;
        timerEl.textContent = countdown + '초 후 새로고침';
    }

    function deviceBadge(device) {
        var cls = device === 'mobile' ? 'warning' : device === 'tablet' ? 'info' : 'secondary';
        return '<span class="badge text-bg-' + cls + '">' + device + '</span>';
    }

    function refTypeBadge(type) {
        var map = { direct: 'secondary', search: 'primary', social: 'danger', external: 'success' };
        var cls = map[type] || 'secondary';
        return '<span class="badge text-bg-' + cls + '">' + type + '</span>';
    }

    function truncate(str, len) {
        return str.length > len ? str.substring(0, len) + '...' : str;
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function escapeAttr(str) {
        return escapeHtml(str).replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    document.addEventListener('click', function (event) {
        var target = event.target;
        if (!target || target.nodeType !== 1) {
            target = target && target.parentElement ? target.parentElement : null;
        }
        var button = target && target.closest ? target.closest('[data-ip-log]') : null;
        if (!button) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();
        loadIpLogs(button.getAttribute('data-ip') || button.textContent.trim(), 1);
    }, true);

    logPrevBtn.addEventListener('click', function () {
        moveLogPage(logPage - 1);
    });
    logNextBtn.addEventListener('click', function () {
        moveLogPage(logPage + 1);
    });
    ipPrevBtn.addEventListener('click', function () {
        moveIpPage(ipPage - 1);
    });
    ipNextBtn.addEventListener('click', function () {
        moveIpPage(ipPage + 1);
    });
    ipHistoryClose.addEventListener('click', function () {
        ipRequestId++;
        selectedIp = '';
        ipHistoryCard.classList.add('d-none');
    });

    // 자동 새로고침
    loadRealtime();

    setInterval(function () {
        countdown--;
        if (countdown <= 0) {
            countdown = refreshInterval;
            loadRealtime();
        }
        timerEl.textContent = countdown + '초 후 새로고침';
    }, 1000);
});
</script>
