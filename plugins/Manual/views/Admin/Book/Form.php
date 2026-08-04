<?php
/**
 * 매뉴얼 책 생성/수정 폼 (관리자)
 *
 * @var string $pageTitle
 * @var array|null $book  수정 시 책 데이터, 신규는 null
 */
$book = $book ?? null;
$isEdit = $book !== null;
?>

<div class="page-container">
    <div class="page-title">
        <div class="page-title-text">
            <h3><?= htmlspecialchars($pageTitle) ?></h3>
            <p>매뉴얼 책의 기본 정보를 입력합니다.</p>
        </div>
        <div class="page-title-actions">
            <a href="/admin/manual" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left"></i> 목록
            </a>
        </div>
    </div>

    <div class="page-block">
        <div class="card">
            <div class="card-body">
                <form id="bookForm" onsubmit="return false;">
                    <input type="hidden" id="bookId" value="<?= $isEdit ? (int) $book['book_id'] : '' ?>">

                    <div class="mb-3">
                        <label class="form-label">제목 <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="title"
                               value="<?= $isEdit ? htmlspecialchars($book['title']) : '' ?>"
                               placeholder="예: 사용자 가이드" maxlength="100">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">슬러그 (URL)</label>
                        <div class="input-group">
                            <span class="input-group-text">/manual/</span>
                            <input type="text" class="form-control" id="slug"
                                   value="<?= $isEdit ? htmlspecialchars($book['slug']) : '' ?>"
                                   placeholder="비우면 자동 생성 (영문 소문자·숫자·하이픈)" maxlength="100">
                        </div>
                        <div class="form-text">비워두면 자동으로 만들어집니다. 한글은 사용할 수 없습니다.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">설명</label>
                        <textarea class="form-control" id="description" rows="3"
                                  placeholder="매뉴얼에 대한 간단한 설명 (선택)"><?= $isEdit ? htmlspecialchars($book['description'] ?? '') : '' ?></textarea>
                    </div>

                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label class="form-label">정렬 순서</label>
                            <input type="number" class="form-control" id="sortOrder"
                                   value="<?= $isEdit ? (int) $book['sort_order'] : 0 ?>">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">상태</label>
                            <div class="form-check form-switch mt-2">
                                <input type="checkbox" class="form-check-input" id="isActive"
                                    <?= (!$isEdit || (int) $book['is_active'] === 1) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="isActive">활성</label>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-3">
                        <button type="button" class="btn btn-primary" onclick="saveBook()">
                            <i class="bi bi-check-lg"></i> 저장
                        </button>
                        <a href="/admin/manual" class="btn btn-outline-secondary">취소</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var isEdit = <?= $isEdit ? 'true' : 'false' ?>;

    window.saveBook = function () {
        var data = {
            title: document.getElementById('title').value,
            slug: document.getElementById('slug').value,
            description: document.getElementById('description').value,
            sort_order: document.getElementById('sortOrder').value,
            is_active: document.getElementById('isActive').checked ? 1 : 0,
        };

        if (!data.title.trim()) {
            alert('제목을 입력해 주세요.');
            return;
        }

        if (isEdit) {
            data.book_id = parseInt(document.getElementById('bookId').value, 10);
            MubloRequest.requestJson('/admin/manual/book', data, { method: 'PUT', loading: true })
                .then(function () { location.href = '/admin/manual'; });
        } else {
            MubloRequest.requestJson('/admin/manual/book', data, { loading: true })
                .then(function () { location.href = '/admin/manual'; });
        }
    };
})();
</script>
