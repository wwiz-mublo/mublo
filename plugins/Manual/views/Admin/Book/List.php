<?php
/**
 * 매뉴얼 책 목록 (관리자)
 *
 * @var string $pageTitle
 * @var array  $books      [{book_id, title, slug, description, is_active, sort_order, page_count}, ...]
 * @var string|null $flashError
 * @var array $bundledManuals [{title, icon, route, installed}, ...]
 * @var array  $skins       사용 가능한 프론트 스킨 목록
 * @var string $currentSkin 현재 적용된 프론트 스킨
 */
$books = $books ?? [];
$flashError = $flashError ?? null;
$bundledManuals = $bundledManuals ?? [];
$skins = $skins ?? ['basic'];
$currentSkin = $currentSkin ?? 'basic';
?>

<div class="page-container">
    <div class="page-title">
        <div class="page-title-text">
            <h3><?= htmlspecialchars($pageTitle) ?></h3>
            <p>사용설명서(매뉴얼) 책과 페이지를 관리합니다.</p>
        </div>
        <div class="page-title-actions">
            <?php if (!empty($bundledManuals)): ?>
                <div class="dropdown">
                    <button type="button" class="btn btn-outline-primary btn-sm dropdown-toggle gap-2"
                            data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-journal-plus"></i> 번들 매뉴얼 추가
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <?php foreach ($bundledManuals as $manual): ?>
                            <li>
                                <button type="button"
                                        class="dropdown-item d-flex align-items-center gap-2 js-import-bundled-manual"
                                        data-title="<?= htmlspecialchars($manual['title'], ENT_QUOTES) ?>"
                                        data-route="<?= htmlspecialchars($manual['route'], ENT_QUOTES) ?>"
                                        <?= !empty($manual['installed']) ? 'disabled' : '' ?>>
                                    <i class="bi <?= htmlspecialchars($manual['icon']) ?>"></i>
                                    <span><?= htmlspecialchars($manual['title']) ?></span>
                                    <?php if (!empty($manual['installed'])): ?>
                                        <span class="badge bg-success-subtle text-success-emphasis ms-auto">추가됨</span>
                                    <?php endif; ?>
                                </button>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            <a href="/manual" target="_blank" rel="noopener noreferrer" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-box-arrow-up-right"></i> 매뉴얼 보기
            </a>
            <a href="/admin/manual/book/create" class="btn btn-primary btn-sm">
                <i class="bi bi-plus-lg"></i> 매뉴얼 추가
            </a>
        </div>
    </div>

    <?php if ($flashError): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($flashError) ?></div>
    <?php endif; ?>

    <!-- 스킨 설정 -->
    <div class="page-block">
        <div class="card">
            <div class="card-body py-2">
                <form id="skinConfigForm" class="row align-items-center g-2">
                    <div class="col-auto">
                        <label class="form-label mb-0 fw-bold" for="skinSelect"><i class="bi bi-palette"></i> 프론트 스킨</label>
                    </div>
                    <div class="col-auto">
                        <select id="skinSelect" class="form-select form-select-sm" style="width:auto">
                            <?php foreach ($skins as $skin): ?>
                            <option value="<?= htmlspecialchars($skin) ?>"<?= $skin === $currentSkin ? ' selected' : '' ?>>
                                <?= htmlspecialchars($skin) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-auto">
                        <button type="button" class="btn btn-primary btn-sm" onclick="saveManualSkin()">저장</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="page-block">
        <div class="card">
            <div class="card-hero">
                <i class="bi bi-book text-pastel-blue"></i>
                <span>매뉴얼 목록</span>
            </div>
            <div class="card-body">
                <?php if (empty($books)): ?>
                    <div class="text-center text-muted py-5">
                        등록된 매뉴얼이 없습니다. 우측 상단 <strong>매뉴얼 추가</strong>로 시작하세요.
                    </div>
                <?php else: ?>
                    <div class="alert alert-info d-flex align-items-center gap-3">
                        <i class="bi bi-eye fs-5"></i>
                        <div>
                            <strong>프론트 노출 설정</strong>
                            <div class="small mb-0">스위치를 끄면 매뉴얼 목록과 직접 주소에서 즉시 숨겨집니다. 관리자에서는 계속 편집할 수 있습니다.</div>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th style="width:60px" class="text-center">번호</th>
                                    <th>제목</th>
                                    <th style="width:140px">슬러그</th>
                                    <th style="width:90px" class="text-center">페이지</th>
                                    <th style="width:150px" class="text-center">프론트 노출</th>
                                    <th style="width:70px" class="text-center">정렬</th>
                                    <th style="width:230px" class="text-center">관리</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($books as $idx => $book): ?>
                                    <?php $isActive = (int) $book['is_active'] === 1; ?>
                                    <tr class="js-manual-row <?= $isActive ? '' : 'opacity-75' ?>">
                                        <td class="text-center text-nowrap text-muted"><?= $idx + 1 ?></td>
                                        <td>
                                            <div class="fw-semibold"><?= htmlspecialchars($book['title']) ?></div>
                                            <?php if (!empty($book['description'])): ?>
                                                <div class="text-muted small mt-1"><?= htmlspecialchars(mb_substr($book['description'], 0, 60)) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td><code><?= htmlspecialchars($book['slug']) ?></code></td>
                                        <td class="text-center text-nowrap"><?= (int) ($book['page_count'] ?? 0) ?></td>
                                        <td class="text-center text-nowrap">
                                            <div class="form-check form-switch d-inline-block mb-0 text-start">
                                                <input type="checkbox"
                                                       class="form-check-input js-manual-visibility"
                                                       id="manualVisibility<?= (int) $book['book_id'] ?>"
                                                       data-book-id="<?= (int) $book['book_id'] ?>"
                                                       <?= $isActive ? 'checked' : '' ?>
                                                       aria-label="<?= htmlspecialchars($book['title']) ?> 프론트 노출">
                                                <label class="form-check-label fw-bold js-manual-visibility-label <?= $isActive ? 'text-success' : 'text-body-secondary' ?>"
                                                       for="manualVisibility<?= (int) $book['book_id'] ?>">
                                                    <?= $isActive ? '노출' : '숨김' ?>
                                                </label>
                                            </div>
                                        </td>
                                        <td class="text-center text-muted"><?= (int) ($book['sort_order'] ?? 0) ?></td>
                                        <td class="text-center text-nowrap">
                                            <a href="/admin/manual/pages/<?= (int) $book['book_id'] ?>" class="btn btn-outline-primary btn-sm me-1" title="페이지 관리">
                                                <i class="bi bi-list-nested"></i> 페이지
                                            </a>
                                            <a href="/admin/manual/book/edit/<?= (int) $book['book_id'] ?>" class="btn btn-outline-secondary btn-sm me-1" title="수정">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                            <button type="button" class="btn btn-outline-danger btn-sm"
                                                    onclick="deleteBook(<?= (int) $book['book_id'] ?>, '<?= htmlspecialchars(addslashes($book['title'])) ?>')" title="삭제">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
document.querySelectorAll('.js-import-bundled-manual').forEach(function (button) {
    button.addEventListener('click', function () {
        var title = button.dataset.title || '번들 매뉴얼';
        var route = button.dataset.route || '';
        if (!route || !confirm(title + '을(를) 추가하시겠습니까?\n추가 후 일반 매뉴얼처럼 편집할 수 있습니다.')) {
            return;
        }

        button.disabled = true;
        MubloRequest.requestJson(route, {}, { loading: true })
            .then(function () { location.reload(); })
            .catch(function (error) {
                button.disabled = false;
                alert((error && error.message) || title + '을(를) 추가하지 못했습니다.');
            });
    });
});

document.querySelectorAll('.js-manual-visibility').forEach(function (toggle) {
    toggle.addEventListener('change', function () {
        var active = toggle.checked;
        var row = toggle.closest('.js-manual-row');
        var label = row ? row.querySelector('.js-manual-visibility-label') : null;

        toggle.disabled = true;
        MubloRequest.requestJson('/admin/manual/book/toggle-active', {
            book_id: parseInt(toggle.dataset.bookId || '0', 10),
            is_active: active ? 1 : 0
        }, { loading: false })
            .then(function () {
                if (label) {
                    label.textContent = active ? '노출' : '숨김';
                    label.classList.toggle('text-success', active);
                    label.classList.toggle('text-body-secondary', !active);
                }
                if (row) row.classList.toggle('opacity-75', !active);
            })
            .catch(function (error) {
                toggle.checked = !active;
                alert((error && error.message) || '프론트 노출 상태를 변경하지 못했습니다.');
            })
            .finally(function () {
                toggle.disabled = false;
            });
    });
});

// 프론트 스킨 저장 — PUT /admin/manual/config
function saveManualSkin() {
    var skin = document.getElementById('skinSelect').value;
    MubloRequest.requestJson('/admin/manual/config', { skin_name: skin }, { method: 'PUT', loading: true })
        .then(function () {})
        .catch(function (err) { MubloRequest.showAlert((err && err.message) || '저장에 실패했습니다.', 'error'); });
}

function deleteBook(bookId, title) {
    if (!confirm('"' + title + '" 매뉴얼을 삭제하시겠습니까?\n하위 페이지도 모두 삭제됩니다.')) {
        return;
    }
    MubloRequest.requestJson('/admin/manual/book', { book_id: bookId }, { method: 'DELETE', loading: true })
        .then(function () { location.reload(); });
}
</script>
