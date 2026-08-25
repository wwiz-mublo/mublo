<?php
/**
 * 쿠폰 목록
 * @var string $pageTitle
 * @var array $coupons 쿠폰 정책 목록
 * @var array $pagination
 * @var array $filters
 */

$coupons    = $coupons ?? [];
$pagination = $pagination ?? [];
$filters    = $filters ?? [];

// 발급 이력이 있는 정책은 삭제 불가(체크박스·삭제버튼 숨김) — 발급 쿠폰 FK가 CASCADE라 이력 보호
foreach ($coupons as &$c) {
    $c['_no_delete'] = ((int) ($c['issued_count'] ?? 0) > 0) ? 1 : 0;
}
unset($c);

// 적용 대상(coupon_method) 라벨 — 폼 select와 동일
$methodLabels = [
    'ORDER'    => '전체 (주문금액)',
    'GOODS'    => '특정 상품',
    'CATEGORY' => '특정 카테고리',
    'SHIPPING' => '배송비',
];

$columns = $this->columns()
    ->checkbox('chk', '', ['id_key' => 'coupon_group_id', 'skip_key' => '_no_delete', '_th_attr' => ['style' => 'width:40px', 'class' => 'text-center'], '_cell_attr' => ['class' => 'text-center']])
    ->add('coupon_group_id', '번호', ['_th_attr' => ['class' => 'text-nowrap', 'style' => 'width:60px'], '_td_attr' => ['class' => 'text-nowrap']])
    ->callback('name', '쿠폰명', function ($row) {
        $id = $row['coupon_group_id'];
        $name = htmlspecialchars($row['name'] ?? '');
        $html = '<a href="/admin/shop/coupons/' . $id . '/edit">' . $name . '</a>';
        $issued = (int) ($row['issued_count'] ?? 0);
        if ($issued > 0) {
            $html .= ' <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle ms-1" title="발급된 쿠폰 수 (삭제 불가)">발급 ' . $issued . '</span>';
        }
        return $html;
    })
    ->add('coupon_type', '유형', ['_th_attr' => ['style' => 'width:100px']])
    ->callback('coupon_method', '적용 대상', function ($row) use ($methodLabels) {
        $m = $row['coupon_method'] ?? '';
        return htmlspecialchars($methodLabels[$m] ?? ($m ?: '-'));
    }, ['_th_attr' => ['class' => 'text-nowrap', 'style' => 'width:120px'], '_td_attr' => ['class' => 'text-nowrap']])
    ->callback('discount', '할인', function ($row) {
        if (($row['discount_type'] ?? '') === 'PERCENTAGE') {
            return htmlspecialchars((string) ($row['discount_value'] ?? 0)) . '%';
        }
        return number_format($row['discount_value'] ?? 0) . '원';
    }, ['_th_attr' => ['class' => 'text-end', 'style' => 'width:120px'], '_td_attr' => ['class' => 'text-end']])
    ->callback('issue_period', '발행 기간', function ($row) {
        $start = htmlspecialchars($row['issue_start'] ?? '');
        $end = htmlspecialchars($row['issue_end'] ?? '');
        return '<div class="d-flex align-items-center justify-content-center">'
            . '<span class="text-end" style="flex:1">' . $start . '</span>'
            . '<span class="px-2">~</span>'
            . '<span class="text-start" style="flex:1">' . $end . '</span>'
            . '</div>';
    }, ['_th_attr' => ['class' => 'text-center'], '_td_attr' => ['class' => 'small']])
    ->callback('is_active', '상태', function ($row) {
        if ($row['is_active'] ?? false) {
            return '<span class="badge bg-success-subtle text-success-emphasis border border-success-subtle">활성</span>';
        }
        return '<span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle">비활성</span>';
    }, ['_th_attr' => ['class' => 'text-center', 'style' => 'width:80px'], '_td_attr' => ['class' => 'text-center']])
    ->actions('actions', '관리', function ($row) {
        $id = $row['coupon_group_id'];
        $name = htmlspecialchars($row['name'] ?? '');
        $html = '<a href="/admin/shop/coupons/' . $id . '/edit" class="btn btn-sm btn-default">수정</a>';
        if (empty($row['_no_delete'])) {
            $html .= ' <button type="button" class="btn btn-sm btn-default btn-delete" data-id="' . $id . '" data-name="' . $name . '">삭제</button>';
        }
        return $html;
    }, ['_th_attr' => ['class' => 'text-nowrap', 'style' => 'width:110px'], '_td_attr' => ['class' => 'text-nowrap']])
    ->build();
?>
<div class="page-container">
    <div class="page-title">
        <div class="page-title-text">
            <h3><?= htmlspecialchars($pageTitle ?? '할인쿠폰') ?></h3>
            <p>할인쿠폰 정책을 관리합니다.</p>
        </div>
        <div class="page-title-actions">
            <a href="/admin/shop/coupons/create" class="btn btn-sm btn-primary">
                <i class="bi bi-plus-lg"></i> 할인쿠폰 등록
            </a>
        </div>
    </div>

    <div class="page-block">
        <!-- 검색 영역 -->
        <form method="get" name="fsearch" id="fsearch" class="mb-2">
            <div class="row align-items-center gy-2 gy-xl-0">
                <div class="col-auto">
                    <span class="ov">
                        <span class="ov-txt"><a href="/admin/shop/coupons">전체</a></span>
                        <span class="ov-num"><b><?= number_format($pagination['totalItems'] ?? count($coupons)) ?></b> 건</span>
                    </span>
                </div>
                <div class="col col-xl-auto ms-xl-auto">
                    <div class="row gx-2">
                        <div class="col col-xl-auto">
                            <div class="search-wrapper">
                                <label for="search_keyword" class="visually-hidden">검색</label>
                                <input type="text" name="keyword" id="search_keyword" class="form-control form-control-sm"
                                       placeholder="쿠폰명 검색"
                                       value="<?= htmlspecialchars($filters['keyword'] ?? '') ?>">
                                <i class="bi bi-search search-icon"></i>
                                <?php if (!empty($filters['keyword'])): ?>
                                <i class="bi bi-x-lg search-reset-icon" onclick="location.href='/admin/shop/coupons'"></i>
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
                <?= $this->listRenderHelper
                    ->setColumns($columns)
                    ->setRows($coupons)
                    ->setSkin('table/basic')
                    ->setWrapAttr(['class' => 'table table-hover align-middle mb-0'])
                    ->showHeader(true)
                    ->render() ?>
            </div>

            <!-- 하단: 선택 삭제 + 페이지네이션 -->
            <div class="row gx-2 justify-content-between align-items-center my-2">
                <div class="col-auto">
                    <button type="button" class="btn btn-sm btn-default mublo-submit"
                            data-target="/admin/shop/coupons/list-delete"
                            data-callback="afterBulkDelete"
                            data-confirm="선택한 쿠폰을 삭제하시겠습니까?">
                        <i class="d-inline d-md-none bi bi-trash"></i>
                        <span class="d-none d-md-inline">선택 삭제</span>
                    </button>
                </div>
                <div class="col-auto">
                    <?php if (!empty($pagination) && ($pagination['totalPages'] ?? 1) > 1): ?>
                        <?= $this->pagination($pagination) ?>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    // 전체 선택 (발급된 정책은 체크박스 자체가 없음)
    document.querySelector('input[name="chk_all"]')?.addEventListener('change', function () {
        var checked = this.checked;
        document.querySelectorAll('input[name="chk[]"]').forEach(function (cb) { cb.checked = checked; });
    });

    // 단건 삭제 (이벤트 위임)
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.btn-delete');
        if (!btn) return;
        var name = btn.dataset.name || '';
        if (!confirm('[' + name + '] 쿠폰을 삭제하시겠습니까?')) return;
        MubloRequest.requestJson('/admin/shop/coupons/' + btn.dataset.id + '/delete', {})
            .then(function () { location.reload(); });
    });
})();

// 일괄 삭제 후 콜백
function afterBulkDelete(data) {
    if (data.result === 'success') {
        location.reload();
    } else {
        alert(data.message || '삭제에 실패했습니다.');
    }
}
</script>
