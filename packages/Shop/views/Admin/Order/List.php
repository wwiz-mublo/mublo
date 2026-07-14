<?php
/**
 * 주문 목록 (FSM 기반)
 *
 * @var string $pageTitle
 * @var array $orders
 * @var array $pagination
 * @var array $filters
 * @var array $orderStatusOptions FSM 상태 옵션 [id => label]
 * @var int $perPage 페이지당 개수 (0 = 전체)
 * @var bool $hasFilter 검색/필터 적용 여부
 */

$orders             = $orders ?? [];
$pagination         = $pagination ?? [];
$filters            = $filters ?? [];
$orderStatusOptions = $orderStatusOptions ?? [];
$orderStatusColors  = $orderStatusColors ?? [];
$perPage            = (int) ($perPage ?? 20);
$hasFilter          = $hasFilter ?? false;
$shippedSet             = $shippedSet ?? [];             // [order_no => true] 운송장 보유
$deliveryEditableStates = $deliveryEditableStates ?? []; // [state_id => true] 운송장 입력 가능 상태
$deliveryCompanies      = $deliveryCompanies ?? [];      // 택배사 목록 (등록 모달 드롭다운)

// 상세 진입 시 현재 검색/페이지 상태를 실어보내, 목록 복귀 시 복원되게 함
$listQuery = http_build_query(array_filter([
    'keyword'        => $filters['keyword'] ?? '',
    'search_field'   => $filters['search_field'] ?? '',
    'order_status'   => $filters['order_status'] ?? '',
    'date_from'      => $filters['date_from'] ?? '',
    'date_to'        => $filters['date_to'] ?? '',
    'payment_method' => $filters['payment_method'] ?? '',
    'per_page'       => $perPage !== 20 ? $perPage : '',
    'page'           => ($pagination['currentPage'] ?? 1) > 1 ? $pagination['currentPage'] : '',
], static fn($v) => $v !== '' && $v !== null));
$detailSuffix = $listQuery !== '' ? '?' . htmlspecialchars($listQuery) : '';
?>
<div class="page-container">
    <div class="page-title">
        <div class="page-title-text">
            <h3><?= htmlspecialchars($pageTitle ?? '주문 관리') ?></h3>
            <p>고객의 주문을 조회하고 처리합니다.</p>
        </div>
        <!-- 상단 우측 액션: 엑셀 다운로드 / 운송장 업로드 -->
        <div class="page-title-actions">
            <button type="button" class="btn btn-sm btn-primary js-export-orders" title="현재 검색 조건에 해당하는 주문 전체를 엑셀로 내보냅니다. (행을 선택하면 선택분만)">
                <i class="bi bi-file-earmark-excel"></i> 엑셀 다운로드<span class="js-export-count small"></span>
            </button>
            <button type="button" class="btn btn-sm btn-info" id="btnUploadInvoices" title="다운로드한 엑셀에 택배사·송장번호를 채워 업로드하면 일괄 등록됩니다.">
                <i class="bi bi-upload"></i> 운송장 업로드
            </button>
            <input type="file" id="invoiceUploadInput" accept=".xlsx,.xls,.csv" style="display:none">
        </div>
    </div>

    <div class="page-block">
        <!-- 검색 영역 -->
        <form method="get" name="fsearch" id="fsearch" class="mb-2">
            <div class="row align-items-center gy-2 gy-xl-0">
                <div class="col-auto">
                    <span class="ov">
                        <span class="ov-txt"><a href="/admin/shop/orders">전체</a></span>
                        <span class="ov-num"><b><?= number_format($pagination['totalItems'] ?? 0) ?></b> 건</span>
                    </span>
                </div>
                <div class="col-auto">
                    <select name="per_page" class="form-select form-select-sm" onchange="document.getElementById('fsearch').submit()" title="페이지당 표시 개수">
                        <?php foreach ([20, 50, 100, 200] as $pp): ?>
                            <option value="<?= $pp ?>" <?= $perPage === $pp ? 'selected' : '' ?>><?= $pp ?>건</option>
                        <?php endforeach; ?>
                        <?php if ($hasFilter): ?>
                            <option value="0" <?= $perPage === 0 ? 'selected' : '' ?>>전체</option>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="col col-xl-auto ms-xl-auto">
                    <div class="row gx-2">
                        <div class="col col-xl-auto">
                            <select name="order_status" class="form-select form-select-sm">
                                <option value="">전체 상태</option>
                                <?php foreach ($orderStatusOptions as $val => $lbl): ?>
                                    <option value="<?= htmlspecialchars($val) ?>" <?= ($filters['order_status'] ?? '') === $val ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col col-xl-auto">
                            <input type="date" name="date_from" class="form-control form-control-sm" value="<?= htmlspecialchars($filters['date_from'] ?? '') ?>">
                        </div>
                        <div class="col-auto align-self-center">~</div>
                        <div class="col col-xl-auto">
                            <input type="date" name="date_to" class="form-control form-control-sm" value="<?= htmlspecialchars($filters['date_to'] ?? '') ?>">
                        </div>
                        <div class="col col-xl-auto">
                            <div class="search-wrapper">
                                <label for="search_keyword" class="visually-hidden">검색</label>
                                <input type="text" name="keyword" id="search_keyword" class="form-control form-control-sm"
                                       placeholder="주문번호/이름/연락처"
                                       value="<?= htmlspecialchars($filters['keyword'] ?? '') ?>">
                                <i class="bi bi-search search-icon"></i>
                                <?php if (!empty($filters['keyword'])): ?>
                                <i class="bi bi-x-lg search-reset-icon" onclick="location.href='/admin/shop/orders'"></i>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <button type="submit" class="btn btn-sm btn-default">
                                <i class="bi bi-search"></i> 검색
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </form>

        <!-- 목록 -->
        <form name="flist" id="flist">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="width:34px" class="text-center"><input type="checkbox" class="form-check-input" id="chkAll" title="전체 선택"></th>
                            <th class="text-nowrap">주문번호</th>
                            <th class="text-nowrap">주문인</th>
                            <th class="text-nowrap">수령인</th>
                            <th>상품</th>
                            <th class="text-end text-nowrap">주문금액</th>
                            <th class="text-end text-nowrap">배송비</th>
                            <th class="text-end text-nowrap">할인금액</th>
                            <th class="text-end text-nowrap">결제금액</th>
                            <th class="text-center text-nowrap">결제수단</th>
                            <th class="text-center text-nowrap">주문일시</th>
                            <th class="text-center text-nowrap">변경일시</th>
                            <th class="text-nowrap">관리</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($orders)): ?>
                            <tr><td colspan="13" class="text-center py-4 text-muted">주문 내역이 없습니다.</td></tr>
                        <?php else: ?>
                            <?php foreach ($orders as $order): ?>
                                <?php
                                    $rawStatus = $order['order_status'] ?? '';
                                    $statusLabel = $orderStatusOptions[$rawStatus] ?? $rawStatus ?: '-';
                                    $statusColor = $orderStatusColors[$rawStatus] ?? 'secondary';
                                    $itemCount = (int) ($order['item_count'] ?? 0);
                                    $firstName = $order['first_item_name'] ?? '';
                                ?>
                                <tr>
                                    <td class="text-center"><input type="checkbox" class="form-check-input order-check" name="chk[]" value="<?= htmlspecialchars($order['order_no']) ?>"></td>
                                    <td class="text-nowrap">
                                        <div><a href="/admin/shop/orders/<?= htmlspecialchars($order['order_no']) ?><?= $detailSuffix ?>"><?= htmlspecialchars($order['order_no']) ?></a></div>
                                        <div><span class="badge bg-<?= htmlspecialchars($statusColor) ?>-subtle text-<?= htmlspecialchars($statusColor) ?>-emphasis border border-<?= htmlspecialchars($statusColor) ?>-subtle"><?= htmlspecialchars($statusLabel) ?></span></div>
                                    </td>
                                    <td class="text-nowrap">
                                        <?php $ordererMemberId = (int) ($order['member_id'] ?? 0); ?>
                                        <div>
                                            <?= htmlspecialchars($order['orderer_name'] ?? '') ?: ($ordererMemberId > 0 ? '-' : '비회원') ?>
                                            <?php if ($ordererMemberId > 0): ?>
                                                <a href="/admin/member/edit/<?= $ordererMemberId ?>" target="_blank" rel="noopener" class="text-decoration-none small link-opacity-50 link-opacity-100-hover link-default" title="회원 상세 (새 창)"><i class="bi bi-person-circle"></i></a>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (!empty($order['orderer_phone'])): ?>
                                            <div class="text-muted small"><?= htmlspecialchars($order['orderer_phone']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-nowrap">
                                        <div>
                                            <?php
                                                $hasShipment = isset($shippedSet[$order['order_no'] ?? '']);
                                                $canInputShipment = isset($deliveryEditableStates[$order['order_status'] ?? '']);
                                            ?>
                                            <?= htmlspecialchars($order['recipient_name'] ?? '-') ?>
                                            <?php if ($hasShipment): ?>
                                                <i class="bi bi-truck text-success" title="운송장 등록됨"></i>
                                            <?php elseif ($canInputShipment): ?>
                                                <a href="#" class="text-secondary shipment-register-trigger"
                                                   data-order-no="<?= htmlspecialchars($order['order_no']) ?>"
                                                   data-recipient="<?= htmlspecialchars($order['recipient_name'] ?? '') ?>"
                                                   title="운송장 미등록 — 클릭하여 등록"><i class="bi bi-truck"></i></a>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (!empty($order['recipient_phone'])): ?>
                                            <div class="text-muted small"><?= htmlspecialchars($order['recipient_phone']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($itemCount > 0): ?>
                                            <a href="#" class="order-items-trigger text-decoration-none small link-opacity-50 link-opacity-100-hover link-default"
                                               data-order-no="<?= htmlspecialchars($order['order_no']) ?>"
                                               data-items='<?= htmlspecialchars(json_encode($order['items'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?>'
                                               title="상품 목록 보기"><i class="bi bi-box-seam"></i></a>
                                        <?php endif; ?>
                                        <?= htmlspecialchars($firstName ?: '-') ?>
                                        <?php if ($itemCount > 1): ?>
                                            <span class="badge text-bg-default ms-1">외 <?= $itemCount - 1 ?>건</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end text-nowrap"><?= number_format($order['total_price'] ?? 0) ?></td>
                                    <td class="text-end text-nowrap"><?php $sf = (int) ($order['shipping_fee'] ?? 0); ?><?= $sf > 0 ? number_format($sf) : '<span class="text-muted">무료</span>' ?></td>
                                    <td class="text-end text-nowrap"><?php $dc = (int) ($order['coupon_discount'] ?? 0) + (int) ($order['point_used'] ?? 0); ?><?= $dc > 0 ? number_format($dc) : '<span class="text-muted">없음</span>' ?></td>
                                    <td class="text-end text-nowrap text-primary fw-semibold"><?= number_format($order['final_amount'] ?? $order['total_price'] ?? 0) ?></td>
                                    <td class="text-center text-nowrap">
                                        <div><?= htmlspecialchars($order['payment_method'] ?? '-') ?></div>
                                        <?php
                                            $cd = (int) ($order['coupon_discount'] ?? 0);
                                            $pu = (int) ($order['point_used'] ?? 0);
                                        ?>
                                        <?php if ($cd > 0 || $pu > 0): ?>
                                        <div class="small">
                                            <?php if ($cd > 0): ?><i class="bi bi-ticket-perforated text-muted" title="쿠폰 할인 <?= number_format($cd) ?>원"></i><?php endif; ?>
                                            <?php if ($pu > 0): ?><i class="bi bi-coin text-muted" title="포인트 사용 <?= number_format($pu) ?>원"></i><?php endif; ?>
                                        </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center text-nowrap">
                                        <?php
                                            $createdAt = $order['created_at'] ?? '';
                                            $ts = $createdAt ? strtotime($createdAt) : false;
                                        ?>
                                        <?php if ($ts): ?>
                                            <div><?= date('Y-m-d', $ts) ?></div>
                                            <div class="text-muted small"><?= date('H:i:s', $ts) ?></div>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center text-nowrap">
                                        <?php
                                            $updatedAt = $order['updated_at'] ?? '';
                                            $uts = $updatedAt ? strtotime($updatedAt) : false;
                                        ?>
                                        <?php if ($uts): ?>
                                            <div><?= date('Y-m-d', $uts) ?></div>
                                            <div class="text-muted small"><?= date('H:i:s', $uts) ?></div>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-nowrap"><a href="/admin/shop/orders/<?= htmlspecialchars($order['order_no']) ?><?= $detailSuffix ?>" class="btn btn-sm btn-default">상세</a></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- 하단: 엑셀 다운로드(상단 버튼과 동일 동작) + 페이지네이션 -->
            <div class="d-flex justify-content-between align-items-center my-2">
                <button type="button" class="btn btn-sm btn-primary js-export-orders" title="현재 검색 조건에 해당하는 주문 전체를 엑셀로 내보냅니다. (행을 선택하면 선택분만)">
                    <i class="bi bi-file-earmark-excel"></i> 엑셀 다운로드<span class="js-export-count small"></span>
                </button>
                <?php if (!empty($pagination) && ($pagination['totalPages'] ?? 1) > 1): ?>
                    <?= $this->pagination($pagination) ?>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- 출력 필드 선택 모달 (엑셀 다운로드 전 필드 지정) -->
<?php
    $exportDefaultCols = ['order_no', 'created_at', 'recipient_name', 'recipient_phone', 'shipping_zip', 'shipping_address', 'product_summary', 'courier', 'invoice_no'];
?>
<div class="modal fade" id="exportFieldsModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">엑셀 출력 필드 선택</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info py-2 px-3 small mb-3">
                    <i class="bi bi-info-circle"></i>
                    이 엑셀은 <strong>운송장 일괄 업로드 양식</strong>을 겸합니다. 다운로드한 파일의
                    <strong>택배사·송장번호</strong> 열을 채워 <strong>[운송장 업로드]</strong>로 다시 올리면
                    <strong>주문번호</strong> 기준으로 일괄 등록됩니다. (이 두 열을 해제하면 업로드 양식으로는 쓸 수 없습니다.)
                </div>
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-muted small" id="exportSelectedOrders">0건 주문 선택됨</span>
                    <div class="btn-group btn-group-sm">
                        <button type="button" class="btn btn-outline-secondary" id="exportFieldsAll">전체 선택</button>
                        <button type="button" class="btn btn-outline-secondary" id="exportFieldsNone">전체 해제</button>
                    </div>
                </div>
                <div class="row row-cols-1 row-cols-sm-2 g-1">
                    <?php foreach (\Mublo\Packages\Shop\Report\ShopOrderReportDefinition::FIELDS as $fKey => $fMeta): ?>
                    <div class="col">
                        <label class="d-flex align-items-center gap-2 py-1 m-0" style="cursor:pointer">
                            <input type="checkbox" class="form-check-input export-field" value="<?= htmlspecialchars($fKey) ?>" <?= in_array($fKey, $exportDefaultCols, true) ? 'checked' : '' ?>>
                            <span><?= htmlspecialchars($fMeta[0]) ?></span>
                        </label>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-bs-dismiss="modal">취소</button>
                <button type="button" class="btn btn-primary" id="btnConfirmExport">
                    <i class="bi bi-download"></i> 다운로드
                </button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const form = document.getElementById('flist');
    if (!form) return;

    const chkAll = document.getElementById('chkAll');
    const exportBtns = Array.from(document.querySelectorAll('.js-export-orders'));
    const countSpans = Array.from(document.querySelectorAll('.js-export-count'));

    const rowChecks = () => Array.from(form.querySelectorAll('.order-check'));

    function selected() {
        return rowChecks().filter(c => c.checked);
    }

    function refresh() {
        const sel = selected();
        const all = rowChecks();
        const countText = sel.length > 0 ? (' (' + sel.length + '건 선택)') : '';
        countSpans.forEach(s => { s.textContent = countText; });
        if (chkAll) {
            chkAll.checked = all.length > 0 && sel.length === all.length;
            chkAll.indeterminate = sel.length > 0 && sel.length < all.length;
        }
    }

    if (chkAll) {
        chkAll.addEventListener('change', function () {
            rowChecks().forEach(c => { c.checked = this.checked; });
            refresh();
        });
    }

    form.addEventListener('change', function (e) {
        if (e.target.classList.contains('order-check')) refresh();
    });

    async function downloadOrdersExcel(filters) {
        // CSRF 토큰 확보 (MubloRequest와 동일 엔드포인트)
        let token = '';
        try {
            const tRes = await fetch('/api/v1/csrf/token');
            const tJson = await tRes.json();
            token = tJson?.data?.token || '';
        } catch (e) { /* 계속 진행 — 서버가 거부하면 아래에서 처리 */ }

        const res = await fetch('/admin/report/shop_orders/download', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': token,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({
                format: 'xlsx',
                menuCode: 'K_Shop_005',
                filters: filters,
            }),
        });

        // 실패는 JSON(success:false)로 올 수 있음 — content-type으로 분기
        const ct = res.headers.get('Content-Type') || '';
        if (ct.includes('application/json')) {
            let msg = '다운로드에 실패했습니다.';
            try { const j = await res.json(); msg = j.message || j.error?.message || msg; } catch (e) {}
            alert(msg);
            return;
        }

        const blob = await res.blob();
        const disp = res.headers.get('Content-Disposition') || '';
        const m = disp.match(/filename="?([^"]+)"?/);
        const filename = m ? decodeURIComponent(m[1]) : ('orders_' + Date.now() + '.xlsx');
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
    }

    // 엑셀 다운로드: 출력 필드 선택 모달을 연다.
    // 기본 대상은 "현재 검색 조건에 해당하는 주문 전체"(페이지 무관). 행을 선택했다면 선택분만.
    const exportModalEl = document.getElementById('exportFieldsModal');
    let exportModal = null;
    function getExportModal() {
        if (!exportModal && exportModalEl && window.bootstrap) {
            exportModal = new bootstrap.Modal(exportModalEl);
        }
        return exportModal;
    }
    const confirmExportBtn = document.getElementById('btnConfirmExport');
    const exportSelectedOrders = document.getElementById('exportSelectedOrders');
    const fieldChecks = () => Array.from(document.querySelectorAll('.export-field'));
    const totalOrders = <?= (int) ($pagination['totalItems'] ?? 0) ?>;

    // 현재 검색 조건(URL 쿼리)을 그대로 내보내기 필터로 사용 — 목록 화면과 동일한 결과 집합
    function currentSearchFilters() {
        const p = new URLSearchParams(window.location.search);
        const f = {};
        ['keyword', 'search_field', 'order_status', 'date_from', 'date_to', 'payment_method'].forEach(function (k) {
            const v = p.get(k);
            if (v) f[k] = v;
        });
        return f;
    }

    function updateExportScopeLabel() {
        if (!exportSelectedOrders) return;
        const sel = selected().length;
        exportSelectedOrders.textContent = sel > 0
            ? ('선택한 ' + sel + '건을 출력합니다')
            : ('검색 결과 전체 ' + totalOrders.toLocaleString() + '건을 출력합니다');
    }

    exportBtns.forEach(function (btn) {
        btn.addEventListener('click', function () {
            updateExportScopeLabel();
            const m = getExportModal();
            if (m) m.show();
        });
    });

    // 모달 필드 전체 선택 / 전체 해제
    const fieldAllBtn = document.getElementById('exportFieldsAll');
    const fieldNoneBtn = document.getElementById('exportFieldsNone');
    if (fieldAllBtn) fieldAllBtn.addEventListener('click', () => fieldChecks().forEach(c => { c.checked = true; }));
    if (fieldNoneBtn) fieldNoneBtn.addEventListener('click', () => fieldChecks().forEach(c => { c.checked = false; }));

    // 모달 확인 → 실제 다운로드
    if (confirmExportBtn) {
        confirmExportBtn.addEventListener('click', async function () {
            const columns = fieldChecks().filter(c => c.checked).map(c => c.value);
            if (columns.length === 0) {
                alert('출력할 필드를 하나 이상 선택해주세요.');
                return;
            }

            // 검색 조건 전체가 기본 대상. 선택된 행이 있으면 그 범위로 좁힌다.
            const filters = Object.assign({ columns: columns }, currentSearchFilters());
            const orderNos = selected().map(c => c.value);
            if (orderNos.length > 0) filters.order_nos = orderNos;

            confirmExportBtn.disabled = true;
            const orig = confirmExportBtn.innerHTML;
            confirmExportBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> 생성 중...';
            try {
                await downloadOrdersExcel(filters);
                const m = getExportModal();
                if (m) m.hide();
            } catch (e) {
                alert('다운로드 중 오류가 발생했습니다.');
            } finally {
                confirmExportBtn.innerHTML = orig;
                confirmExportBtn.disabled = false;
            }
        });
    }

    // 운송장 엑셀 업로드
    const uploadBtn = document.getElementById('btnUploadInvoices');
    const uploadInput = document.getElementById('invoiceUploadInput');
    if (uploadBtn && uploadInput) {
        uploadBtn.addEventListener('click', () => uploadInput.click());
        uploadInput.addEventListener('change', async function () {
            const f = uploadInput.files[0];
            if (!f) return;

            uploadBtn.disabled = true;
            const orig = uploadBtn.innerHTML;
            uploadBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> 업로드 중...';
            try {
                let token = '';
                try { const t = await fetch('/api/v1/csrf/token'); token = (await t.json())?.data?.token || ''; } catch (e) {}

                const fd = new FormData();
                fd.append('file', f);
                const res = await fetch('/admin/shop/orders/upload-invoices', {
                    method: 'POST',
                    headers: { 'X-CSRF-Token': token, 'X-Requested-With': 'XMLHttpRequest' },
                    body: fd,
                });
                const json = await res.json().catch(() => ({}));

                if (json.result !== 'success') {
                    alert(json.message || '업로드에 실패했습니다.');
                    return;
                }

                const d = json.data || {};
                let msg = (d.success || 0) + '건 송장 등록 완료';
                if (Array.isArray(d.failed) && d.failed.length > 0) {
                    msg += '\n\n실패 ' + d.failed.length + '건:\n'
                        + d.failed.slice(0, 15).map(x => '· ' + (x.order_no || ('행 ' + x.line)) + ': ' + x.reason).join('\n');
                    if (d.failed.length > 15) msg += '\n…외 ' + (d.failed.length - 15) + '건';
                }
                alert(msg);
                location.reload();
            } catch (e) {
                alert('업로드 중 오류가 발생했습니다.');
            } finally {
                uploadBtn.innerHTML = orig;
                uploadBtn.disabled = false;
                uploadInput.value = '';
            }
        });
    }

    refresh();
})();
</script>

<!-- 주문 상품 조회 모달 -->
<div class="modal fade" id="orderItemsModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">주문 상품 <small class="text-muted ms-1" id="orderItemsModalNo"></small></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <ul class="list-group list-group-flush" id="orderItemsModalBody"></ul>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const modalEl = document.getElementById('orderItemsModal');
    if (!modalEl) return;
    const bodyEl = document.getElementById('orderItemsModalBody');
    const noEl = document.getElementById('orderItemsModalNo');
    let modal = null;

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        }[c]));
    }

    function renderItem(it) {
        const gid = parseInt(it.goods_id, 10) || 0;
        const img = it.goods_image
            ? '<img src="' + esc(it.goods_image) + '" alt="" style="width:50px;height:50px;object-fit:cover;border-radius:4px;flex:none">'
            : '<span class="d-inline-flex align-items-center justify-content-center text-muted" style="width:50px;height:50px;background:#f1f3f5;border-radius:4px;flex:none"><i class="bi bi-image"></i></span>';
        const qty = parseInt(it.quantity, 10) || 0;
        const sub = it.option_name
            ? esc(it.option_name) + ' ' + qty + '개'
            : '수량 ' + qty + '개';
        const manage = gid
            ? '<a href="/admin/shop/products/' + gid + '/edit" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary" title="상품 관리"><i class="bi bi-gear"></i></a>'
            : '';
        const viewBtn = gid
            ? '<a href="/shop/products/' + gid + '" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary" title="상품 보기"><i class="bi bi-box-arrow-up-right"></i></a>'
            : '';
        return '<li class="list-group-item d-flex align-items-center gap-2">'
            + img
            + '<div class="flex-grow-1" style="min-width:0">'
            + '<div class="lh-sm">' + esc(it.goods_name) + '</div>'
            + '<div class="text-muted small">' + sub + '</div>'
            + '</div>'
            + '<div class="btn-group btn-group-sm" style="flex:none">' + manage + viewBtn + '</div>'
            + '</li>';
    }

    document.querySelectorAll('.order-items-trigger').forEach(function (trigger) {
        trigger.addEventListener('click', function (e) {
            e.preventDefault();
            let items = [];
            try { items = JSON.parse(this.dataset.items || '[]'); } catch (_) {}
            noEl.textContent = this.dataset.orderNo || '';
            bodyEl.innerHTML = items.length
                ? items.map(renderItem).join('')
                : '<li class="list-group-item text-muted text-center py-4">상품이 없습니다.</li>';
            if (!modal) modal = new bootstrap.Modal(modalEl);
            modal.show();
        });
    });
})();
</script>

<!-- 운송장 등록 모달 (목록에서 바로 입력) -->
<div class="modal fade" id="shipmentRegisterModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">운송장 등록 <small class="text-muted ms-1" id="shipmentModalRecipient"></small></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="shipmentModalOrderNo" value="">
                <div class="mb-3">
                    <label class="form-label">택배사</label>
                    <select id="shipmentModalCompany" class="form-select">
                        <option value="">택배사 선택</option>
                        <?php foreach ($deliveryCompanies as $dc): ?>
                        <option value="<?= (int) $dc['company_id'] ?>"><?= htmlspecialchars($dc['company_name'] ?? '') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">송장번호 <span class="text-danger">*</span></label>
                    <input type="text" id="shipmentModalInvoice" class="form-control" placeholder="송장번호">
                </div>
                <div class="mb-2">
                    <label class="form-label">메모 (선택)</label>
                    <input type="text" id="shipmentModalMemo" class="form-control" placeholder="메모">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">취소</button>
                <button type="button" class="btn btn-primary" id="shipmentModalSubmit"><i class="bi bi-plus-lg"></i> 등록</button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const modalEl = document.getElementById('shipmentRegisterModal');
    if (!modalEl) return;
    let modal = null;

    document.querySelectorAll('.shipment-register-trigger').forEach(function (trigger) {
        trigger.addEventListener('click', function (e) {
            e.preventDefault();
            document.getElementById('shipmentModalOrderNo').value = this.dataset.orderNo || '';
            document.getElementById('shipmentModalRecipient').textContent = this.dataset.recipient || '';
            document.getElementById('shipmentModalCompany').value = '';
            document.getElementById('shipmentModalInvoice').value = '';
            document.getElementById('shipmentModalMemo').value = '';
            if (!modal) modal = new bootstrap.Modal(modalEl);
            modal.show();
        });
    });

    document.getElementById('shipmentModalSubmit').addEventListener('click', function () {
        const orderNo = document.getElementById('shipmentModalOrderNo').value;
        const companyId = document.getElementById('shipmentModalCompany').value;
        const invoiceNo = document.getElementById('shipmentModalInvoice').value.trim();
        const memo = document.getElementById('shipmentModalMemo').value.trim();

        if (!invoiceNo) { alert('송장번호를 입력해주세요.'); return; }

        MubloRequest.requestJson('/admin/shop/orders/' + orderNo + '/shipments', {
            company_id: companyId ? parseInt(companyId) : null,
            invoice_no: invoiceNo,
            admin_memo: memo
        }).then(function () {
            location.reload();
        });
    });
})();
</script>
