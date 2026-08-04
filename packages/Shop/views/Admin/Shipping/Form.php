<?php
/**
 * 배송 템플릿 등록/수정 폼
 *
 * @var bool $isEdit 수정 모드 여부
 * @var array|null $template 배송 템플릿 데이터
 * @var array $shippingMethodOptions 배송 방법 옵션
 * @var array $deliveryCompanies 택배사 목록
 */

$t = $template ?? [];
?>

<form id="shippingForm">
    <!-- 신규 등록 시엔 빈 값. 저장 성공 후 JS가 발급된 id를 채워 수정 모드로 전환(재저장 중복 방지) -->
    <input type="hidden" name="formData[shipping_id]" id="shippingIdField" value="<?= htmlspecialchars((string) ($t['shipping_id'] ?? '')) ?>">
    <div class="page-container form-container">
        <div class="page-title">
            <div class="page-title-text">
                <h3 id="shippingFormTitle"><?= $isEdit ? '배송 템플릿 수정' : '배송 템플릿 등록' ?></h3>
                <p>배송비 정책과 반품/교환 정보를 설정합니다.</p>
            </div>
            <div class="page-title-actions">
                <a href="/admin/shop/shipping" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-list"></i> 목록
                </a>
                <button type="button" class="btn btn-sm btn-primary mublo-submit"
                        data-target="/admin/shop/shipping/store" data-callback="shippingSaved">
                    <i class="bi bi-check-lg"></i> <?= $isEdit ? '수정' : '등록' ?>
                </button>
                <?php if ($isEdit): ?>
                <button type="button" class="btn btn-sm btn-outline-danger"
                        onclick="if(confirm('이 배송 템플릿을 삭제하시겠습니까?')) MubloRequest.requestJson('/admin/shop/shipping/<?= $t['shipping_id'] ?>/delete', {}, {callback: 'shippingDeleted'})">
                    <i class="bi bi-trash"></i> 삭제
                </button>
                <?php endif; ?>
            </div>
        </div>

        <div class="page-block">
            <!-- 기본 정보 -->
            <div class="card mb-4">
                <div class="card-hero">
                    <i class="bi bi-info-circle text-pastel-blue"></i>
                    <span>기본 정보</span>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <label class="col-sm-2 col-form-label">템플릿명 <span class="text-danger">*</span></label>
                        <div class="col-sm-4">
                            <input type="text" name="formData[name]" class="form-control"
                                   value="<?= htmlspecialchars($t['name'] ?? '') ?>" required>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <label class="col-sm-2 col-form-label">배송 방법</label>
                        <div class="col-sm-4">
                            <select name="formData[shipping_method]" class="form-select" id="shippingMethod">
                                <?php foreach ($shippingMethodOptions as $val => $lbl): ?>
                                    <option value="<?= $val ?>" <?= ($t['shipping_method'] ?? 'PAID') === $val ? 'selected' : '' ?>><?= $lbl ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <label class="col-sm-2 col-form-label">사용 여부</label>
                        <div class="col-sm-4">
                            <select name="formData[is_active]" class="form-select">
                                <option value="1" <?= ($t['is_active'] ?? 1) ? 'selected' : '' ?>>사용</option>
                                <option value="0" <?= ($t['is_active'] ?? 1) ? '' : 'selected' ?>>사용 안 함</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 배송비 설정 -->
            <div class="card mb-4">
                <div class="card-hero">
                    <i class="bi bi-currency-dollar text-pastel-green"></i>
                    <span>배송비 설정</span>
                </div>
                <div class="card-body">
                    <div class="row mb-3" id="rowBasicCost">
                        <label class="col-sm-2 col-form-label">기본 배송비</label>
                        <div class="col-sm-4">
                            <div class="input-group">
                                <input type="number" name="formData[basic_cost]" class="form-control"
                                       value="<?= (int)($t['basic_cost'] ?? 3000) ?>" min="0">
                                <span class="input-group-text">원</span>
                            </div>
                        </div>
                    </div>
                    <div class="row mb-3" id="rowFreeThreshold">
                        <label class="col-sm-2 col-form-label">무료 배송 기준</label>
                        <div class="col-sm-4">
                            <div class="input-group">
                                <input type="number" name="formData[free_threshold]" class="form-control"
                                       value="<?= (int)($t['free_threshold'] ?? 50000) ?>" min="0">
                                <span class="input-group-text">원 이상</span>
                            </div>
                        </div>
                    </div>
                    <div class="row mb-3" id="rowGoodsPerUnit">
                        <label class="col-sm-2 col-form-label">수량 단위</label>
                        <div class="col-sm-4">
                            <div class="input-group">
                                <input type="number" name="formData[goods_per_unit]" class="form-control"
                                       value="<?= (int)($t['goods_per_unit'] ?? 1) ?>" min="1">
                                <span class="input-group-text">개 당</span>
                            </div>
                        </div>
                        <div class="col-sm-4 pt-2">
                            <span class="form-text">해당 수량마다 기본 배송비가 적용됩니다.</span>
                        </div>
                    </div>
                    <div class="row mb-3" id="rowPriceRanges">
                        <label class="col-sm-2 col-form-label">구간별 배송비</label>
                        <div class="col-sm-10">
                            <button type="button" class="btn btn-outline-secondary" id="btnAddRange">
                                <i class="bi bi-plus-lg"></i> 구간 추가
                            </button>
                            <div id="priceRangesContainer" class="mt-2"></div>
                            <input type="hidden" name="formData[price_ranges]" id="priceRangesJson"
                                   value="<?= htmlspecialchars($t['price_ranges'] ?? '[]') ?>">
                        </div>
                    </div>
                </div>
            </div>

            <!-- 추가 배송비 설정 -->
            <div class="card mb-4">
                <div class="card-hero">
                    <i class="bi bi-geo text-pastel-orange"></i>
                    <span>추가 배송비 설정</span>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <label class="col-sm-2 col-form-label">사용 여부</label>
                        <div class="col-sm-4">
                            <select name="formData[extra_cost_enabled]" class="form-select" id="extraCostEnabled">
                                <option value="1" <?= ($t['extra_cost_enabled'] ?? 0) ? 'selected' : '' ?>>사용</option>
                                <option value="0" <?= ($t['extra_cost_enabled'] ?? 0) ? '' : 'selected' ?>>사용 안 함</option>
                            </select>
                        </div>
                    </div>
                    <!-- 사용 시 노출: 지역(우편번호)별 추가배송비 입력 -->
                    <div id="extraCostFields" style="display:none;">
                        <div class="row mb-3">
                            <label class="col-sm-2 col-form-label">지역(우편번호)별</label>
                            <div class="col-sm-10">
                                <button type="button" class="btn btn-outline-secondary" id="btnAddExtraRange">
                                    <i class="bi bi-plus-lg"></i> 대역 추가
                                </button>
                                <div id="extraRangesContainer" class="mt-2"></div>
                                <input type="hidden" name="formData[extra_cost_ranges]" id="extraRangesJson"
                                       value="<?= htmlspecialchars($t['extra_cost_ranges'] ?? '[]') ?>">
                                <span class="form-text d-block mt-1">
                                    배송지 우편번호가 해당 대역에 들면 기본 배송비에 추가요금이 더해집니다(무료배송이어도 부과).
                                    대역이 겹치면 <strong>더 좁게(구체적으로) 지정한 대역</strong>이 적용됩니다(순서 무관). 예: "광주" 안의 "광주시청"이 우선.
                                    제주·울릉도 대표 대역을 미리 채워두었으니 금액만 조정하거나, 필요에 따라 행을 추가/삭제하세요.
                                    (전국 도서산간을 모두 포함하지는 않습니다.)
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 반품/교환 -->
            <div class="card mb-4">
                <div class="card-hero">
                    <i class="bi bi-arrow-return-left text-pastel-purple"></i>
                    <span>반품 / 교환</span>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <label class="col-sm-2 col-form-label">반품 배송비</label>
                        <div class="col-sm-4">
                            <div class="input-group">
                                <input type="number" name="formData[return_cost]" class="form-control"
                                       value="<?= (int)($t['return_cost'] ?? 3000) ?>" min="0">
                                <span class="input-group-text">원</span>
                            </div>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <label class="col-sm-2 col-form-label">교환 배송비</label>
                        <div class="col-sm-4">
                            <div class="input-group">
                                <input type="number" name="formData[exchange_cost]" class="form-control"
                                       value="<?= (int)($t['exchange_cost'] ?? 6000) ?>" min="0">
                                <span class="input-group-text">원</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 배송 정보 -->
            <div class="card mb-4">
                <div class="card-hero">
                    <i class="bi bi-truck text-pastel-sky"></i>
                    <span>배송 정보</span>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <label class="col-sm-2 col-form-label">배송 수단</label>
                        <div class="col-sm-4">
                            <select name="formData[delivery_method]" class="form-select">
                                <option value="COURIER" <?= ($t['delivery_method'] ?? 'COURIER') === 'COURIER' ? 'selected' : '' ?>>택배</option>
                                <option value="POSTAL" <?= ($t['delivery_method'] ?? '') === 'POSTAL' ? 'selected' : '' ?>>우편</option>
                                <option value="PICKUP" <?= ($t['delivery_method'] ?? '') === 'PICKUP' ? 'selected' : '' ?>>직접수령</option>
                                <option value="OWN" <?= ($t['delivery_method'] ?? '') === 'OWN' ? 'selected' : '' ?>>자체배송</option>
                                <option value="ETC" <?= ($t['delivery_method'] ?? '') === 'ETC' ? 'selected' : '' ?>>기타</option>
                            </select>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <label class="col-sm-2 col-form-label">택배사</label>
                        <div class="col-sm-4">
                            <select name="formData[delivery_company_id]" class="form-select">
                                <option value="0">선택 안함</option>
                                <?php foreach ($deliveryCompanies as $company): ?>
                                    <option value="<?= $company['company_id'] ?>"
                                        <?= (int)($t['delivery_company_id'] ?? 0) === (int)$company['company_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($company['company_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <label class="col-sm-2 col-form-label">배송 안내</label>
                        <div class="col-sm-6">
                            <input type="text" name="formData[shipping_guide]" class="form-control"
                                   value="<?= htmlspecialchars($t['shipping_guide'] ?? '') ?>"
                                   placeholder="예: 평균 2~3일 소요">
                        </div>
                    </div>
                </div>
            </div>

            <!-- 출고지 주소 -->
            <div class="card mb-4">
                <div class="card-hero">
                    <i class="bi bi-geo-alt text-pastel-orange"></i>
                    <span>출고지</span>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <label class="col-sm-2 col-form-label">우편번호</label>
                        <div class="col-sm-2">
                            <input type="text" name="formData[origin_zipcode]" id="originZipcode" class="form-control"
                                   value="<?= htmlspecialchars($t['origin_zipcode'] ?? '') ?>" maxlength="10" readonly>
                        </div>
                        <div class="col-sm-4 d-flex gap-1">
                            <button type="button" class="btn btn-outline-secondary btn-zip-search" data-zip-target="origin">우편번호 찾기</button>
                            <button type="button" class="btn btn-outline-secondary btn-zip-reset" data-zip-target="origin">초기화</button>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <label class="col-sm-2 col-form-label">주소</label>
                        <div class="col-sm-6">
                            <input type="text" name="formData[origin_address1]" id="originAddress1" class="form-control mb-2"
                                   value="<?= htmlspecialchars($t['origin_address1'] ?? '') ?>" placeholder="기본 주소" readonly>
                            <input type="text" name="formData[origin_address2]" id="originAddress2" class="form-control"
                                   value="<?= htmlspecialchars($t['origin_address2'] ?? '') ?>" placeholder="상세 주소">
                        </div>
                    </div>
                </div>
            </div>

            <!-- 반품지 주소 -->
            <div class="card mb-4">
                <div class="card-hero">
                    <i class="bi bi-geo-alt text-pastel-blue"></i>
                    <span>반품/교환지</span>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <label class="col-sm-2 col-form-label">우편번호</label>
                        <div class="col-sm-2">
                            <input type="text" name="formData[return_zipcode]" id="returnZipcode" class="form-control"
                                   value="<?= htmlspecialchars($t['return_zipcode'] ?? '') ?>" maxlength="10" readonly>
                        </div>
                        <div class="col-sm-4 d-flex gap-1">
                            <button type="button" class="btn btn-outline-secondary btn-zip-search" data-zip-target="return">우편번호 찾기</button>
                            <button type="button" class="btn btn-outline-secondary btn-zip-reset" data-zip-target="return">초기화</button>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <label class="col-sm-2 col-form-label">주소</label>
                        <div class="col-sm-6">
                            <input type="text" name="formData[return_address1]" id="returnAddress1" class="form-control mb-2"
                                   value="<?= htmlspecialchars($t['return_address1'] ?? '') ?>" placeholder="기본 주소" readonly>
                            <input type="text" name="formData[return_address2]" id="returnAddress2" class="form-control"
                                   value="<?= htmlspecialchars($t['return_address2'] ?? '') ?>" placeholder="상세 주소">
                        </div>
                    </div>
                </div>
            </div>

            <!-- 하단 버튼 -->
            <div class="d-flex justify-content-center gap-2">
                <a href="/admin/shop/shipping" class="btn btn-secondary">목록</a>
                <button type="button" class="btn btn-primary mublo-submit"
                        data-target="/admin/shop/shipping/store" data-callback="shippingSaved"><?= $isEdit ? '수정' : '등록' ?></button>
            </div>
        </div><!-- /.page-block -->
    </div>
</form>

<!-- 다음(Daum) 우편번호 검색 API -->
<script src="//t1.daumcdn.net/mapjsapi/bundle/postcode/prod/postcode.v2.js"></script>
<script>
(function() {
    // 우편번호 찾기 (출고지/반품지 공용) — data-zip-target 으로 대상 구분
    document.querySelectorAll('.btn-zip-search').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const target = this.dataset.zipTarget; // 'origin' | 'return'
            new daum.Postcode({
                oncomplete: function(data) {
                    document.getElementById(target + 'Zipcode').value = data.zonecode;
                    document.getElementById(target + 'Address1').value = data.roadAddress || data.jibunAddress;
                    document.getElementById(target + 'Address2').focus();
                }
            }).open();
        });
    });

    // 우편번호 초기화 (readonly 라 직접 못 지우므로 버튼으로 비움)
    document.querySelectorAll('.btn-zip-reset').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const target = this.dataset.zipTarget; // 'origin' | 'return'
            document.getElementById(target + 'Zipcode').value = '';
            document.getElementById(target + 'Address1').value = '';
            document.getElementById(target + 'Address2').value = '';
        });
    });

    const methodSelect = document.getElementById('shippingMethod');
    const rowBasicCost = document.getElementById('rowBasicCost');
    const rowFreeThreshold = document.getElementById('rowFreeThreshold');
    const rowGoodsPerUnit = document.getElementById('rowGoodsPerUnit');
    const rowPriceRanges = document.getElementById('rowPriceRanges');

    function toggleFields() {
        const method = methodSelect.value;
        rowBasicCost.style.display = method === 'FREE' ? 'none' : '';
        rowFreeThreshold.style.display = method === 'COND' ? '' : 'none';
        rowGoodsPerUnit.style.display = method === 'QUANTITY' ? '' : 'none';
        rowPriceRanges.style.display = method === 'AMOUNT' ? '' : 'none';
    }

    methodSelect.addEventListener('change', toggleFields);
    toggleFields();

    // 도서산간 추가배송비: 사용 선택 시 입력 영역 노출
    const extraCostSelect = document.getElementById('extraCostEnabled');
    const extraCostFields = document.getElementById('extraCostFields');
    const extraContainer = document.getElementById('extraRangesContainer');
    const extraHidden = document.getElementById('extraRangesJson');

    // 사용 ON 인데 저장값이 비어 있으면 채워줄 대표 대역 (관리자가 편집/삭제 가능)
    const EXTRA_DEFAULTS = [
        { label: '제주',   zip_from: '63000', zip_to: '63644', cost: 3000 },
        { label: '울릉도', zip_from: '40200', zip_to: '40240', cost: 5000 }
    ];

    let extraRanges = [];
    try { extraRanges = JSON.parse(extraHidden.value) || []; } catch(e) { extraRanges = []; }

    function syncExtraRanges() {
        extraHidden.value = JSON.stringify(extraRanges);
    }

    function renderExtraRanges() {
        extraContainer.innerHTML = '';
        extraRanges.forEach((range, idx) => {
            const row = document.createElement('div');
            row.className = 'd-flex align-items-center gap-2 mb-2';
            row.innerHTML =
                '<input type="text" class="form-control" style="width:120px" value="' + (range.label || '') + '" placeholder="지역명" data-idx="' + idx + '" data-field="label">' +
                '<input type="text" class="form-control" style="width:110px" value="' + (range.zip_from || '') + '" placeholder="우편 시작" data-idx="' + idx + '" data-field="zip_from">' +
                '<span class="text-muted">~</span>' +
                '<input type="text" class="form-control" style="width:110px" value="' + (range.zip_to || '') + '" placeholder="우편 끝" data-idx="' + idx + '" data-field="zip_to">' +
                '<span class="text-muted ms-2">추가비</span>' +
                '<input type="number" class="form-control" style="width:110px" value="' + (range.cost || 0) + '" placeholder="추가비" data-idx="' + idx + '" data-field="cost" min="0">' +
                '<span class="text-muted">원</span>' +
                '<button type="button" class="btn btn-outline-danger" data-idx="' + idx + '"><i class="bi bi-x-lg"></i></button>';
            extraContainer.appendChild(row);
        });

        extraContainer.querySelectorAll('input[data-idx]').forEach(input => {
            input.addEventListener('change', function() {
                const field = this.dataset.field;
                extraRanges[this.dataset.idx][field] = field === 'cost' ? (parseInt(this.value) || 0) : this.value.trim();
                syncExtraRanges();
            });
        });

        extraContainer.querySelectorAll('button[data-idx]').forEach(btn => {
            btn.addEventListener('click', function() {
                extraRanges.splice(parseInt(this.dataset.idx), 1);
                renderExtraRanges();
            });
        });

        syncExtraRanges();
    }

    function toggleExtraCost() {
        const on = extraCostSelect.value === '1';
        extraCostFields.style.display = on ? '' : 'none';
        // 사용 ON 인데 비어 있으면 대표 대역 프리필
        if (on && extraRanges.length === 0) {
            extraRanges = EXTRA_DEFAULTS.map(r => Object.assign({}, r));
            renderExtraRanges();
        }
    }

    document.getElementById('btnAddExtraRange').addEventListener('click', function() {
        extraRanges.push({ label: '', zip_from: '', zip_to: '', cost: 0 });
        renderExtraRanges();
    });

    extraCostSelect.addEventListener('change', toggleExtraCost);
    renderExtraRanges();
    toggleExtraCost();

    // 구간별 배송비 관리
    const container = document.getElementById('priceRangesContainer');
    const hiddenInput = document.getElementById('priceRangesJson');
    let ranges = [];
    try { ranges = JSON.parse(hiddenInput.value) || []; } catch(e) { ranges = []; }

    function renderRanges() {
        container.innerHTML = '';
        ranges.forEach((range, idx) => {
            const row = document.createElement('div');
            row.className = 'd-flex align-items-center gap-2 mb-2';
            row.innerHTML =
                '<input type="number" class="form-control" style="width:120px" value="' + (range.min || 0) + '" placeholder="최소" data-idx="' + idx + '" data-field="min">' +
                '<span class="text-muted">~</span>' +
                '<input type="number" class="form-control" style="width:120px" value="' + (range.max || 0) + '" placeholder="최대" data-idx="' + idx + '" data-field="max">' +
                '<span class="text-muted">원</span>' +
                '<span class="text-muted ms-2">배송비</span>' +
                '<input type="number" class="form-control" style="width:120px" value="' + (range.cost || 0) + '" placeholder="배송비" data-idx="' + idx + '" data-field="cost">' +
                '<span class="text-muted">원</span>' +
                '<button type="button" class="btn btn-outline-danger" data-idx="' + idx + '"><i class="bi bi-x-lg"></i></button>';
            container.appendChild(row);
        });

        container.querySelectorAll('input[data-idx]').forEach(input => {
            input.addEventListener('change', function() {
                ranges[this.dataset.idx][this.dataset.field] = parseInt(this.value) || 0;
                syncRanges();
            });
        });

        container.querySelectorAll('button[data-idx]').forEach(btn => {
            btn.addEventListener('click', function() {
                ranges.splice(parseInt(this.dataset.idx), 1);
                renderRanges();
            });
        });

        syncRanges();
    }

    function syncRanges() {
        hiddenInput.value = JSON.stringify(ranges);
    }

    document.getElementById('btnAddRange').addEventListener('click', function() {
        ranges.push({ min: 0, max: 0, cost: 0 });
        renderRanges();
    });

    renderRanges();
})();

MubloRequest.registerCallback('shippingSaved', function(response) {
    if (response.result !== 'success') {
        alert(response.message || '저장에 실패했습니다.');
        return;
    }
    var d = response.data || {};
    // 신규 등록이었으면 발급된 id를 폼에 채워 수정 모드로 전환한다.
    // (hidden shipping_id 가 채워지면 다음 저장은 update → 재저장 중복 생성 방지)
    var idField = document.getElementById('shippingIdField');
    var wasCreate = idField && !idField.value;
    if (idField && d.shipping_id) {
        idField.value = d.shipping_id;
    }
    if (wasCreate) {
        var title = document.getElementById('shippingFormTitle');
        if (title) title.textContent = '배송 템플릿 수정';
        document.querySelectorAll('.mublo-submit[data-callback="shippingSaved"]').forEach(function(btn) {
            // 아이콘은 보존하고 라벨 텍스트만 교체
            btn.childNodes.forEach(function(node) {
                if (node.nodeType === 3 && node.textContent.trim()) node.textContent = ' 수정';
            });
        });
    }
    // 목록으로 빠지지 않고 폼에 머무르며 결과만 안내
    alert(response.message || '저장되었습니다.');
});

MubloRequest.registerCallback('shippingDeleted', function(response) {
    if (response.result === 'success') {
        alert(response.message || '삭제되었습니다.');
        location.href = '/admin/shop/shipping';
    } else {
        alert(response.message || '삭제에 실패했습니다.');
    }
});
</script>
