<?php
/**
 * 매뉴얼 페이지 생성/수정 폼 (관리자, MubloEditor)
 *
 * @var string $pageTitle
 * @var array  $book            소속 책
 * @var array|null $page         수정 시 페이지 데이터, 신규는 null
 * @var array  $parentOptions    부모 선택 목록 [{page_id, label}, ...]
 * @var int|null $selectedParent 선택된 부모 id
 */
$book = $book ?? [];
$page = $page ?? null;
$parentOptions = $parentOptions ?? [];
$selectedParent = $selectedParent ?? null;
$isEdit = $page !== null;
$bookId = (int) ($book['book_id'] ?? 0);
?>

<?= editor_css() ?>

<div class="page-container">
    <div class="page-title">
        <div class="page-title-text">
            <h3><?= htmlspecialchars($pageTitle) ?></h3>
            <p><?= htmlspecialchars($book['title'] ?? '') ?> 매뉴얼의 페이지를 편집합니다.</p>
        </div>
        <div class="page-title-actions">
            <a href="/admin/manual/pages/<?= $bookId ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left"></i> 페이지 목록
            </a>
        </div>
    </div>

    <div class="page-block">
        <div class="card">
            <div class="card-body">
                <form id="pageForm" onsubmit="return false;">
                    <input type="hidden" id="pageId" value="<?= $isEdit ? (int) $page['page_id'] : '' ?>">
                    <input type="hidden" id="bookId" value="<?= $bookId ?>">

                    <div class="row">
                        <div class="col-md-8 mb-3">
                            <label class="form-label">제목 <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="title"
                                   value="<?= $isEdit ? htmlspecialchars($page['title']) : '' ?>"
                                   placeholder="예: 시작하기" maxlength="200">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">슬러그 (URL)</label>
                            <input type="text" class="form-control" id="slug"
                                   value="<?= $isEdit ? htmlspecialchars($page['slug']) : '' ?>"
                                   placeholder="비우면 자동 생성" maxlength="100">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">상위 페이지</label>
                            <select class="form-select" id="parentId">
                                <option value="">— 최상위 (루트) —</option>
                                <?php foreach ($parentOptions as $opt): ?>
                                    <option value="<?= (int) $opt['page_id'] ?>" <?= ($selectedParent === (int) $opt['page_id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($opt['label']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">상위 페이지를 지정하면 목차에서 하위 항목으로 들어갑니다.</div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">정렬 순서</label>
                            <input type="number" class="form-control" id="sortOrder"
                                   value="<?= $isEdit ? (int) $page['sort_order'] : 0 ?>">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">상태</label>
                            <div class="form-check form-switch mt-2">
                                <input type="checkbox" class="form-check-input" id="isActive"
                                    <?= (!$isEdit || (int) $page['is_active'] === 1) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="isActive">활성</label>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">본문</label>
                        <?= editor_html('content', $isEdit ? ($page['content'] ?? '') : '', [
                            'name'        => 'content',
                            'height'      => 480,
                            'toolbar'     => 'full',
                            'placeholder' => '매뉴얼 본문을 입력하세요 (HTML 사용 가능)',
                        ]) ?>
                    </div>

                    <div class="d-flex gap-2 mt-3">
                        <button type="button" class="btn btn-primary" onclick="savePage()">
                            <i class="bi bi-check-lg"></i> 저장
                        </button>
                        <a href="/admin/manual/pages/<?= $bookId ?>" class="btn btn-outline-secondary">취소</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?= editor_js() ?>

<script>
(function () {
    var isEdit = <?= $isEdit ? 'true' : 'false' ?>;
    var editorInstance = null;

    document.addEventListener('DOMContentLoaded', function () {
        if (typeof MubloEditor !== 'undefined') {
            var el = document.getElementById('content');
            if (el && !el.dataset.mubloEditorInitialized) {
                MubloEditor.create(el);
                el.dataset.mubloEditorInitialized = 'true';
            }
            editorInstance = MubloEditor.get('content');
        }
    });

    function getContent() {
        if (editorInstance && editorInstance.sync) {
            editorInstance.sync();
        }
        return document.getElementById('content').value;
    }

    window.savePage = function () {
        var data = {
            book_id: parseInt(document.getElementById('bookId').value, 10),
            title: document.getElementById('title').value,
            slug: document.getElementById('slug').value,
            parent_id: document.getElementById('parentId').value || null,
            content: getContent(),
            sort_order: document.getElementById('sortOrder').value,
            is_active: document.getElementById('isActive').checked ? 1 : 0,
        };

        if (!data.title.trim()) {
            alert('제목을 입력해 주세요.');
            return;
        }

        var backUrl = '/admin/manual/pages/' + data.book_id;

        if (isEdit) {
            data.page_id = parseInt(document.getElementById('pageId').value, 10);
            MubloRequest.requestJson('/admin/manual/page', data, { method: 'PUT', loading: true })
                .then(function () { location.href = backUrl; });
        } else {
            MubloRequest.requestJson('/admin/manual/page', data, { loading: true })
                .then(function () { location.href = backUrl; });
        }
    };
})();
</script>
