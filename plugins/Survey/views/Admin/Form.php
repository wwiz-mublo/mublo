<?php
/**
 * @var string      $pageTitle
 * @var array|null  $survey
 * @var array       $questions
 * @var array       $statusOptions
 * @var array       $typeOptions
 */
$surveyId    = $survey['survey_id']    ?? 0;
$isEdit      = $surveyId > 0;
$saveUrl     = $isEdit
    ? '/admin/survey/surveys/' . $surveyId . '/update'
    : '/admin/survey/surveys/store';

// 신규 생성 시 $survey가 null이므로 각 필드를 안전하게 꺼냄
$surveyTitle        = $survey['title']           ?? '';
$surveyDescription  = $survey['description']     ?? '';
$surveyStatus       = $survey['status']          ?? 'draft';
$surveyStartAt      = $survey['start_at']        ?? '';
$surveyEndAt        = $survey['end_at']          ?? '';
$surveyResponseLimit = (int) ($survey['response_limit'] ?? 0);
$surveyAllowAnon    = (bool) ($survey['allow_anonymous'] ?? true);
$surveyAllowDup     = (bool) ($survey['allow_duplicate'] ?? false);

$startAtInput = $surveyStartAt ? str_replace(' ', 'T', substr($surveyStartAt, 0, 16)) : '';
$endAtInput   = $surveyEndAt   ? str_replace(' ', 'T', substr($surveyEndAt,   0, 16)) : '';
?>
<div class="page-container">
    <div class="page-title">
        <div class="page-title-text">
            <h3><?= htmlspecialchars($pageTitle) ?></h3>
            <p><?= $isEdit ? '설문 정보와 질문을 편집합니다.' : '새 설문을 등록합니다.' ?></p>
        </div>
        <div class="page-title-actions">
            <a href="<?= htmlspecialchars($listUrl ?? '/admin/survey/surveys') ?>"
               class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-list"></i> 목록
            </a>
            <?php if ($isEdit): ?>
            <a href="/admin/survey/surveys/<?= $surveyId ?>/result"
               class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-bar-chart-line"></i> 결과 보기
            </a>
            <?php endif; ?>
            <button type="button" class="btn btn-primary btn-sm" id="btn-save">
                <i class="bi bi-floppy"></i> 저장
            </button>
        </div>
    </div>

    <div class="page-block row">
        <!-- 좌측: 설문 기본 정보 -->
        <div class="col-lg-4">
            <div class="card">
                <div class="card-hero">
                    <i class="bi bi-info-circle text-pastel-blue"></i>
                    <span>기본 정보</span>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">설문 제목 <span class="text-danger">*</span></label>
                        <input type="text" id="survey-title" class="form-control"
                               value="<?= htmlspecialchars($surveyTitle) ?>"
                               placeholder="설문 제목을 입력하세요">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">설명</label>
                        <textarea id="survey-description" class="form-control" rows="3"
                                  placeholder="설문 안내 문구 (선택)"><?= htmlspecialchars($surveyDescription) ?></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">상태</label>
                        <select id="survey-status" class="form-select">
                            <?php foreach ($statusOptions as $opt): ?>
                            <option value="<?= $opt['value'] ?>"
                                <?= $surveyStatus === $opt['value'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($opt['label']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <div class="card mt-3">
                <div class="card-hero">
                    <i class="bi bi-gear text-pastel-green"></i>
                    <span>참여 설정</span>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">시작일</label>
                        <input type="datetime-local" id="survey-start-at" class="form-control"
                               value="<?= htmlspecialchars($startAtInput) ?>">
                        <div class="form-text">비워두면 즉시 시작</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">종료일</label>
                        <input type="datetime-local" id="survey-end-at" class="form-control"
                               value="<?= htmlspecialchars($endAtInput) ?>">
                        <div class="form-text">비워두면 무기한</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">최대 응답 수</label>
                        <input type="number" id="survey-response-limit" class="form-control"
                               value="<?= $surveyResponseLimit ?>" min="0">
                        <div class="form-text">0 = 무제한</div>
                    </div>
                    <div class="form-check mb-2">
                        <input type="checkbox" class="form-check-input" id="survey-allow-anonymous"
                               <?= $surveyAllowAnon ? 'checked' : '' ?>>
                        <label class="form-check-label" for="survey-allow-anonymous">비회원 참여 허용</label>
                    </div>
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="survey-allow-duplicate"
                               <?= $surveyAllowDup ? 'checked' : '' ?>>
                        <label class="form-check-label" for="survey-allow-duplicate">중복 참여 허용</label>
                    </div>
                </div>
            </div>
        </div>

        <!-- 우측: 질문 편집기 -->
        <div class="col-lg-8">
            <div class="card">
                <div class="card-hero">
                    <i class="bi bi-list-ol text-pastel-purple"></i>
                    <span>질문 목록</span>
                    <button type="button" class="btn btn-xs btn-outline-primary ms-auto" id="btn-add-question">
                        <i class="bi bi-plus-lg"></i> 질문 추가
                    </button>
                </div>
                <div class="card-body">
                    <div id="question-list" class="list-group"></div>
                </div>
                <div class="card-footer text-muted small" id="question-empty-hint">
                    질문을 추가하려면 위 버튼을 클릭하세요.
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 질문 추가/편집 모달 -->
<div class="modal fade" id="modal-add-question" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modal-question-title">질문 추가</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">질문 유형 <span class="text-danger">*</span></label>
                    <select id="q-type" class="form-select">
                        <?php foreach ($typeOptions as $opt): ?>
                        <option value="<?= $opt['value'] ?>"><?= htmlspecialchars($opt['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">질문 내용 <span class="text-danger">*</span></label>
                    <input type="text" id="q-title" class="form-control" placeholder="질문을 입력하세요">
                </div>
                <div class="mb-3">
                    <label class="form-label">보조 설명 <span class="text-muted">(선택)</span></label>
                    <input type="text" id="q-description" class="form-control" placeholder="추가 안내 문구">
                </div>
                <div id="q-options-wrap" class="mb-3" style="display:none">
                    <label class="form-label">선택지</label>
                    <div id="q-options-list"></div>
                    <button type="button" class="btn btn-sm btn-outline-secondary mt-2" id="btn-add-option">
                        <i class="bi bi-plus"></i> 선택지 추가
                    </button>
                </div>
                <div class="form-check">
                    <input type="checkbox" class="form-check-input" id="q-required" checked>
                    <label class="form-check-label" for="q-required">필수 응답</label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">취소</button>
                <button type="button" class="btn btn-primary" id="btn-confirm-question">확인</button>
            </div>
        </div>
    </div>
</div>

<style>
/* 질문 목록 = 부트스트랩 list-group (배경/보더/패딩·다크 네이티브) */
#question-list { min-height: 60px; }
.list-group-item.sortable-ghost { opacity: .4; background: var(--bs-secondary-bg); }
.list-group-item .drag-handle {
    cursor: grab;
    color: var(--bs-tertiary-color);
    padding-right: 8px;
    flex-shrink: 0;
}
.list-group-item .drag-handle:active { cursor: grabbing; }
.option-item { display: flex; align-items: center; gap: 8px; margin-bottom: 4px; }
.option-item .drag-handle { padding-right: 0; }
</style>

<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.3/Sortable.min.js"></script>
<script>
(function () {
    const HAS_OPTIONS_TYPES = ['radio', 'checkbox', 'select'];
    const TYPE_LABELS = <?= json_encode(array_column($typeOptions, 'label', 'value'), JSON_UNESCAPED_UNICODE) ?>;

    let questions    = <?= json_encode($questions, JSON_UNESCAPED_UNICODE) ?>;
    let editingIndex = -1;
    let sortableInst = null;   // 질문 목록 Sortable 인스턴스 (재생성 방지)
    let optSortable  = null;   // 선택지 Sortable 인스턴스

    const listEl    = document.getElementById('question-list');
    const emptyHint = document.getElementById('question-empty-hint');
    const modal     = new bootstrap.Modal(document.getElementById('modal-add-question'));

    /* ===== 질문 목록 렌더링 ===== */
    function render() {
        // 기존 Sortable 인스턴스 제거
        if (sortableInst) {
            sortableInst.destroy();
            sortableInst = null;
        }

        listEl.innerHTML = '';
        emptyHint.style.display = questions.length === 0 ? '' : 'none';

        questions.forEach(function (q, idx) {
            const div = document.createElement('div');
            div.className = 'list-group-item d-flex align-items-start gap-2';
            div.dataset.index = idx;

            const opts = (q.options || []).map(function (o) {
                return '<span class="badge bg-body-secondary text-body border">' + escHtml(o) + '</span>';
            }).join(' ');

            div.innerHTML = [
                '<span class="drag-handle"><i class="bi bi-grip-vertical"></i></span>',
                '<div class="flex-grow-1">',
                '  <div class="fw-medium">' + escHtml(q.title) + '</div>',
                '  <div class="text-muted small mt-1">',
                '    <span class="badge bg-secondary me-1">' + (TYPE_LABELS[q.type] || q.type) + '</span>',
                q.required ? '<span class="badge bg-danger-subtle text-danger me-1">필수</span>' : '',
                opts,
                '  </div>',
                '</div>',
                '<div class="d-flex gap-1 flex-shrink-0">',
                '  <button class="btn btn-sm btn-outline-secondary" data-action="edit" data-idx="' + idx + '">',
                '    <i class="bi bi-pencil"></i>',
                '  </button>',
                '  <button class="btn btn-sm btn-outline-danger" data-action="delete" data-idx="' + idx + '">',
                '    <i class="bi bi-trash"></i>',
                '  </button>',
                '</div>',
            ].join('');

            listEl.appendChild(div);
        });

        // Sortable 단 1회 생성
        sortableInst = Sortable.create(listEl, {
            handle: '.drag-handle',
            animation: 150,
            ghostClass: 'sortable-ghost',
            onEnd: function (evt) {
                if (evt.oldIndex === evt.newIndex) return;
                const moved = questions.splice(evt.oldIndex, 1)[0];
                questions.splice(evt.newIndex, 0, moved);
                render();
            }
        });
    }

    /* ===== 질문 추가 버튼 ===== */
    document.getElementById('btn-add-question').addEventListener('click', function () {
        editingIndex = -1;
        document.getElementById('modal-question-title').textContent = '질문 추가';
        resetModal();
        modal.show();
    });

    /* ===== 편집/삭제 (이벤트 위임) ===== */
    listEl.addEventListener('click', function (e) {
        const btn = e.target.closest('[data-action]');
        if (!btn) return;
        const idx = parseInt(btn.dataset.idx);

        if (btn.dataset.action === 'edit') {
            editingIndex = idx;
            document.getElementById('modal-question-title').textContent = '질문 편집';
            fillModal(questions[idx]);
            modal.show();
        } else if (btn.dataset.action === 'delete') {
            if (!confirm('이 질문을 삭제하시겠습니까?')) return;
            questions.splice(idx, 1);
            render();
        }
    });

    /* ===== 유형 변경 시 선택지 영역 토글 ===== */
    document.getElementById('q-type').addEventListener('change', function () {
        toggleOptionsWrap(this.value);
    });

    function toggleOptionsWrap(type) {
        document.getElementById('q-options-wrap').style.display =
            HAS_OPTIONS_TYPES.includes(type) ? '' : 'none';
    }

    /* ===== 선택지 추가 ===== */
    document.getElementById('btn-add-option').addEventListener('click', function () {
        addOptionInput('');
    });

    function addOptionInput(value) {
        const wrap = document.getElementById('q-options-list');
        const div  = document.createElement('div');
        div.className = 'option-item';
        div.innerHTML = [
            '<span class="drag-handle text-muted"><i class="bi bi-grip-vertical"></i></span>',
            '<input type="text" class="form-control form-control-sm option-value"',
            '  value="' + escHtml(value) + '" placeholder="선택지 내용">',
            '<button type="button" class="btn btn-sm btn-outline-danger option-remove">',
            '  <i class="bi bi-x-lg"></i>',
            '</button>',
        ].join('');
        div.querySelector('.option-remove').addEventListener('click', function () {
            div.remove();
        });
        wrap.appendChild(div);

        // 선택지 Sortable: 기존 인스턴스 제거 후 재생성
        if (optSortable) {
            optSortable.destroy();
        }
        optSortable = Sortable.create(wrap, { handle: '.drag-handle', animation: 100 });
    }

    /* ===== 모달 확인 ===== */
    document.getElementById('btn-confirm-question').addEventListener('click', function () {
        const type  = document.getElementById('q-type').value;
        const title = document.getElementById('q-title').value.trim();
        if (!title) { alert('질문 내용을 입력하세요.'); return; }

        const opts = [];
        if (HAS_OPTIONS_TYPES.includes(type)) {
            document.querySelectorAll('#q-options-list .option-value').forEach(function (inp) {
                const v = inp.value.trim();
                if (v) opts.push(v);
            });
            if (opts.length === 0) { alert('선택지를 1개 이상 입력하세요.'); return; }
        }

        const q = {
            type:        type,
            title:       title,
            description: document.getElementById('q-description').value.trim(),
            options:     opts,
            required:    document.getElementById('q-required').checked,
        };

        if (editingIndex >= 0) {
            questions[editingIndex] = q;
        } else {
            questions.push(q);
        }

        modal.hide();
        render();
    });

    /* ===== 저장 ===== */
    document.getElementById('btn-save').addEventListener('click', function () {
        const title = document.getElementById('survey-title').value.trim();
        if (!title) { alert('설문 제목을 입력하세요.'); return; }

        const payload = {
            title:           title,
            description:     document.getElementById('survey-description').value.trim(),
            status:          document.getElementById('survey-status').value,
            allow_anonymous: document.getElementById('survey-allow-anonymous').checked ? 1 : 0,
            allow_duplicate: document.getElementById('survey-allow-duplicate').checked ? 1 : 0,
            response_limit:  parseInt(document.getElementById('survey-response-limit').value) || 0,
            start_at:        document.getElementById('survey-start-at').value || null,
            end_at:          document.getElementById('survey-end-at').value || null,
            questions:       questions,
        };

        MubloRequest.requestJson('<?= $saveUrl ?>', payload, { loading: true })
            .then(function (res) {
                var newId = res.data?.survey_id ?? null;
                if (newId && !<?= $isEdit ? 'true' : 'false' ?>) {
                    location.href = '/admin/survey/surveys/' + newId + '/edit';
                } else {
                    location.reload();
                }
            });
    });

    /* ===== 모달 초기화 ===== */
    function resetModal() {
        document.getElementById('q-type').value = 'radio';
        document.getElementById('q-title').value = '';
        document.getElementById('q-description').value = '';
        document.getElementById('q-required').checked = true;
        document.getElementById('q-options-list').innerHTML = '';
        if (optSortable) { optSortable.destroy(); optSortable = null; }
        toggleOptionsWrap('radio');
    }

    function fillModal(q) {
        document.getElementById('q-type').value = q.type;
        document.getElementById('q-title').value = q.title;
        document.getElementById('q-description').value = q.description || '';
        document.getElementById('q-required').checked = !!q.required;
        document.getElementById('q-options-list').innerHTML = '';
        if (optSortable) { optSortable.destroy(); optSortable = null; }
        (q.options || []).forEach(addOptionInput);
        toggleOptionsWrap(q.type);
    }

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    render();
})();
</script>
