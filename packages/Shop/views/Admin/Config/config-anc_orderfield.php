<?php
/**
 * 쇼핑몰 설정 - 주문 추가 필드 탭
 *
 * @var array $orderFields 주문 추가 필드 목록
 */
$orderFields = $orderFields ?? [];

$fieldTypeOptions = [
    'text' => '텍스트',
    'email' => '이메일',
    'tel' => '전화번호',
    'number' => '숫자',
    'date' => '날짜',
    'textarea' => '긴 텍스트',
    'select' => '셀렉트',
    'radio' => '라디오',
    'checkbox' => '체크박스',
    'address' => '주소',
    'file' => '파일',
];
?>
<div class="card mb-4">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <p class="text-muted mb-0">체크아웃 시 고객에게 추가 정보를 받을 수 있습니다.</p>
            <button type="button" class="btn btn-sm btn-outline-primary" id="btnAddOrderField">
                <i class="bi bi-plus-lg"></i> 필드 추가
            </button>
        </div>

        <!-- 필드 목록 테이블 -->
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="orderFieldTable">
                <thead>
                    <tr>
                        <th style="width:40px"></th>
                        <th>필드명</th>
                        <th>라벨</th>
                        <th style="width:90px">타입</th>
                        <th style="width:60px" class="text-center">필수</th>
                        <th style="width:60px" class="text-center">암호화</th>
                        <th style="width:60px" class="text-center">활성</th>
                        <th style="width:100px" class="text-center">관리</th>
                    </tr>
                </thead>
                <tbody id="orderFieldBody">
                    <?php if (empty($orderFields)): ?>
                    <tr id="orderFieldEmpty">
                        <td colspan="8" class="text-center py-4 text-muted">등록된 주문 추가 필드가 없습니다.</td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($orderFields as $field): ?>
                    <tr data-field-id="<?= $field['field_id'] ?>">
                        <td class="handle text-center" style="cursor:grab"><i class="bi bi-arrows-move text-muted"></i></td>
                        <td><code style="font-size:0.8rem"><?= htmlspecialchars($field['field_name']) ?></code></td>
                        <td>
                            <?= htmlspecialchars($field['field_label']) ?>
                            <?php if (!empty($field['is_admin_only'])): ?>
                                <span class="badge bg-dark ms-1" title="관리자 전용">관리자</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge bg-secondary"><?= htmlspecialchars($fieldTypeOptions[$field['field_type']] ?? $field['field_type']) ?></span></td>
                        <td class="text-center"><?= $field['is_required'] ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<i class="bi bi-circle text-muted"></i>' ?></td>
                        <td class="text-center"><?= $field['is_encrypted'] ? '<span title="암호화 저장">🔒</span>' : '<i class="bi bi-circle text-muted"></i>' ?></td>
                        <td class="text-center"><?= $field['is_active'] ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<i class="bi bi-x-circle text-danger"></i>' ?></td>
                        <td class="text-center">
                            <button type="button" class="btn btn-sm btn-outline-secondary me-1 btn-edit-ofield"
                                    data-field='<?= htmlspecialchars(json_encode($field, JSON_UNESCAPED_UNICODE)) ?>'>
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-danger btn-delete-ofield"
                                    data-field-id="<?= $field['field_id'] ?>"
                                    data-field-label="<?= htmlspecialchars($field['field_label']) ?>">
                                <i class="bi bi-trash"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- 필드 추가/수정 모달 -->
<div class="modal fade" id="orderFieldModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="ofModalTitle">필드 추가</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="of_field_id" value="0">

                <div class="mb-3">
                    <label class="form-label">필드명 <span class="text-danger">*</span></label>
                    <input type="text" id="of_field_name" class="form-control" placeholder="영문 소문자, 숫자, 언더스코어">
                    <div class="form-text">영문 소문자로 시작, 수정 불가</div>
                </div>

                <div class="mb-3">
                    <label class="form-label">라벨 <span class="text-danger">*</span></label>
                    <input type="text" id="of_field_label" class="form-control" placeholder="사용자에게 표시되는 이름">
                </div>

                <div class="mb-3">
                    <label class="form-label">타입</label>
                    <select id="of_field_type" class="form-select">
                        <?php foreach ($fieldTypeOptions as $val => $label): ?>
                        <option value="<?= $val ?>"><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- 선택지 (select/radio/checkbox) -->
                <div class="mb-3" id="of_options_wrap" style="display:none">
                    <label class="form-label">선택지</label>
                    <div id="of_options_list"></div>
                    <button type="button" class="btn btn-sm btn-outline-secondary mt-2" id="of_add_option">
                        <i class="bi bi-plus"></i> 항목 추가
                    </button>
                </div>

                <!-- 파일 설정 -->
                <div class="mb-3" id="of_file_wrap" style="display:none">
                    <label class="form-label">파일 설정</label>
                    <div class="row g-2">
                        <div class="col-6">
                            <input type="number" id="of_file_max_size" class="form-control" placeholder="최대 용량 (MB)" value="5">
                            <div class="form-text">MB 단위</div>
                        </div>
                        <div class="col-6">
                            <input type="text" id="of_file_allowed_ext" class="form-control" placeholder="jpg,png,pdf">
                            <div class="form-text">허용 확장자 (콤마 구분)</div>
                        </div>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">안내 문구</label>
                    <input type="text" id="of_placeholder" class="form-control" placeholder="입력란에 표시할 안내 문구">
                </div>

                <div class="d-flex gap-4 flex-wrap">
                    <div class="form-check form-switch">
                        <input type="checkbox" id="of_is_required" class="form-check-input">
                        <label class="form-check-label" for="of_is_required">필수 입력</label>
                    </div>
                    <div class="form-check form-switch">
                        <input type="checkbox" id="of_is_encrypted" class="form-check-input">
                        <label class="form-check-label" for="of_is_encrypted">암호화 저장</label>
                    </div>
                    <div class="form-check form-switch">
                        <input type="checkbox" id="of_is_active" class="form-check-input" checked>
                        <label class="form-check-label" for="of_is_active">활성</label>
                    </div>
                    <div class="form-check form-switch">
                        <input type="checkbox" id="of_is_admin_only" class="form-check-input">
                        <label class="form-check-label" for="of_is_admin_only">관리자 전용</label>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">취소</button>
                <button type="button" class="btn btn-primary" id="ofModalSave">저장</button>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    var modal = null;
    var modalEl = document.getElementById('orderFieldModal');

    function getModal() {
        if (!modal) modal = new bootstrap.Modal(modalEl);
        return modal;
    }

    // ── 타입 변경 → 옵션/파일 영역 토글 ──
    var typeSelect = document.getElementById('of_field_type');
    typeSelect.addEventListener('change', function() {
        toggleTypeFields(this.value);
    });

    function toggleTypeFields(type) {
        var optTypes = ['select', 'radio', 'checkbox'];
        document.getElementById('of_options_wrap').style.display = optTypes.includes(type) ? '' : 'none';
        document.getElementById('of_file_wrap').style.display = type === 'file' ? '' : 'none';
    }

    // ── 선택지 행 추가/삭제 ──
    document.getElementById('of_add_option').addEventListener('click', function() {
        addOptionRow('');
    });

    function addOptionRow(value) {
        var list = document.getElementById('of_options_list');
        var row = document.createElement('div');
        row.className = 'd-flex gap-2 mb-2 of-option-row';
        row.innerHTML = '<input type="text" class="form-control form-control-sm of-option-input" value="' + escAttr(value) + '" placeholder="선택지 값">'
            + '<button type="button" class="btn btn-sm btn-outline-danger of-option-remove"><i class="bi bi-x"></i></button>';
        list.appendChild(row);

        row.querySelector('.of-option-remove').addEventListener('click', function() {
            row.remove();
        });
    }

    function escAttr(str) {
        var d = document.createElement('div');
        d.textContent = str || '';
        return d.innerHTML.replace(/"/g, '&quot;');
    }

    function clearOptions() {
        document.getElementById('of_options_list').innerHTML = '';
    }

    function getOptions() {
        var inputs = document.querySelectorAll('.of-option-input');
        var opts = [];
        inputs.forEach(function(el) {
            var v = el.value.trim();
            if (v) opts.push(v);
        });
        return opts;
    }

    // ── 모달 리셋 ──
    function resetModal() {
        document.getElementById('of_field_id').value = '0';
        document.getElementById('of_field_name').value = '';
        document.getElementById('of_field_name').readOnly = false;
        document.getElementById('of_field_label').value = '';
        document.getElementById('of_field_type').value = 'text';
        document.getElementById('of_placeholder').value = '';
        document.getElementById('of_is_required').checked = false;
        document.getElementById('of_is_encrypted').checked = false;
        document.getElementById('of_is_active').checked = true;
        document.getElementById('of_is_admin_only').checked = false;
        document.getElementById('of_file_max_size').value = '5';
        document.getElementById('of_file_allowed_ext').value = '';
        clearOptions();
        toggleTypeFields('text');
    }

    // ── 추가 버튼 ──
    document.getElementById('btnAddOrderField').addEventListener('click', function() {
        resetModal();
        document.getElementById('ofModalTitle').textContent = '필드 추가';
        getModal().show();
    });

    // ── 수정 버튼 (이벤트 위임) ──
    document.getElementById('orderFieldBody').addEventListener('click', function(e) {
        var editBtn = e.target.closest('.btn-edit-ofield');
        var deleteBtn = e.target.closest('.btn-delete-ofield');

        if (editBtn) {
            var field = JSON.parse(editBtn.getAttribute('data-field'));
            resetModal();
            document.getElementById('ofModalTitle').textContent = '필드 수정';
            document.getElementById('of_field_id').value = field.field_id;
            document.getElementById('of_field_name').value = field.field_name;
            document.getElementById('of_field_name').readOnly = true;
            document.getElementById('of_field_label').value = field.field_label;
            document.getElementById('of_field_type').value = field.field_type;
            document.getElementById('of_placeholder').value = field.placeholder || '';
            document.getElementById('of_is_required').checked = !!field.is_required;
            document.getElementById('of_is_encrypted').checked = !!field.is_encrypted;
            document.getElementById('of_is_active').checked = field.is_active !== 0 && field.is_active !== '0';
            document.getElementById('of_is_admin_only').checked = !!field.is_admin_only;

            toggleTypeFields(field.field_type);

            // 선택지 복원
            var opts = field.field_options;
            if (typeof opts === 'string' && opts) {
                try { opts = JSON.parse(opts); } catch(e) { opts = []; }
            }
            if (Array.isArray(opts)) {
                opts.forEach(function(o) {
                    addOptionRow(typeof o === 'object' ? (o.value || o.label || '') : o);
                });
            }

            // 파일 설정 복원
            var cfg = field.field_config;
            if (typeof cfg === 'string' && cfg) {
                try { cfg = JSON.parse(cfg); } catch(e) { cfg = {}; }
            }
            if (cfg && typeof cfg === 'object') {
                document.getElementById('of_file_max_size').value = cfg.max_size || '5';
                document.getElementById('of_file_allowed_ext').value = cfg.allowed_ext || '';
            }

            getModal().show();
        }

        if (deleteBtn) {
            var fieldId = deleteBtn.getAttribute('data-field-id');
            var fieldLabel = deleteBtn.getAttribute('data-field-label');
            if (!confirm('"' + fieldLabel + '" 필드를 삭제하시겠습니까?\n해당 필드의 기존 주문 데이터도 함께 삭제됩니다.')) return;

            MubloRequest.requestJson('/admin/shop/order-fields/delete', { field_id: parseInt(fieldId) })
                .then(function() {
                    location.reload();
                });
        }
    });

    // ── 모달 저장 ──
    document.getElementById('ofModalSave').addEventListener('click', function() {
        var fieldType = document.getElementById('of_field_type').value;
        var payload = {
            field_id: parseInt(document.getElementById('of_field_id').value) || 0,
            field_name: document.getElementById('of_field_name').value.trim(),
            field_label: document.getElementById('of_field_label').value.trim(),
            field_type: fieldType,
            placeholder: document.getElementById('of_placeholder').value.trim(),
            is_required: document.getElementById('of_is_required').checked ? 1 : 0,
            is_encrypted: document.getElementById('of_is_encrypted').checked ? 1 : 0,
            is_active: document.getElementById('of_is_active').checked ? 1 : 0,
            is_admin_only: document.getElementById('of_is_admin_only').checked ? 1 : 0,
        };

        if (!payload.field_name || !payload.field_label) {
            alert('필드명과 라벨을 입력해주세요.');
            return;
        }

        // 선택지
        if (['select', 'radio', 'checkbox'].includes(fieldType)) {
            payload.field_options = getOptions();
        }

        // 파일 설정
        if (fieldType === 'file') {
            payload.field_config = {
                max_size: parseInt(document.getElementById('of_file_max_size').value) || 5,
                allowed_ext: document.getElementById('of_file_allowed_ext').value.trim(),
            };
        }

        MubloRequest.requestJson('/admin/shop/order-fields/store', payload)
            .then(function() {
                getModal().hide();
                location.reload();
            });
    });

    // ── Sortable (드래그 순서 변경) ──
    if (typeof Sortable !== 'undefined') {
        new Sortable(document.getElementById('orderFieldBody'), {
            handle: '.handle',
            animation: 150,
            onEnd: function() {
                var ids = [];
                document.querySelectorAll('#orderFieldBody tr[data-field-id]').forEach(function(tr) {
                    ids.push(parseInt(tr.getAttribute('data-field-id')));
                });
                if (ids.length > 0) {
                    MubloRequest.requestJson('/admin/shop/order-fields/order-update', { field_ids: ids });
                }
            }
        });
    }
})();
</script>
