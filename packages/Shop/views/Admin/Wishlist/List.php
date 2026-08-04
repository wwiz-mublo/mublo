<?php
/**
 * 관리자 찜한상품 목록
 *
 * @var string $pageTitle
 * @var array $items 찜 항목 (회원·상품 join)
 * @var array $pagination
 * @var array $filters
 */

$items      = $items ?? [];
$pagination = $pagination ?? [];
$filters    = $filters ?? [];

// 찜은 복합키(member_id + goods_id)로 삭제 → 체크박스 값에 "member:goods" 인코딩
foreach ($items as &$row) {
    $row['wl_key'] = (int) ($row['member_id'] ?? 0) . ':' . (int) ($row['goods_id'] ?? 0);
}
unset($row);

$columns = $this->columns()
    ->checkbox('chk', '', ['id_key' => 'wl_key', '_th_attr' => ['style' => 'width:40px', 'class' => 'text-center'], '_cell_attr' => ['class' => 'text-center']])
    ->add('wishlist_id', '번호', ['_th_attr' => ['class' => 'text-nowrap', 'style' => 'width:60px'], '_td_attr' => ['class' => 'text-nowrap']])
    ->callback('member', '회원', function ($row) {
        $uid = htmlspecialchars($row['user_id'] ?? '-');
        $html = "<strong>{$uid}</strong>";
        if (!empty($row['nickname'])) {
            $html .= ' <span class="text-muted small">(' . htmlspecialchars($row['nickname']) . ')</span>';
        }
        return $html;
    }, ['_th_attr' => ['style' => 'width:160px']])
    ->callback('goods_name', '상품명', function ($row) {
        $gid = (int) ($row['goods_id'] ?? 0);
        if ($gid <= 0) {
            return '<span class="text-muted">삭제된 상품</span>';
        }
        $gslug = $row['goods_slug'] ?? '';
        $frontUrl = '/shop/products/' . $gid . ($gslug !== '' ? '/' . rawurlencode($gslug) : '');
        $name = htmlspecialchars($row['goods_name'] ?? '-');
        $icons = '<a href="' . htmlspecialchars($frontUrl) . '" target="_blank" class="small text-muted text-decoration-none goods-icon-link" title="상품 보기"><i class="bi bi-box-arrow-up-right"></i></a>'
            . '<a href="/admin/shop/products/' . $gid . '/edit" target="_blank" data-no-active-code class="small text-muted text-decoration-none goods-icon-link" title="상품 관리"><i class="bi bi-gear"></i></a>';
        return '<div class="goods-cell">'
            . '<div class="goods-cell__line">'
            . '<span class="goods-cell__name">' . $name . '</span>'
            . '<span class="goods-cell__icons">' . $icons . '</span>'
            . '</div>'
            . '</div>';
    }, ['_td_attr' => ['class' => 'goods-td']])
    ->callback('display_price', '판매가', function ($row) {
        return number_format((int) ($row['display_price'] ?? 0)) . '원';
    }, ['_th_attr' => ['class' => 'text-end', 'style' => 'width:120px'], '_td_attr' => ['class' => 'text-end']])
    ->callback('is_active', '상태', function ($row) {
        $active = $row['is_active'] ?? null;
        if ($active === null) {
            return '<span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle">-</span>';
        }
        if ((int) $active === 1) {
            return '<span class="badge bg-success-subtle text-success-emphasis border border-success-subtle">판매중</span>';
        }
        return '<span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle">중지</span>';
    }, ['_th_attr' => ['class' => 'text-center', 'style' => 'width:80px'], '_td_attr' => ['class' => 'text-center']])
    ->callback('created_at', '찜한 일시', function ($row) {
        return htmlspecialchars($row['created_at'] ?? '');
    }, ['_th_attr' => ['class' => 'text-center', 'style' => 'width:180px'], '_td_attr' => ['class' => 'text-center']])
    ->actions('actions', '관리', function ($row) {
        $memberId = (int) ($row['member_id'] ?? 0);
        $goodsId = (int) ($row['goods_id'] ?? 0);
        return '<button type="button" class="btn btn-sm btn-default btn-delete-wishlist" data-member-id="' . $memberId . '" data-goods-id="' . $goodsId . '">삭제</button>';
    }, ['_th_attr' => ['style' => 'width:80px']])
    ->build();
?>
<div class="page-container">
    <div class="page-title">
        <div class="page-title-text">
            <h3><?= htmlspecialchars($pageTitle ?? '찜한상품') ?></h3>
            <p>회원이 찜한 상품 목록을 조회합니다.</p>
        </div>
    </div>

    <div class="page-block">
        <form method="get" name="fsearch" id="fsearch" class="mb-2">
            <div class="row align-items-center gy-2 gy-xl-0">
                <div class="col-auto">
                    <span class="ov">
                        <span class="ov-txt"><a href="/admin/shop/wishlists">전체</a></span>
                        <span class="ov-num"><b><?= number_format($pagination['totalItems'] ?? 0) ?></b> 건</span>
                    </span>
                </div>
                <div class="col col-xl-auto ms-xl-auto">
                    <div class="row gx-2">
                        <div class="col col-xl-auto">
                            <div class="search-wrapper">
                                <label for="search_keyword" class="visually-hidden">검색</label>
                                <input type="text" name="keyword" id="search_keyword" class="form-control form-control-sm"
                                       placeholder="회원 아이디 / 상품명"
                                       value="<?= htmlspecialchars($filters['keyword'] ?? '') ?>">
                                <i class="bi bi-search search-icon"></i>
                                <?php if (!empty($filters['keyword'])): ?>
                                <i class="bi bi-x-lg search-reset-icon" onclick="location.href='/admin/shop/wishlists'"></i>
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

        <form name="flist" id="flist">
            <div class="table-responsive">
                <?= $this->listRenderHelper
                    ->setColumns($columns)
                    ->setRows($items)
                    ->setSkin('table/basic')
                    ->setWrapAttr(['class' => 'table table-hover align-middle mb-0'])
                    ->showHeader(true)
                    ->render() ?>
            </div>

            <div class="row gx-2 justify-content-between align-items-center my-2">
                <div class="col-auto">
                    <button type="button" class="btn btn-sm btn-default mublo-submit"
                            data-target="/admin/shop/wishlists/list-delete"
                            data-callback="afterBulkDelete"
                            data-confirm="선택한 찜 항목을 삭제하시겠습니까?">
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

<style>
.goods-icon-link:hover { color: var(--bs-emphasis-color, #000) !important; }
/* 상품명 셀: 이름행 말줄임, 아이콘 고정폭 (td 폭 따라감) */
.goods-td { max-width: 0; }
.goods-cell { max-width: 100%; }
.goods-cell__line { display: flex; align-items: center; gap: .375rem; min-width: 0; }
.goods-cell__name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; min-width: 0; }
.goods-cell__icons { flex-shrink: 0; display: inline-flex; gap: .375rem; }
</style>
<script>
(function () {
    // 전체 선택
    document.querySelector('input[name="chk_all"]')?.addEventListener('change', function () {
        var checked = this.checked;
        document.querySelectorAll('input[name="chk[]"]').forEach(function (cb) { cb.checked = checked; });
    });

    // 단건 삭제 (복합키: member_id + goods_id)
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.btn-delete-wishlist');
        if (!btn) return;
        if (!confirm('이 찜 항목을 삭제하시겠습니까?')) return;
        MubloRequest.requestJson('/admin/shop/wishlists/delete', {
            member_id: parseInt(btn.dataset.memberId, 10),
            goods_id: parseInt(btn.dataset.goodsId, 10)
        }).then(function () { location.reload(); });
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
