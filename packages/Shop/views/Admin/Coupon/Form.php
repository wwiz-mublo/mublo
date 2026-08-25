<?php
/**
 * 쿠폰 등록/수정 폼
 * @var array|null $coupon 쿠폰 정책 데이터
 * @var bool $isEdit 컨트롤러가 전달 (true=수정, false=생성)
 */

use Mublo\Packages\Shop\Enum\CouponType;

$isEdit = !empty($isEdit);
?>

<style>
/* 대상 상품 input-group: 해제 버튼이 숨겨지면(has-clear 미적용) 마지막 보이는 요소인
   검색 버튼의 우측 라운딩이 죽으므로 복구한다. (display:none 자식은 여전히 :last-child라 발생) */
#goodsInputGroup:not(.has-clear) #btnClearGoods { display: none; }
#goodsInputGroup:not(.has-clear) #btnSearchGoods {
    border-top-right-radius: var(--bs-border-radius);
    border-bottom-right-radius: var(--bs-border-radius);
}
</style>

<form id="couponForm">
    <?php if ($isEdit): ?>
    <input type="hidden" name="formData[coupon_group_id]" value="<?= $coupon['coupon_group_id'] ?>">
    <?php endif; ?>
    <div class="page-container form-container">
        <div class="page-title">
            <div class="page-title-text">
                <h3><?= $isEdit ? '할인쿠폰 수정' : '할인쿠폰 등록' ?></h3>
                <p>할인쿠폰의 발행 유형과 할인·사용 조건을 설정합니다.</p>
            </div>
            <div class="page-title-actions">
                <a href="/admin/shop/coupons" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-list"></i> 목록
                </a>
                <button type="button" class="btn btn-sm btn-primary mublo-submit"
                        data-target="/admin/shop/coupons/store"
                        data-callback="couponSaved">
                    <i class="bi bi-check-lg"></i> <?= $isEdit ? '수정' : '등록' ?>
                </button>
            </div>
        </div>

    <div class="card mb-4">
        <div class="card-hero">
            <i class="bi bi-info-circle text-pastel-blue"></i>
            <span>기본 정보</span>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-lg-7">
                    <div class="row mb-3">
                        <label class="col-sm-3 col-form-label">쿠폰명 <span class="text-danger">*</span></label>
                        <div class="col-sm-9"><input type="text" name="formData[name]" class="form-control" value="<?= htmlspecialchars($coupon['name'] ?? '') ?>" required></div>
                    </div>
                    <div class="row mb-3">
                        <label class="col-sm-3 col-form-label">발행 유형</label>
                        <div class="col-sm-9">
                            <select name="formData[coupon_type]" class="form-select">
                                <?php foreach (CouponType::options() as $val => $lbl): ?>
                                    <option value="<?= $val ?>" <?= ($coupon['coupon_type'] ?? '') === $val ? 'selected' : '' ?>><?= $lbl ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row mb-3" id="autoTriggerRow" style="<?= ($coupon['coupon_type'] ?? '') === 'AUTO' ? '' : 'display:none' ?>">
                        <label class="col-sm-3 col-form-label">자동 발행 조건</label>
                        <div class="col-sm-9">
                            <select name="formData[auto_issue_trigger]" class="form-select" id="autoIssueTrigger">
                                <option value="">선택</option>
                                <option value="JOIN" <?= ($coupon['auto_issue_trigger'] ?? '') === 'JOIN' ? 'selected' : '' ?>>회원가입</option>
                                <option value="LEVEL" <?= ($coupon['auto_issue_trigger'] ?? '') === 'LEVEL' ? 'selected' : '' ?>>등급변경</option>
                                <option value="LOGIN" <?= ($coupon['auto_issue_trigger'] ?? '') === 'LOGIN' ? 'selected' : '' ?>>로그인</option>
                            </select>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <label class="col-sm-3 col-form-label">적용 대상</label>
                        <div class="col-sm-9">
                            <select name="formData[coupon_method]" class="form-select" id="couponMethod">
                                <option value="ORDER" <?= ($coupon['coupon_method'] ?? 'ORDER') === 'ORDER' ? 'selected' : '' ?>>전체 (주문금액)</option>
                                <option value="GOODS" <?= ($coupon['coupon_method'] ?? '') === 'GOODS' ? 'selected' : '' ?>>특정 상품</option>
                                <option value="CATEGORY" <?= ($coupon['coupon_method'] ?? '') === 'CATEGORY' ? 'selected' : '' ?>>특정 카테고리</option>
                                <option value="SHIPPING" <?= ($coupon['coupon_method'] ?? '') === 'SHIPPING' ? 'selected' : '' ?>>배송비</option>
                            </select>
                        </div>
                    </div>
                    <div class="row mb-3" id="targetGoodsRow" style="<?= ($coupon['coupon_method'] ?? '') === 'GOODS' ? '' : 'display:none' ?>">
                        <label class="col-sm-3 col-form-label">대상 상품</label>
                        <div class="col-sm-9">
                            <input type="hidden" name="formData[target_goods_id]" id="targetGoodsId" value="<?= $coupon['target_goods_id'] ?? '' ?>">
                            <div class="input-group<?= empty($coupon['target_goods_id']) ? '' : ' has-clear' ?>" id="goodsInputGroup">
                                <input type="text" class="form-control" id="targetGoodsName" value="<?= htmlspecialchars($coupon['target_goods_name'] ?? '') ?>" placeholder="상품을 검색하세요" readonly>
                                <button type="button" class="btn btn-outline-secondary" id="btnSearchGoods">검색</button>
                                <button type="button" class="btn btn-outline-danger" id="btnClearGoods">해제</button>
                            </div>
                            <div class="form-text">비워두면 모든 상품에 적용</div>
                        </div>
                    </div>
                    <div class="row mb-3" id="targetCategoryRow" style="<?= ($coupon['coupon_method'] ?? '') === 'CATEGORY' ? '' : 'display:none' ?>">
                        <label class="col-sm-3 col-form-label">대상 카테고리</label>
                        <div class="col-sm-9">
                            <select name="formData[target_category]" class="form-select" id="targetCategorySelect">
                                <option value="">전체 (모든 카테고리)</option>
                            </select>
                            <div class="form-text">비워두면 모든 카테고리에 적용</div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="border rounded p-3 h-100">
                        <div id="couponTypeDesc">
                            <h6 class="text-primary mb-2" id="typeDescTitle">관리자 발행</h6>
                            <p class="text-muted small mb-0" id="typeDescBody">관리자가 직접 특정 회원에게 쿠폰을 발행합니다. 수동으로 대상을 지정하여 개별 발행할 수 있습니다.</p>
                        </div>
                        <hr class="my-2">
                        <div id="couponMethodDesc">
                            <h6 class="text-success mb-2" id="methodDescTitle">주문 할인</h6>
                            <p class="text-muted small mb-0" id="methodDescBody">주문 전체 금액에서 할인을 적용합니다. 특정 상품이나 카테고리에 관계없이 주문 금액 기준으로 할인됩니다.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-hero">
            <i class="bi bi-currency-dollar text-pastel-green"></i>
            <span>할인 설정</span>
        </div>
        <div class="card-body">
            <div class="row mb-3">
                <label class="col-sm-2 col-form-label">할인 유형</label>
                <div class="col-sm-3">
                    <select name="formData[discount_type]" class="form-select">
                        <option value="FIXED" <?= ($coupon['discount_type'] ?? '') === 'FIXED' ? 'selected' : '' ?>>정액</option>
                        <option value="PERCENTAGE" <?= ($coupon['discount_type'] ?? '') === 'PERCENTAGE' ? 'selected' : '' ?>>정률(%)</option>
                    </select>
                </div>
                <label class="col-sm-2 col-form-label">할인 값</label>
                <div class="col-sm-3"><input type="number" name="formData[discount_value]" class="form-control" value="<?= $coupon['discount_value'] ?? 0 ?>"></div>
            </div>
            <div class="row mb-3">
                <label class="col-sm-2 col-form-label">최대 할인</label>
                <div class="col-sm-3"><input type="number" name="formData[max_discount]" class="form-control" value="<?= $coupon['max_discount'] ?? '' ?>" placeholder="정률 시"></div>
                <label class="col-sm-2 col-form-label">최소 주문금액</label>
                <div class="col-sm-3"><input type="number" name="formData[min_order_amount]" class="form-control" value="<?= $coupon['min_order_amount'] ?? 0 ?>"></div>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-hero">
            <i class="bi bi-sliders text-pastel-purple"></i>
            <span>사용 제한</span>
        </div>
        <div class="card-body">
            <div class="row mb-3">
                <label class="col-sm-2 col-form-label">중복 사용</label>
                <div class="col-sm-4">
                    <select name="formData[duplicate_policy]" class="form-select">
                        <option value="DENY_SAME_METHOD" <?= ($coupon['duplicate_policy'] ?? 'DENY_SAME_METHOD') === 'DENY_SAME_METHOD' ? 'selected' : '' ?>>같은 유형 중복 불가</option>
                        <option value="ALLOW" <?= ($coupon['duplicate_policy'] ?? '') === 'ALLOW' ? 'selected' : '' ?>>중복 사용 가능</option>
                        <option value="DENY_ALL" <?= ($coupon['duplicate_policy'] ?? '') === 'DENY_ALL' ? 'selected' : '' ?>>단독 사용만 가능</option>
                    </select>
                </div>
                <div class="col-sm-4 pt-2 text-muted">다른 쿠폰과 함께 사용 가능 여부</div>
            </div>
            <div class="row mb-3">
                <label class="col-sm-2 col-form-label">1인당 사용 횟수</label>
                <div class="col-sm-3"><input type="number" name="formData[use_limit_per_member]" class="form-control" value="<?= $coupon['use_limit_per_member'] ?? 1 ?>" min="1"></div>
                <div class="col-sm-4 pt-2 text-muted">회원당 사용 가능 횟수</div>
            </div>
            <div class="row mb-3">
                <label class="col-sm-2 col-form-label">1인당 발급 횟수</label>
                <div class="col-sm-3"><input type="number" name="formData[download_limit_per_member]" class="form-control" value="<?= $coupon['download_limit_per_member'] ?? 1 ?>" min="1"></div>
                <div class="col-sm-4 pt-2 text-muted">회원당 발급(다운로드) 가능 횟수</div>
            </div>
            <div class="row mb-3">
                <label class="col-sm-2 col-form-label">첫 주문 전용</label>
                <div class="col-sm-4 pt-2">
                    <div class="form-check">
                        <input type="checkbox" name="formData[first_order_only]" class="form-check-input" value="1" <?= ($coupon['first_order_only'] ?? 0) ? 'checked' : '' ?>>
                        <label class="form-check-label">첫 주문에만 사용 가능</label>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-hero">
            <i class="bi bi-ticket-perforated text-pastel-sky"></i>
            <span>발행 제한</span>
        </div>
        <div class="card-body">
            <div class="row mb-3">
                <label class="col-sm-2 col-form-label">총 발행 수량</label>
                <div class="col-sm-3"><input type="number" name="formData[total_issue_limit]" class="form-control" value="<?= $coupon['total_issue_limit'] ?? '' ?>" placeholder="무제한"></div>
                <div class="col-sm-4 pt-2 text-muted">비워두면 무제한</div>
            </div>
            <div class="row mb-3">
                <label class="col-sm-2 col-form-label">프로모션 코드</label>
                <div class="col-sm-4"><input type="text" name="formData[promotion_code]" class="form-control text-uppercase" value="<?= htmlspecialchars($coupon['promotion_code'] ?? '') ?>" placeholder="예: WELCOME2026"></div>
                <div class="col-sm-4 pt-2 text-muted">회원이 직접 입력하여 발급받는 코드</div>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-hero">
            <i class="bi bi-calendar-range text-pastel-orange"></i>
            <span>기간 설정</span>
        </div>
        <div class="card-body">
            <div class="row mb-3">
                <label class="col-sm-2 col-form-label">발행 기간</label>
                <div class="col-sm-4 mb-2 mb-sm-0">
                    <div class="input-group">
                        <span class="input-group-text">시작</span>
                        <input type="datetime-local" name="formData[issue_start]" class="form-control" value="<?= $coupon['issue_start'] ?? '' ?>">
                    </div>
                </div>
                <div class="col-auto text-center pt-2 d-none d-sm-block">~</div>
                <div class="col-sm-4">
                    <div class="input-group">
                        <span class="input-group-text">종료</span>
                        <input type="datetime-local" name="formData[issue_end]" class="form-control" value="<?= $coupon['issue_end'] ?? '' ?>">
                    </div>
                </div>
            </div>
            <div class="row mb-3">
                <label class="col-sm-2 col-form-label">사용 가능일</label>
                <div class="col-sm-3"><input type="number" name="formData[valid_days]" class="form-control" value="<?= $coupon['valid_days'] ?? '' ?>" placeholder="발행일로부터 N일"></div>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-body">
            <div class="form-check">
                <input type="checkbox" name="formData[is_active]" class="form-check-input" value="1" <?= ($coupon['is_active'] ?? 1) ? 'checked' : '' ?>>
                <label class="form-check-label">활성화</label>
            </div>
        </div>
    </div>

        <!-- 하단 버튼 -->
        <div class="d-flex justify-content-center gap-2">
            <a href="/admin/shop/coupons" class="btn btn-secondary">목록</a>
            <button type="button" class="btn btn-primary mublo-submit"
                data-target="/admin/shop/coupons/store"
                data-callback="couponSaved"><?= $isEdit ? '수정' : '등록' ?></button>
        </div>
    </div>
</form>

<!-- 상품 검색 모달 -->
<div class="modal fade" id="goodsSearchModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">상품 검색</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="input-group mb-3">
                    <input type="text" class="form-control" id="goodsSearchKeyword" placeholder="상품명으로 검색">
                    <button type="button" class="btn btn-primary" id="btnDoSearch">검색</button>
                </div>
                <div id="goodsSearchResults" style="max-height:400px;overflow-y:auto;"></div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const couponType = document.querySelector('[name="formData[coupon_type]"]');
    const couponMethod = document.getElementById('couponMethod');
    const autoRow = document.getElementById('autoTriggerRow');
    const trigger = document.getElementById('autoIssueTrigger');
    const targetGoodsRow = document.getElementById('targetGoodsRow');
    const targetCategoryRow = document.getElementById('targetCategoryRow');

    const typeDescs = {
        ADMIN: { title: '관리자 발행', body: '관리자가 직접 특정 회원에게 쿠폰을 발행합니다. 수동으로 대상을 지정하여 개별 발행할 수 있습니다.' },
        AUTO: { title: '자동 발행', body: '회원가입, 등급변경, 로그인 등 특정 이벤트 발생 시 자동으로 쿠폰이 발행됩니다. 아래에서 자동 발행 조건을 선택하세요.' },
        DOWNLOAD: { title: '다운로드 발행', body: '쿠폰함 페이지에서 회원이 직접 다운로드하여 발급받습니다. 프로모션 코드를 설정하면 코드 입력으로도 발급 가능합니다.' }
    };

    const methodDescs = {
        ORDER: { title: '전체 (주문금액)', body: '주문 전체 금액에서 할인을 적용합니다. 특정 상품이나 카테고리에 관계없이 주문 금액 기준으로 할인됩니다.' },
        GOODS: { title: '특정 상품', body: '지정한 상품 구매 시에만 할인을 적용합니다. 검색 버튼으로 대상 상품을 선택할 수 있습니다.' },
        CATEGORY: { title: '특정 카테고리', body: '지정한 카테고리의 상품 구매 시에만 할인을 적용합니다. 드롭다운에서 카테고리를 선택하세요.' },
        SHIPPING: { title: '배송비 할인', body: '배송비에 대해 할인을 적용합니다. 주문 금액이 아닌 배송비를 기준으로 할인 금액이 계산됩니다.' }
    };

    function updateTypeDesc() {
        const desc = typeDescs[couponType.value] || typeDescs.ADMIN;
        document.getElementById('typeDescTitle').textContent = desc.title;
        document.getElementById('typeDescBody').textContent = desc.body;
        autoRow.style.display = couponType.value === 'AUTO' ? '' : 'none';
        if (couponType.value !== 'AUTO') trigger.value = '';
    }

    function updateMethodDesc() {
        const val = couponMethod.value;
        const desc = methodDescs[val] || methodDescs.ORDER;
        document.getElementById('methodDescTitle').textContent = desc.title;
        document.getElementById('methodDescBody').textContent = desc.body;

        targetGoodsRow.style.display = val === 'GOODS' ? '' : 'none';
        targetCategoryRow.style.display = val === 'CATEGORY' ? '' : 'none';

        if (val === 'CATEGORY' && targetCategorySelect.options.length <= 1) {
            loadCategories();
        }
    }

    couponType.addEventListener('change', updateTypeDesc);
    couponMethod.addEventListener('change', updateMethodDesc);
    updateTypeDesc();
    updateMethodDesc();

    // === 상품 검색 모달 ===
    const goodsModal = new bootstrap.Modal(document.getElementById('goodsSearchModal'));
    const searchKeyword = document.getElementById('goodsSearchKeyword');
    const searchResults = document.getElementById('goodsSearchResults');
    const targetGoodsId = document.getElementById('targetGoodsId');
    const targetGoodsName = document.getElementById('targetGoodsName');

    document.getElementById('btnSearchGoods').addEventListener('click', function() {
        searchKeyword.value = '';
        searchResults.innerHTML = '<p class="text-muted text-center py-3">상품명을 입력 후 검색하세요</p>';
        goodsModal.show();
    });

    document.getElementById('btnClearGoods').addEventListener('click', function() {
        targetGoodsId.value = '';
        targetGoodsName.value = '';
        document.getElementById('goodsInputGroup').classList.remove('has-clear');
    });

    document.getElementById('btnDoSearch').addEventListener('click', doGoodsSearch);
    searchKeyword.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') { e.preventDefault(); doGoodsSearch(); }
    });

    function doGoodsSearch() {
        const keyword = searchKeyword.value.trim();
        if (!keyword) return;

        searchResults.innerHTML = '<p class="text-center py-3">검색 중...</p>';
        MubloRequest.requestQuery('/admin/shop/block-items', { keyword: keyword })
            .then(function(res) {
                const items = (res.data && res.data.items) || [];
                if (items.length === 0) {
                    searchResults.innerHTML = '<p class="text-muted text-center py-3">검색 결과가 없습니다</p>';
                    return;
                }
                let html = '<table class="table table-sm table-hover mb-0"><thead><tr><th>ID</th><th>상품명</th><th>가격</th><th></th></tr></thead><tbody>';
                items.forEach(function(item) {
                    html += '<tr>'
                        + '<td>' + item.id + '</td>'
                        + '<td>' + (item.label || '') + '</td>'
                        + '<td>' + (item.price || '') + '</td>'
                        + '<td><button type="button" class="btn btn-sm btn-outline-primary btn-select-goods" data-id="' + item.id + '" data-name="' + (item.label || '').replace(/"/g, '&quot;') + '">선택</button></td>'
                        + '</tr>';
                });
                html += '</tbody></table>';
                searchResults.innerHTML = html;
            });
    }

    searchResults.addEventListener('click', function(e) {
        const btn = e.target.closest('.btn-select-goods');
        if (!btn) return;
        targetGoodsId.value = btn.dataset.id;
        targetGoodsName.value = btn.dataset.name;
        document.getElementById('goodsInputGroup').classList.add('has-clear');
        goodsModal.hide();
    });

    // === 카테고리 드롭다운 ===
    const targetCategorySelect = document.getElementById('targetCategorySelect');
    const savedCategory = <?= json_encode($coupon['target_category'] ?? '') ?>;
    let categoriesLoaded = false;

    function loadCategories() {
        if (categoriesLoaded) return;
        categoriesLoaded = true;

        MubloRequest.requestQuery('/admin/shop/block-items', { include_categories: '1', keyword: '__none__' })
            .then(function(res) {
                const categories = (res.data && res.data.categories) || [];
                // path_code 깊이 계산 (parent_code 기반)
                const depthMap = {};
                categories.forEach(function(cat) {
                    depthMap[cat.path_code] = cat.parent_code
                        ? (depthMap[cat.parent_code] || 0) + 1
                        : 0;
                });
                categories.forEach(function(cat) {
                    const opt = document.createElement('option');
                    opt.value = cat.path_code || cat.code || '';
                    const depth = depthMap[cat.path_code] || 0;
                    const indent = '\u00A0\u00A0'.repeat(depth);
                    opt.textContent = indent + (cat.name || '');
                    if (opt.value === savedCategory) opt.selected = true;
                    targetCategorySelect.appendChild(opt);
                });
            });
    }

    // 수정 모드에서 카테고리가 선택되어 있으면 미리 로드
    if (savedCategory || couponMethod.value === 'CATEGORY') {
        loadCategories();
    }

    // 수정 모드에서 상품이 지정되어 있으면 이름 로드 (Controller에서 target_goods_name 전달됨)
    // Controller에서 못 가져온 경우 fallback
    const savedGoodsId = <?= json_encode($coupon['target_goods_id'] ?? '') ?>;
    if (savedGoodsId && !targetGoodsName.value) {
        MubloRequest.requestQuery('/admin/shop/block-items', { keyword: '' })
            .then(function(res) {
                const items = (res.data && res.data.items) || [];
                const found = items.find(function(i) { return String(i.id) === String(savedGoodsId); });
                if (found) targetGoodsName.value = found.label || '';
            });
    }
});

MubloRequest.registerCallback('couponSaved', function(response) {
    if (response.result === 'success') {
        alert(response.message || '저장되었습니다.');
        <?php if ($isEdit): ?>
        location.reload();
        <?php else: ?>
        location.href = response.data?.redirect || '/admin/shop/coupons';
        <?php endif; ?>
    } else {
        alert(response.message || '저장에 실패했습니다.');
    }
});
</script>
