<?php
/**
 * 주문상태 FSM 설정 (단일 페이지)
 *
 * @var array $orderStates  주문 상태 배열 [{id, label, description, action, to, terminal, system, sort_order}, ...]
 * @var array $stateActions 상태별 액션 설정 {stateId: [{type, enabled, ...params}, ...], ...}
 * @var array $actionTypes  등록된 액션 타입 {type: label}
 * @var array $actionSchemas 액션 타입별 스키마 {type: {required, fields}}
 * @var array $actionDescriptions 액션 타입별 설명 {type: description}
 * @var array $actionAllowDuplicates 액션 타입별 중복 허용 여부 {type: bool}
 */

use Mublo\Packages\Shop\Enum\OrderAction;
?>

<div class="page-container">
    <div class="page-title">
        <div class="page-title-text">
            <h3>주문상태 설정</h3>
            <p>주문 처리 흐름의 상태 전이 규칙과 상태별 액션을 관리합니다.</p>
        </div>
    </div>

    <!-- FSM 폼 -->
    <div class="page-block">
        <form id="orderStateForm">
            <input type="hidden" name="formData[order_states]" id="order_states_json" value="">

            <div class="card">
                <div class="card-hero">
                    <i class="bi bi-diagram-3 text-pastel-blue"></i>
                    <span>상태 전이 규칙</span>
                    <div class="ms-auto">
                        <button type="button" class="btn btn-xs btn-outline-secondary me-1" id="btnAddCustomState">
                            <i class="bi bi-plus-lg"></i> 커스텀 상태 추가
                        </button>
                        <button type="button" class="btn btn-xs btn-primary mublo-submit"
                            data-target="/admin/shop/order-states/store"
                            data-callback="orderStateSaved">
                            <i class="bi bi-check-lg"></i> 저장
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <ul class="form-text mb-3 ps-3">
                        <li>
                            기본값으로 사용하시는 것이 가장 편리합니다.
                            필요한 경우 라벨을 수정하거나, 커스텀 상태를 추가하여 흐름을 확장할 수 있습니다.
                        </li>
                        <li>
                            <i class="bi bi-truck"></i> <strong>배송</strong> — 해당 상태에서 택배사·송장번호 등 배송정보를 입력/수정할 수 있는지 여부입니다.
                            체크된 상태에서만 관리자가 배송정보를 편집할 수 있습니다.
                        </li>
                        <li>
                            <span class="badge bg-warning text-dark me-1"><i class="bi bi-geo-alt-fill"></i> 현재 상태</span>
                            에서
                            <span class="badge bg-success me-1">활성 상태</span>
                            로만 이동할 수 있습니다.
                            <span class="text-muted small ms-1">비활성 버튼을 클릭하면 전이를 추가/제거할 수 있습니다.</span>
                        </li>
                    </ul>

                    <div class="table-responsive">
                        <table class="table table-sm table-bordered mb-0" id="orderStatesTable">
                            <thead>
                                <tr>
                                    <th style="width:40px" class="text-center">#</th>
                                    <th style="width:120px">ID</th>
                                    <th style="width:60px" class="text-center">유형</th>
                                    <th style="width:130px">라벨</th>
                                    <th class="text-center">이동 가능 (to)</th>
                                    <th style="width:60px" class="text-center"><i class="bi bi-truck"></i> 배송</th>
                                    <th style="width:50px" class="text-center">종료</th>
                                    <th style="width:90px" class="text-center">관리</th>
                                    <th style="width:70px" class="text-center">액션</th>
                                </tr>
                            </thead>
                            <tbody id="orderStatesBody">
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <!-- 액션 안내 -->
    <div class="page-block">
        <div class="card">
            <div class="card-hero">
                <i class="bi bi-info-circle text-pastel-green"></i>
                <span>액션이란?</span>
            </div>
            <div class="card-body">
                <p class="mb-2">
                    <strong>액션</strong>은 주문이 특정 상태에 진입할 때 <strong>자동으로 실행</strong>되는 작업입니다.
                    위 테이블의 <i class="bi bi-lightning"></i> 버튼을 눌러 각 상태별로 실행할 액션을 설정할 수 있습니다.
                </p>

                <table class="table table-sm table-bordered mb-0">
                    <thead>
                        <tr>
                            <th style="width:140px">액션 종류</th>
                            <th>설명</th>
                            <th style="width:100px" class="text-center">중복 등록</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($actionTypes as $type => $label): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($label) ?></strong></td>
                            <td class="text-muted"><?= htmlspecialchars($actionDescriptions[$type] ?? '') ?></td>
                            <td class="text-center">
                                <?php if ($actionAllowDuplicates[$type] ?? false): ?>
                                    <span class="badge bg-success">가능</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">불가</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div><!-- /.page-container -->

<!-- 커스텀 상태 추가 모달 -->
<div class="modal fade" id="addCustomModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">커스텀 상태 추가</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">상태 라벨 <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="customLabel" placeholder="예: 검수중" maxlength="30">
                </div>
                <div class="mb-0">
                    <label class="form-label">설명</label>
                    <input type="text" class="form-control" id="customDescription" placeholder="예: 상품 품질 검수 진행 중" maxlength="100">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">취소</button>
                <button type="button" class="btn btn-primary btn-sm" id="btnConfirmAddCustom">추가</button>
            </div>
        </div>
    </div>
</div>

<!-- 액션 설정 모달 -->
<div class="modal fade" id="actionModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-lightning"></i> 액션 설정 — <span id="actionModalLabel"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="actionModalBody">
            </div>
            <div class="modal-footer justify-content-between">
                <div id="actionAddDropdown"></div>
                <div>
                    <form id="stateActionsForm" class="d-inline">
                        <input type="hidden" name="formData[state_actions]" id="state_actions_json" value="">
                        <button type="button" id="stateActionsSaveBtn" class="btn btn-primary btn-sm mublo-submit"
                            data-target="/admin/shop/order-states/store-actions"
                            data-callback="stateActionsSaved">
                            <i class="bi bi-check-lg"></i> 액션 저장
                        </button>
                    </form>
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">닫기</button>
                </div>
            </div>
        </div>
    </div>
</div>


<script>
(function() {
    const systemActions = <?= json_encode(
        array_map(fn($case) => [
            'action' => $case->value,
            'label' => $case->defaultLabel(),
        ], OrderAction::systemCases()),
        JSON_UNESCAPED_UNICODE
    ) ?>;
    const systemActionValues = systemActions.map(a => a.action);

    let orderStates = <?= json_encode($orderStates, JSON_UNESCAPED_UNICODE) ?>;
    let stateActions = <?= json_encode($stateActions ?: new \stdClass(), JSON_UNESCAPED_UNICODE) ?>;
    const actionTypes = <?= json_encode($actionTypes ?: new \stdClass(), JSON_UNESCAPED_UNICODE) ?>;
    const actionSchemas = <?= json_encode($actionSchemas ?: new \stdClass(), JSON_UNESCAPED_UNICODE) ?>;
    const actionDescriptions = <?= json_encode($actionDescriptions ?: new \stdClass(), JSON_UNESCAPED_UNICODE) ?>;
    const actionAllowDuplicates = <?= json_encode($actionAllowDuplicates ?: new \stdClass(), JSON_UNESCAPED_UNICODE) ?>;

    const tbody = document.getElementById('orderStatesBody');
    const hiddenInput = document.getElementById('order_states_json');
    let currentActionStateId = null;
    // 모달 작업 사본: add/remove/필드편집은 여기에만 반영하고,
    // '액션 저장'을 눌러야 원본 stateActions에 커밋한다. 닫기/새로고침은 그대로 버려진다.
    let modalActions = [];

    function renderTable() {
        tbody.innerHTML = '';

        orderStates.forEach((state, idx) => {
            const isSystem = state.system;
            const isTerminal = state.terminal;
            const tr = document.createElement('tr');
            tr.dataset.index = idx;

            // 1. 순서
            const tdNum = document.createElement('td');
            tdNum.className = 'text-center text-muted align-middle';
            tdNum.textContent = idx + 1;
            tr.appendChild(tdNum);

            // 2. ID
            const tdId = document.createElement('td');
            tdId.className = 'align-middle';
            const idSpan = document.createElement('span');
            idSpan.className = 'state-id';
            if (isTerminal) {
                idSpan.style.color = state.id === 'confirmed' ? '#198754' : '#dc3545';
            }
            idSpan.textContent = state.id;
            tdId.appendChild(idSpan);
            tr.appendChild(tdId);

            // 3. 유형
            const tdType = document.createElement('td');
            tdType.className = 'text-center align-middle';
            const typeBadge = document.createElement('span');
            typeBadge.className = isSystem ? 'badge bg-primary' : 'badge bg-warning text-dark';
            typeBadge.style.fontSize = '0.7rem';
            typeBadge.textContent = isSystem ? '시스템' : '커스텀';
            tdType.appendChild(typeBadge);
            tr.appendChild(tdType);

            // 4. 라벨
            const tdLabel = document.createElement('td');
            tdLabel.className = 'align-middle';
            const labelInput = document.createElement('input');
            labelInput.type = 'text';
            labelInput.className = 'form-control form-control-sm';
            labelInput.value = state.label;
            labelInput.maxLength = 30;
            labelInput.addEventListener('change', function() {
                orderStates[idx].label = this.value.trim();
                syncFsmHidden();
            });
            tdLabel.appendChild(labelInput);
            tr.appendChild(tdLabel);

            // 5. 이동 가능 (to)
            const tdTo = document.createElement('td');
            tdTo.className = 'align-middle';
            if (isTerminal) {
                const termNote = document.createElement('span');
                termNote.className = 'text-muted small';
                termNote.textContent = '종료 상태';
                tdTo.appendChild(termNote);
            } else {
                tdTo.appendChild(createToggleList(idx, state));
            }
            tr.appendChild(tdTo);

            // 6. 배송정보 입력
            const tdDelivery = document.createElement('td');
            tdDelivery.className = 'text-center align-middle';
            const deliveryCheck = document.createElement('input');
            deliveryCheck.type = 'checkbox';
            deliveryCheck.className = 'form-check-input';
            deliveryCheck.checked = state.delivery_editable || false;
            deliveryCheck.addEventListener('change', function() {
                orderStates[idx].delivery_editable = this.checked;
                syncFsmHidden();
            });
            tdDelivery.appendChild(deliveryCheck);
            tr.appendChild(tdDelivery);

            // 7. 종료
            const tdTerminal = document.createElement('td');
            tdTerminal.className = 'text-center align-middle';
            const termCheck = document.createElement('input');
            termCheck.type = 'checkbox';
            termCheck.className = 'form-check-input';
            termCheck.checked = isTerminal;
            termCheck.addEventListener('change', function() {
                orderStates[idx].terminal = this.checked;
                if (this.checked) {
                    orderStates[idx].to = [];
                }
                renderTable();
            });
            tdTerminal.appendChild(termCheck);
            tr.appendChild(tdTerminal);

            // 8. 관리
            const tdCtrl = document.createElement('td');
            tdCtrl.className = 'text-center align-middle';
            const btnGroup = document.createElement('div');
            btnGroup.className = 'btn-group btn-group-sm';

            if (idx > 0) {
                btnGroup.appendChild(createBtn('bi-arrow-up', '위로', () => moveState(idx, -1)));
            }
            if (idx < orderStates.length - 1) {
                btnGroup.appendChild(createBtn('bi-arrow-down', '아래로', () => moveState(idx, 1)));
            }
            if (!isSystem) {
                btnGroup.appendChild(createBtn('bi-trash', '삭제', () => removeState(idx), 'btn-outline-danger'));
            }

            tdCtrl.appendChild(btnGroup);
            tr.appendChild(tdCtrl);

            // 9. 액션
            const tdAction = document.createElement('td');
            tdAction.className = 'text-center align-middle';
            const actionCount = (stateActions[state.id] || []).length;
            const actionBtn = document.createElement('button');
            actionBtn.type = 'button';
            actionBtn.className = actionCount > 0
                ? 'btn btn-sm btn-outline-primary'
                : 'btn btn-sm btn-outline-secondary';
            actionBtn.innerHTML = actionCount > 0
                ? `<i class="bi bi-lightning-fill"></i> ${actionCount}`
                : '<i class="bi bi-lightning"></i>';
            actionBtn.title = '액션 설정';
            actionBtn.addEventListener('click', () => openActionModal(state.id));
            tdAction.appendChild(actionBtn);
            tr.appendChild(tdAction);

            tbody.appendChild(tr);
        });

        syncFsmHidden();
    }

    function createToggleList(stateIdx, state) {
        const div = document.createElement('div');
        div.className = 'to-toggle-list';
        const currentValues = state.to || [];

        orderStates.forEach((otherState) => {
            // 자기 자신: 노란색 마커로 현재 위치 표시
            if (otherState.id === state.id) {
                const marker = document.createElement('span');
                marker.className = 'btn btn-warning btn-sm pe-none';
                const icon = document.createElement('i');
                icon.className = 'bi bi-geo-alt-fill me-1';
                marker.appendChild(icon);
                marker.appendChild(document.createTextNode(otherState.label));
                marker.title = '현재 상태';
                div.appendChild(marker);
                return;
            }

            const checkId = `to_${state.id}_${otherState.id}`;
            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.className = 'btn-check';
            checkbox.id = checkId;
            checkbox.autocomplete = 'off';
            checkbox.checked = currentValues.includes(otherState.id);
            checkbox.addEventListener('change', function() {
                if (this.checked) {
                    if (!orderStates[stateIdx].to.includes(otherState.id)) {
                        orderStates[stateIdx].to.push(otherState.id);
                    }
                } else {
                    orderStates[stateIdx].to = orderStates[stateIdx].to.filter(id => id !== otherState.id);
                }
                syncFsmHidden();
            });

            const label = document.createElement('label');
            label.className = 'btn btn-outline-secondary btn-sm';
            label.htmlFor = checkId;
            label.textContent = otherState.label;

            div.appendChild(checkbox);
            div.appendChild(label);
        });

        return div;
    }

    function createBtn(icon, title, onClick, cls = 'btn-outline-secondary') {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = `btn ${cls} btn-sm px-1`;
        btn.innerHTML = `<i class="bi ${icon}"></i>`;
        btn.title = title;
        btn.addEventListener('click', onClick);
        return btn;
    }

    function moveState(idx, direction) {
        const target = idx + direction;
        if (target < 0 || target >= orderStates.length) return;
        [orderStates[idx], orderStates[target]] = [orderStates[target], orderStates[idx]];
        reindex();
        renderTable();
    }

    function removeState(idx) {
        const state = orderStates[idx];
        if (!confirm(`커스텀 상태 '${state.label}'을(를) 삭제하시겠습니까?\n이 상태를 참조하는 이동 가능(to) 목록도 함께 정리됩니다.`)) return;

        const removedId = state.id;
        orderStates.forEach(s => {
            s.to = (s.to || []).filter(id => id !== removedId);
        });

        orderStates.splice(idx, 1);
        reindex();
        renderTable();
    }

    function addCustomState() {
        const modal = new bootstrap.Modal(document.getElementById('addCustomModal'));
        document.getElementById('customLabel').value = '';
        document.getElementById('customDescription').value = '';
        modal.show();

        document.getElementById('addCustomModal').addEventListener('shown.bs.modal', function() {
            document.getElementById('customLabel').focus();
        }, { once: true });
    }

    function confirmAddCustom() {
        const label = document.getElementById('customLabel').value.trim();
        const description = document.getElementById('customDescription').value.trim();

        if (!label) {
            alert('상태 라벨을 입력하세요.');
            return;
        }

        const slug = toSlug(label);
        const hash = Math.random().toString(36).substring(2, 6);
        const id = slug + '_' + hash;

        if (orderStates.some(s => s.id === id)) {
            alert('중복된 ID가 생성되었습니다. 다시 시도해주세요.');
            return;
        }

        orderStates.push({
            id: id,
            label: label,
            description: description,
            action: 'custom',
            to: [],
            terminal: false,
            delivery_editable: false,
            system: false,
            sort_order: orderStates.length + 1
        });

        bootstrap.Modal.getInstance(document.getElementById('addCustomModal')).hide();
        renderTable();
    }

    function toSlug(str) {
        let slug = str.replace(/[^a-zA-Z0-9]/g, '_').replace(/_+/g, '_').replace(/^_|_$/g, '');
        if (!slug) slug = 'custom';
        return slug.toLowerCase();
    }

    function reindex() {
        orderStates.forEach((state, idx) => {
            state.sort_order = idx + 1;
        });
    }

    function syncFsmHidden() {
        reindex();
        hiddenInput.value = JSON.stringify(orderStates);
    }

    // 이벤트 바인딩
    document.getElementById('btnAddCustomState').addEventListener('click', addCustomState);
    document.getElementById('btnConfirmAddCustom').addEventListener('click', confirmAddCustom);
    document.getElementById('customLabel').addEventListener('keydown', function(e) {
        if (e.key === 'Enter') { e.preventDefault(); confirmAddCustom(); }
    });

    // 초기 렌더링
    renderTable();

    // =============================================
    // 액션 모달
    // =============================================
    const actionsHiddenInput = document.getElementById('state_actions_json');
    const actionModalBody = document.getElementById('actionModalBody');
    const actionModalLabel = document.getElementById('actionModalLabel');
    const actionAddDropdown = document.getElementById('actionAddDropdown');

    function openActionModal(stateId) {
        currentActionStateId = stateId;
        // 원본을 건드리지 않도록 깊은 복사본으로 작업한다.
        modalActions = JSON.parse(JSON.stringify(stateActions[stateId] || []));
        const state = orderStates.find(s => s.id === stateId);
        actionModalLabel.textContent = state ? state.label : stateId;

        renderActionModalContent();
        renderAddDropdown();

        const modal = new bootstrap.Modal(document.getElementById('actionModal'));
        modal.show();
    }

    function renderActionModalContent() {
        actionModalBody.innerHTML = '';
        const stateId = currentActionStateId;
        const actions = modalActions;

        if (actions.length === 0) {
            const empty = document.createElement('p');
            empty.className = 'text-muted text-center my-3';
            empty.textContent = '설정된 액션이 없습니다. 아래에서 액션을 추가하세요.';
            actionModalBody.appendChild(empty);
            return;
        }

        actions.forEach((action, actionIdx) => {
            actionModalBody.appendChild(renderActionItem(stateId, action, actionIdx));
        });
    }

    function renderAddDropdown() {
        actionAddDropdown.innerHTML = '';

        const dropdown = document.createElement('div');
        dropdown.className = 'dropdown d-inline-block';

        const trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = 'btn btn-sm btn-outline-secondary dropdown-toggle';
        trigger.dataset.bsToggle = 'dropdown';
        trigger.innerHTML = '<i class="bi bi-plus-lg"></i> 액션 추가';

        const menu = document.createElement('ul');
        menu.className = 'dropdown-menu';

        for (const [type, label] of Object.entries(actionTypes)) {
            const li = document.createElement('li');
            const a = document.createElement('a');
            a.className = 'dropdown-item';
            a.href = '#';

            const isDuplicateBlocked = !actionAllowDuplicates[type]
                && modalActions.some(act => act.type === type);

            const desc = actionDescriptions[type] || '';

            if (isDuplicateBlocked) {
                a.classList.add('disabled');
                a.innerHTML = '<strong>' + label + '</strong> <span class="text-muted">(이미 등록됨)</span>'
                    + (desc ? '<br><small class="text-muted">' + desc + '</small>' : '');
            } else {
                a.innerHTML = '<strong>' + label + '</strong>'
                    + (desc ? '<br><small class="text-muted">' + desc + '</small>' : '');
                a.addEventListener('click', function(e) {
                    e.preventDefault();
                    addActionToState(currentActionStateId, type);
                });
            }

            li.appendChild(a);
            menu.appendChild(li);
        }

        dropdown.appendChild(trigger);
        dropdown.appendChild(menu);
        actionAddDropdown.appendChild(dropdown);
    }

    function renderActionItem(stateId, action, actionIdx) {
        const isEnabled = action.enabled !== false;
        const type = action.type || '';
        const schema = actionSchemas[type] || {};
        const typeLabel = actionTypes[type] || type;

        const item = document.createElement('div');
        item.className = 'action-item' + (isEnabled ? '' : ' disabled');

        const header = document.createElement('div');
        header.className = 'action-header';

        const leftSide = document.createElement('div');
        leftSide.className = 'd-flex align-items-center';

        const enableCheck = document.createElement('input');
        enableCheck.type = 'checkbox';
        enableCheck.className = 'form-check-input me-2';
        enableCheck.checked = isEnabled;
        enableCheck.title = isEnabled ? '활성' : '비활성';
        enableCheck.addEventListener('change', function() {
            modalActions[actionIdx].enabled = this.checked;
            const parentItem = this.closest('.action-item');
            parentItem.classList.toggle('disabled', !this.checked);
        });

        const typeSpan = document.createElement('strong');
        typeSpan.textContent = typeLabel;

        leftSide.appendChild(enableCheck);
        leftSide.appendChild(typeSpan);

        const deleteBtn = document.createElement('button');
        deleteBtn.type = 'button';
        deleteBtn.className = 'btn btn-sm btn-outline-danger px-1';
        deleteBtn.innerHTML = '<i class="bi bi-trash"></i>';
        deleteBtn.title = '삭제';
        deleteBtn.addEventListener('click', function() {
            removeActionFromState(stateId, actionIdx);
        });

        header.appendChild(leftSide);
        header.appendChild(deleteBtn);
        item.appendChild(header);

        // 설명 텍스트 (헤더 아래 별도 줄)
        const desc = actionDescriptions[type] || '';
        if (desc) {
            const descEl = document.createElement('p');
            descEl.className = 'action-description';
            descEl.textContent = desc;
            item.appendChild(descEl);
        }

        // 필드 영역 (스키마 기반 동적 생성)
        const fields = schema.fields || {};
        if (Object.keys(fields).length > 0) {
            const fieldsRow = document.createElement('div');
            fieldsRow.className = 'action-fields';

            for (const [fieldName, fieldDef] of Object.entries(fields)) {
                const fieldGroup = document.createElement('div');
                fieldGroup.className = 'field-group';

                const label = document.createElement('label');
                label.textContent = fieldDef.label || fieldName;
                fieldGroup.appendChild(label);

                let input;

                if (fieldDef.type === 'select') {
                    input = document.createElement('select');
                    input.className = 'form-select form-select-sm';

                    const emptyOpt = document.createElement('option');
                    emptyOpt.value = '';
                    emptyOpt.textContent = '선택';
                    input.appendChild(emptyOpt);

                    // 채널 종속 옵션(예: 템플릿): 선택된 채널의 항목만 노출, 채널 미선택 시 전체
                    let options = fieldDef.options || {};
                    if (fieldDef.options_by_channel && action.channel) {
                        options = fieldDef.options_by_channel[action.channel] || {};
                    }
                    for (const [val, text] of Object.entries(options)) {
                        const opt = document.createElement('option');
                        opt.value = val;
                        opt.textContent = text;
                        if (action[fieldName] === val) opt.selected = true;
                        input.appendChild(opt);
                    }
                } else if (fieldDef.type === 'number') {
                    input = document.createElement('input');
                    input.type = 'number';
                    input.className = 'form-control form-control-sm';
                    input.value = action[fieldName] || '';
                    if (fieldDef.placeholder) input.placeholder = fieldDef.placeholder;
                } else {
                    input = document.createElement('input');
                    input.type = ['url', 'password'].includes(fieldDef.type) ? fieldDef.type : 'text';
                    input.className = 'form-control form-control-sm';
                    input.value = action[fieldName] || '';
                    if (fieldDef.placeholder) input.placeholder = fieldDef.placeholder;
                }

                const fn = fieldName;
                input.addEventListener('change', function() {
                    modalActions[actionIdx][fn] = this.value;

                    // 채널 변경 시 채널 종속 필드(템플릿) 값 초기화 후 다시 그려 옵션 갱신
                    if (fn === 'channel') {
                        let needsRerender = false;
                        for (const [name, def] of Object.entries(fields)) {
                            if (def.options_by_channel) {
                                modalActions[actionIdx][name] = '';
                                needsRerender = true;
                            }
                        }
                        if (needsRerender) renderActionModalContent();
                    }
                });

                fieldGroup.appendChild(input);
                fieldsRow.appendChild(fieldGroup);
            }

            item.appendChild(fieldsRow);
        }

        return item;
    }

    function addActionToState(stateId, type) {
        // 중복 등록 제한 검사 (작업 사본 기준)
        if (actionAllowDuplicates[type] === false) {
            const existing = modalActions.some(a => a.type === type);
            if (existing) {
                alert(`'${actionTypes[type] || type}' 액션은 하나만 등록할 수 있습니다.`);
                return;
            }
        }

        const newAction = { type: type, enabled: true };

        const schema = actionSchemas[type] || {};
        const fields = schema.fields || {};
        for (const [fieldName, fieldDef] of Object.entries(fields)) {
            if (fieldDef.type === 'select' && fieldDef.options && !fieldDef.options_by_channel) {
                const firstKey = Object.keys(fieldDef.options)[0];
                if (firstKey) newAction[fieldName] = firstKey;
            } else {
                // 채널 종속 필드(options_by_channel)는 기본값 없음 — 채널 선택 후 필터된 목록에서 고른다
                newAction[fieldName] = '';
            }
        }

        modalActions.push(newAction);
        renderActionModalContent();
        renderAddDropdown();
    }

    function removeActionFromState(stateId, actionIdx) {
        modalActions.splice(actionIdx, 1);
        renderActionModalContent();
        renderAddDropdown();
    }

    function syncActionsHidden() {
        actionsHiddenInput.value = JSON.stringify(stateActions);
    }

    // '액션 저장' 클릭 시: 작업 사본을 원본에 커밋하고 hidden input을 갱신한다.
    // 이 리스너는 버블 단계에서 MubloRequest의 document 핸들러(디바운스 제출)보다
    // 먼저 실행되므로, 제출 시점엔 hidden input이 최신 상태다.
    document.getElementById('stateActionsSaveBtn').addEventListener('click', function() {
        if (currentActionStateId === null) return;
        if (modalActions.length > 0) {
            stateActions[currentActionStateId] = JSON.parse(JSON.stringify(modalActions));
        } else {
            delete stateActions[currentActionStateId];
        }
        syncActionsHidden();
    });

    document.getElementById('actionModal').addEventListener('hidden.bs.modal', function() {
        renderTable();
    });

    syncActionsHidden();

    // 콜백
    MubloRequest.registerCallback('orderStateSaved', function(response) {
        if (response.result === 'success') {
            if (response.data && response.data.warnings && response.data.warnings.length > 0) {
                alert('저장되었습니다.\n\n경고:\n' + response.data.warnings.join('\n'));
            } else {
                alert(response.message || '저장되었습니다.');
            }
            location.reload();
        } else {
            alert(response.message || '저장에 실패했습니다.');
        }
    });

    MubloRequest.registerCallback('stateActionsSaved', function(response) {
        if (response.result === 'success') {
            alert(response.message || '액션 설정이 저장되었습니다.');
            location.reload();
        } else {
            let msg = response.message || '저장에 실패했습니다.';
            if (response.data && response.data.errors && response.data.errors.length > 0) {
                msg += '\n\n' + response.data.errors.join('\n');
            }
            alert(msg);
        }
    });
})();
</script>
