<?php
/**
 * 구매후기 관리 목록
 *
 * @var string $pageTitle
 * @var array $items
 * @var array $pagination
 * @var array $filters
 */
$currentVisible = $filters['is_visible'] ?? '';
$currentKeyword = $filters['keyword'] ?? '';

$totalItems = $pagination['totalItems'] ?? 0;
$currentPage = $pagination['currentPage'] ?? 1;
$totalPages = $pagination['totalPages'] ?? 1;
$perPage = $pagination['perPage'] ?? 20;

$columns = $this->columns()
    ->checkbox('chk', '', ['id_key' => 'review_id', '_th_attr' => ['style' => 'width:40px', 'class' => 'text-center'], '_cell_attr' => ['class' => 'text-center']])
    ->add('review_id', '번호', ['_th_attr' => ['class' => 'text-nowrap', 'style' => 'width:60px'], '_td_attr' => ['class' => 'text-nowrap']])
    ->callback('goods_name', '상품명', function ($row) {
        $rid = (int) $row['review_id'];
        $gid = (int) ($row['goods_id'] ?? 0);
        $gslug = $row['goods_slug'] ?? '';
        $frontUrl = $gid > 0 ? ('/shop/products/' . $gid . ($gslug !== '' ? '/' . rawurlencode($gslug) : '')) : '';
        $name = htmlspecialchars($row['goods_name'] ?? '상품 삭제됨');
        $excerpt = htmlspecialchars(mb_substr(strip_tags($row['content'] ?? ''), 0, 50));

        $icons = '';
        if ($gid > 0) {
            $icons = '<a href="' . htmlspecialchars($frontUrl) . '" target="_blank" class="small text-muted text-decoration-none goods-icon-link" title="상품 보기"><i class="bi bi-box-arrow-up-right"></i></a>'
                . '<a href="/admin/shop/products/' . $gid . '/edit" target="_blank" data-no-active-code class="small text-muted text-decoration-none goods-icon-link" title="상품 관리"><i class="bi bi-gear"></i></a>';
        }
        $html = '<div class="goods-cell">'
            . '<div class="goods-cell__line">'
            . '<a href="/admin/shop/reviews/' . $rid . '/edit" class="goods-cell__name">' . $name . '</a>'
            . ($icons !== '' ? '<span class="goods-cell__icons">' . $icons . '</span>' : '')
            . '</div>'
            . '<div class="goods-cell__sub text-muted small">' . $excerpt . '</div>'
            . '</div>';
        return $html;
    }, ['_td_attr' => ['class' => 'goods-td']])
    ->callback('rating', '평점', function ($row) {
        $rating = (int) $row['rating'];
        $nick = $row['nickname'] ?? '';
        $uid = $row['user_id'] ?? '';
        // 회원=닉네임>아이디, 비회원(member 조인 비어있음)=비회원
        $author = $nick !== '' ? $nick : ($uid !== '' ? $uid : '비회원');
        return '<div><span class="star-display" data-rating="' . $rating . '"></span></div>'
            . '<div class="text-muted small">' . htmlspecialchars($author) . '</div>';
    }, ['_th_attr' => ['class' => 'text-center', 'style' => 'width:90px'], '_td_attr' => ['class' => 'text-center']])
    ->callback('review_type', '유형', function ($row) {
        $isPhoto = ($row['review_type'] ?? '') === 'PHOTO';
        $cls = $isPhoto ? 'bg-info-subtle text-info-emphasis border border-info-subtle' : 'bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle';
        $label = $isPhoto ? '포토' : '텍스트';
        return '<span class="badge ' . $cls . '">' . $label . '</span>';
    }, ['_th_attr' => ['class' => 'text-center', 'style' => 'width:70px'], '_td_attr' => ['class' => 'text-center']])
    ->callback('is_visible', '공개', function ($row) {
        $rid = (int) $row['review_id'];
        $v = $row['is_visible'];
        return '<select name="is_visible[' . $rid . ']" class="form-select form-select-sm">'
            . '<option value="1"' . ($v ? ' selected' : '') . '>공개</option>'
            . '<option value="0"' . (!$v ? ' selected' : '') . '>비공개</option>'
            . '</select>';
    }, ['_th_attr' => ['class' => 'text-center', 'style' => 'width:90px'], '_td_attr' => ['class' => 'text-center']])
    ->callback('is_best', '베스트', function ($row) {
        $rid = (int) $row['review_id'];
        $v = $row['is_best'];
        return '<select name="is_best[' . $rid . ']" class="form-select form-select-sm">'
            . '<option value="1"' . ($v ? ' selected' : '') . '>베스트</option>'
            . '<option value="0"' . (!$v ? ' selected' : '') . '>일반</option>'
            . '</select>';
    }, ['_th_attr' => ['class' => 'text-center', 'style' => 'width:90px'], '_td_attr' => ['class' => 'text-center']])
    ->callback('admin_reply', '답변', function ($row) {
        if (!empty($row['admin_reply'])) {
            return '<span class="badge bg-success-subtle text-success-emphasis border border-success-subtle">완료</span>';
        }
        return '<span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle">미답변</span>';
    }, ['_th_attr' => ['class' => 'text-center', 'style' => 'width:70px'], '_td_attr' => ['class' => 'text-center']])
    ->callback('created_at', '등록일', function ($row) {
        $at = (string) ($row['created_at'] ?? '');
        return '<div><small>' . htmlspecialchars(substr($at, 0, 10)) . '</small></div>'
            . '<div class="text-muted" style="font-size:0.75rem">' . htmlspecialchars(substr($at, 11, 5)) . '</div>';
    }, ['_th_attr' => ['class' => 'text-center', 'style' => 'width:100px'], '_td_attr' => ['class' => 'text-center']])
    ->actions('actions', '관리', function ($row) {
        $rid = (int) $row['review_id'];
        return '
            <a href="/admin/shop/reviews/' . $rid . '/edit" class="btn btn-sm btn-default" title="수정"><i class="bi bi-pencil"></i></a>
            <button type="button" class="btn btn-sm btn-default btn-delete-review" data-review-id="' . $rid . '" title="삭제"><i class="bi bi-trash"></i></button>
        ';
    }, ['_th_attr' => ['style' => 'width:90px']])
    ->build();
?>
<div class="page-container">
    <div class="page-title">
        <div class="page-title-text">
            <h3><?= htmlspecialchars($pageTitle ?? '구매후기 관리') ?></h3>
            <p>구매후기를 관리합니다.</p>
        </div>
        <div class="page-title-actions">
            <a href="/admin/shop/reviews/create" class="btn btn-primary btn-sm">
                <i class="bi bi-plus-lg"></i> 후기 등록
            </a>
        </div>
    </div>

    <form method="get" name="fsearch" id="fsearch" class="mb-2">
        <div class="row align-items-center gy-2 gy-xl-0">
            <div class="col-auto">
                <span class="ov">
                    <span class="ov-txt"><a href="/admin/shop/reviews">전체</a></span>
                    <span class="ov-num"><b><?= number_format($totalItems) ?></b> 건</span>
                </span>
            </div>
            <div class="col col-xl-auto ms-xl-auto">
                <div class="row gx-2">
                    <div class="col-auto">
                        <select name="is_visible" class="form-select form-select-sm">
                            <option value="">전체 상태</option>
                            <option value="1" <?= $currentVisible === '1' ? 'selected' : '' ?>>공개</option>
                            <option value="0" <?= $currentVisible === '0' ? 'selected' : '' ?>>비공개</option>
                        </select>
                    </div>
                    <div class="col col-xl-auto">
                        <div class="search-wrapper">
                            <label for="search_keyword" class="visually-hidden">검색</label>
                            <input type="text" name="keyword" id="search_keyword" class="form-control form-control-sm"
                                   placeholder="내용/상품명"
                                   value="<?= htmlspecialchars($currentKeyword) ?>">
                            <i class="bi bi-search search-icon"></i>
                            <?php if ($currentKeyword): ?>
                            <i class="bi bi-x-lg search-reset-icon" onclick="location.href='/admin/shop/reviews'"></i>
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
                ->setWrapAttr(['class' => 'table table-hover align-middle mb-0', 'id' => 'reviewTable'])
                ->showHeader(true)
                ->render() ?>
        </div>

        <div class="row gx-2 justify-content-between align-items-center my-2">
            <div class="col-auto">
                <div class="d-flex gap-1">
                    <button type="button" class="btn btn-sm btn-default" onclick="batchModify()">
                        <i class="d-inline d-md-none bi bi-pencil-square"></i>
                        <span class="d-none d-md-inline">선택 수정</span>
                    </button>
                    <button type="button" class="btn btn-sm btn-default mublo-submit"
                            data-target="/admin/shop/reviews/list-delete"
                            data-callback="afterBulkDelete"
                            data-confirm="선택한 후기를 삭제하시겠습니까?">
                        <i class="d-inline d-md-none bi bi-trash"></i>
                        <span class="d-none d-md-inline">선택 삭제</span>
                    </button>
                </div>
            </div>
            <div class="col-auto">
                <?= $this->pagination($pagination) ?>
            </div>
        </div>
    </form>
</div>

<style>
/* 인라인 셀렉트 변경됨 표시 (회원 관리 패턴) */
#flist select.list-changed {
    border-color: var(--bs-warning, #ffc107);
    background-color: var(--bs-warning-bg-subtle, #fff3cd);
    font-weight: 600;
}
.goods-icon-link:hover { color: var(--bs-emphasis-color, #000) !important; }
/* 상품명 셀: 2행 고정 (이름행 + 부가행), 각 행 말줄임 */
.goods-td { max-width: 0; }
.goods-cell { max-width: 100%; }
.goods-cell__line { display: flex; align-items: center; gap: .375rem; min-width: 0; }
.goods-cell__name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; min-width: 0; }
.goods-cell__icons { flex-shrink: 0; display: inline-flex; gap: .375rem; }
.goods-cell__sub { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
</style>
<script src="/assets/lib/star/star.js"></script>
<script>
(function () {
    document.querySelectorAll('.star-display').forEach(function (el) {
        new StarRating(el, { mode: 'display', maxScore: 5, maxStars: 5, rating: parseInt(el.dataset.rating) || 0, starSize: '0.9rem' });
    });
    // 전체 선택
    document.querySelector('input[name="chk_all"]')?.addEventListener('change', function () {
        var checked = this.checked;
        document.querySelectorAll('input[name="chk[]"]').forEach(function (cb) { cb.checked = checked; });
    });
    // 행 셀렉트(공개/베스트) 변경 시 변경 표시 + 해당 행 체크 (회원 관리 패턴)
    var flist = document.getElementById('flist');
    if (flist) {
        flist.querySelectorAll('select').forEach(function (sel) { sel.dataset.original = sel.value; });
        flist.addEventListener('change', function (e) {
            var el = e.target;
            if (!el || el.tagName !== 'SELECT') return;
            var row = el.closest('tr');
            if (!row) return;
            el.classList.toggle('list-changed', el.value !== el.dataset.original);
            var cb = row.querySelector('input[name="chk[]"]');
            if (cb) cb.checked = row.querySelector('select.list-changed') !== null;
        });
    }
    // 단건 삭제 (이벤트 위임)
    document.getElementById('reviewTable')?.addEventListener('click', function (e) {
        var btn = e.target.closest('.btn-delete-review');
        if (!btn) return;
        if (!confirm('이 후기를 삭제하시겠습니까?')) return;
        MubloRequest.requestJson('/admin/shop/reviews/delete', { review_id: parseInt(btn.dataset.reviewId) })
            .then(function () { location.reload(); });
    });
})();

// 선택 상태 일괄 수정
function batchModify() {
    var checked = document.querySelectorAll('input[name="chk[]"]:checked');
    if (checked.length === 0) { alert('수정할 항목을 선택하세요.'); return; }
    var items = {};
    checked.forEach(function (cb) {
        var row = cb.closest('tr');
        items[cb.value] = {
            is_visible: parseInt(row.querySelector('select[name^="is_visible["]').value),
            is_best: parseInt(row.querySelector('select[name^="is_best["]').value)
        };
    });
    if (!confirm(checked.length + '건의 상태를 수정하시겠습니까?')) return;
    MubloRequest.requestJson('/admin/shop/reviews/list-modify', { items: items })
        .then(function () { location.reload(); });
}

// 일괄 삭제 후 콜백
function afterBulkDelete(data) {
    if (data.result === 'success') {
        location.reload();
    } else {
        alert(data.message || '삭제에 실패했습니다.');
    }
}
</script>
