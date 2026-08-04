<?php
/**
 * 배송 템플릿 목록
 * @var string $pageTitle
 * @var array $templates 배송 템플릿 목록
 * @var array $shippingMethodOptions 배송 방법 옵션
 * @var array $pagination
 * @var array $filters
 */

$templates             = $templates ?? [];
$shippingMethodOptions = $shippingMethodOptions ?? [];
$pagination            = $pagination ?? [];
$filters               = $filters ?? [];
$defaultTemplateId     = (int) ($defaultTemplateId ?? 0);

// 배송 수단 라벨 (Form.php의 select 옵션과 동일)
$deliveryMethodLabels = [
    'COURIER' => '택배',
    'POSTAL'  => '우편',
    'PICKUP'  => '직접수령',
    'OWN'     => '자체배송',
    'ETC'     => '기타',
];

// 삭제 불가(기본·사용 중) 행에는 체크박스를 렌더하지 않도록 플래그를 붙인다.
foreach ($templates as &$tpl) {
    $isDefault  = (int) $tpl['shipping_id'] === $defaultTemplateId;
    $usageCount = (int) ($tpl['usage_count'] ?? 0);
    $tpl['_no_delete'] = ($isDefault || $usageCount > 0) ? 1 : 0;
}
unset($tpl);

$columns = $this->columns()
    ->checkbox('chk', '', ['id_key' => 'shipping_id', 'skip_key' => '_no_delete', '_th_attr' => ['style' => 'width:40px', 'class' => 'text-center'], '_cell_attr' => ['class' => 'text-center']])
    ->add('shipping_id', '번호', ['_th_attr' => ['class' => 'text-nowrap', 'style' => 'width:60px'], '_td_attr' => ['class' => 'text-nowrap']])
    ->callback('name', '템플릿명', function ($row) use ($defaultTemplateId) {
        $id = (int) $row['shipping_id'];
        $name = htmlspecialchars($row['name'] ?? '');
        $isDefault = $id === $defaultTemplateId;
        $usageCount = (int) ($row['usage_count'] ?? 0);
        $html = '<a href="/admin/shop/shipping/' . $id . '/edit">' . $name . '</a>';
        if ($isDefault) {
            $html .= ' <span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle ms-1" title="쇼핑몰 기본 배송 템플릿">기본</span>';
        }
        if ($usageCount > 0) {
            $html .= ' <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle ms-1" title="이 배송 템플릿을 개별 상품에 적용한 수">개별 적용 ' . $usageCount . '</span>';
        }
        return $html;
    })
    ->callback('shipping_method', '배송 방법', function ($row) use ($shippingMethodOptions) {
        $m = $row['shipping_method'] ?? '';
        return htmlspecialchars($shippingMethodOptions[$m] ?? $m ?: '-');
    }, ['_th_attr' => ['style' => 'width:100px']])
    ->callback('delivery_method', '배송 수단', function ($row) use ($deliveryMethodLabels) {
        $dm = $row['delivery_method'] ?: 'COURIER'; // 폼과 동일하게 미지정은 택배 기본
        return htmlspecialchars($deliveryMethodLabels[$dm] ?? $dm);
    }, ['_th_attr' => ['style' => 'width:90px']])
    ->callback('basic_cost', '기본 배송비', function ($row) {
        return number_format($row['basic_cost'] ?? 0) . '원';
    }, ['_th_attr' => ['class' => 'text-end', 'style' => 'width:100px'], '_td_attr' => ['class' => 'text-end']])
    ->callback('free_threshold', '조건', function ($row) {
        $ft = (int) ($row['free_threshold'] ?? 0);
        return $ft > 0 ? number_format($ft) . '원 이상' : '-';
    }, ['_th_attr' => ['class' => 'text-end text-nowrap', 'style' => 'width:140px'], '_td_attr' => ['class' => 'text-end text-nowrap']])
    ->callback('return_cost', '반품비', function ($row) {
        return number_format($row['return_cost'] ?? 0) . '원';
    }, ['_th_attr' => ['class' => 'text-end', 'style' => 'width:90px'], '_td_attr' => ['class' => 'text-end']])
    ->callback('exchange_cost', '교환비', function ($row) {
        return number_format($row['exchange_cost'] ?? 0) . '원';
    }, ['_th_attr' => ['class' => 'text-end', 'style' => 'width:90px'], '_td_attr' => ['class' => 'text-end']])
    ->callback('extra_cost_enabled', '추가배송비', function ($row) {
        return ($row['extra_cost_enabled'] ?? false) ? '사용' : '-';
    }, ['_th_attr' => ['class' => 'text-center', 'style' => 'width:90px'], '_td_attr' => ['class' => 'text-center']])
    ->callback('is_active', '상태', function ($row) {
        if ($row['is_active'] ?? false) {
            return '<span class="badge bg-success-subtle text-success-emphasis border border-success-subtle">활성</span>';
        }
        return '<span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle">비활성</span>';
    }, ['_th_attr' => ['class' => 'text-center', 'style' => 'width:70px'], '_td_attr' => ['class' => 'text-center']])
    ->actions('actions', '관리', function ($row) {
        $id = (int) $row['shipping_id'];
        $name = htmlspecialchars($row['name'] ?? '');
        $html = '<a href="/admin/shop/shipping/' . $id . '/edit" class="btn btn-sm btn-default">수정</a>';
        if (empty($row['_no_delete'])) {
            $html .= ' <button type="button" class="btn btn-sm btn-default btn-delete" data-id="' . $id . '" data-name="' . $name . '">삭제</button>';
        }
        return $html;
    }, ['_th_attr' => ['style' => 'width:110px'], '_td_attr' => ['class' => 'text-nowrap']])
    ->build();
?>
<div class="page-container">
    <div class="page-title">
        <div class="page-title-text">
            <h3><?= htmlspecialchars($pageTitle ?? '배송 템플릿') ?></h3>
            <p>상품에 적용할 배송 정책을 관리합니다.</p>
        </div>
        <div class="page-title-actions">
            <a href="/admin/shop/shipping/create" class="btn btn-sm btn-primary">
                <i class="bi bi-plus-lg"></i> 템플릿 등록
            </a>
        </div>
    </div>

    <div class="page-block">
        <!-- 검색 영역 -->
        <form method="get" name="fsearch" id="fsearch" class="mb-2">
            <div class="row align-items-center gy-2 gy-xl-0">
                <div class="col-auto">
                    <span class="ov">
                        <span class="ov-txt"><a href="/admin/shop/shipping">전체</a></span>
                        <span class="ov-num"><b><?= number_format(count($templates)) ?></b> 건</span>
                    </span>
                </div>
                <div class="col col-xl-auto ms-xl-auto">
                    <div class="row gx-2">
                        <div class="col col-xl-auto">
                            <div class="search-wrapper">
                                <label for="search_keyword" class="visually-hidden">검색</label>
                                <input type="text" name="keyword" id="search_keyword" class="form-control form-control-sm"
                                       placeholder="템플릿명 검색"
                                       value="<?= htmlspecialchars($filters['keyword'] ?? '') ?>">
                                <i class="bi bi-search search-icon"></i>
                                <?php if (!empty($filters['keyword'])): ?>
                                <i class="bi bi-x-lg search-reset-icon" onclick="location.href='/admin/shop/shipping'"></i>
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
                    ->setRows($templates)
                    ->setSkin('table/basic')
                    ->setWrapAttr(['class' => 'table table-hover align-middle mb-0'])
                    ->showHeader(true)
                    ->render() ?>
            </div>

            <!-- 하단: 선택 삭제 + 페이지네이션 -->
            <div class="row gx-2 justify-content-between align-items-center my-2">
                <div class="col-auto">
                    <button type="button" class="btn btn-sm btn-default mublo-submit"
                            data-target="/admin/shop/shipping/list-delete"
                            data-callback="afterBulkDelete"
                            data-confirm="선택한 배송 템플릿을 삭제하시겠습니까?">
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
    // 전체 선택 (삭제 불가 행은 체크박스 자체가 없음)
    document.querySelector('input[name="chk_all"]')?.addEventListener('change', function () {
        var checked = this.checked;
        document.querySelectorAll('input[name="chk[]"]').forEach(function (cb) { cb.checked = checked; });
    });

    // 단건 삭제 (이벤트 위임)
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.btn-delete');
        if (!btn) return;
        var name = btn.dataset.name || '';
        if (!confirm('[' + name + '] 배송 템플릿을 삭제하시겠습니까?')) return;
        MubloRequest.requestJson('/admin/shop/shipping/' + btn.dataset.id + '/delete', {})
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
