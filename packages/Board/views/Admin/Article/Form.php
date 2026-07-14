<?php
/**
 * Admin Boardarticle - Form
 *
 * 게시글 작성/수정 폼
 *
 * @var string $pageTitle 페이지 제목
 * @var bool $isEdit 수정 모드 여부
 * @var array|null $article 게시글 데이터
 * @var array $boards 게시판 옵션
 * @var int $selectedBoardId 선택된 게시판 ID
 * @var array|null $selectedBoard 선택된 게시판 정보
 * @var array $categories 카테고리 옵션
 * @var array $attachments 첨부파일 목록 (수정 시)
 * @var array $statusOptions 상태 옵션
 */

$article = $article ?? [];
$isEdit = $isEdit ?? false;
$selectedBoardId = $selectedBoardId ?? 0;
$selectedBoard = $selectedBoard ?? null;
$attachments = $attachments ?? [];
$links = $links ?? [];
$useFile = $selectedBoard ? $selectedBoard->isUseFile() : false;
$useLink = $selectedBoard ? $selectedBoard->isUseLink() : false;
$fileCountLimit = $selectedBoard ? $selectedBoard->getFileCountLimit() : 0;
$fileExtAllowed = $selectedBoard ? $selectedBoard->getFileExtensionAllowed() : '';
?>

<?= editor_css() ?>

<form name="frm" id="frm">
<div class="page-container form-container">
    <div class="page-title">
        <div class="page-title-text">
            <h3><?= htmlspecialchars($pageTitle ?? '게시글 작성') ?></h3>
            <p>
                <?php if ($isEdit): ?>
                게시글을 수정합니다.
                <?php else: ?>
                새로운 게시글을 작성합니다.
                <?php endif; ?>
            </p>
        </div>
        <div class="page-title-actions">
            <a href="/admin/board/article" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-list"></i> 목록
            </a>
            <button type="button"
                class="btn btn-sm btn-outline-primary mublo-submit"
                data-target="/admin/board/article/store"
                data-callback="articleSaved"
                onclick="setStatus('draft')">
                <i class="bi bi-file-earmark"></i> 임시저장
            </button>
            <button type="button"
                class="btn btn-sm btn-primary mublo-submit"
                data-target="/admin/board/article/store"
                data-callback="articleSaved"
                onclick="setStatus('published')">
                <i class="bi bi-check-lg"></i> 발행
            </button>
        </div>
    </div>

    <!-- 숨김 필드 -->
    <?php if ($isEdit): ?>
    <input type="hidden" name="formData[article_id]" value="<?= $article['article_id'] ?? '' ?>">
    <?php endif; ?>
    <input type="hidden" name="formData[status]" id="article_status" value="published">

    <div class="page-block row">
        <!-- 메인 콘텐츠 영역 -->
        <div class="col-lg-9">
            <div class="card mb-4">
                <div class="card-body">
                    <!-- 제목 -->
                    <div class="mb-3">
                        <label class="form-label">제목 <span class="text-danger">*</span></label>
                        <input type="text"
                               class="form-control"
                               name="formData[title]"
                               value="<?= htmlspecialchars($article['title'] ?? '') ?>"
                               placeholder="게시글 제목을 입력하세요"
                               maxlength="255"
                               required>
                    </div>

                    <!-- 내용 -->
                    <div class="mb-3">
                        <label class="form-label">내용</label>
                        <?= editor_html('article_content', $article['content'] ?? '', [
                            'name' => 'formData[content]',
                            'height' => 400,
                            'toolbar' => 'full',
                            'placeholder' => '게시글 내용을 입력하세요',
                        ]) ?>
                    </div>
                </div>
            </div>

            <!-- 첨부파일 (게시판 use_file 시 노출 / 등록 모드는 게시판 선택 시 동적 표시) -->
            <div class="card mb-4" id="file-card" style="<?= $useFile ? '' : 'display:none;' ?>">
                <div class="card-hero">
                    <i class="bi bi-paperclip text-pastel-blue"></i>
                    <span>첨부파일</span>
                    <small class="text-muted ms-2" id="file-limit-hint"><?= $fileCountLimit ? '최대 ' . (int) $fileCountLimit . '개' . ($fileExtAllowed ? ', ' . htmlspecialchars($fileExtAllowed) : '') : '' ?></small>
                </div>
                <div class="card-body">
                    <?php if ($isEdit && !empty($attachments)): ?>
                    <div class="list-group list-group-flush mb-3">
                        <?php foreach ($attachments as $attachment): ?>
                        <div class="list-group-item d-flex justify-content-between align-items-center px-0" id="attachment-<?= $attachment['attachment_id'] ?>">
                            <div>
                                <?php if ($attachment['is_image']): ?>
                                <i class="bi bi-image text-success"></i>
                                <?php else: ?>
                                <i class="bi bi-file-earmark text-secondary"></i>
                                <?php endif; ?>
                                <span><?= htmlspecialchars($attachment['original_name']) ?></span>
                                <small class="text-muted ms-2">(<?= number_format($attachment['file_size'] / 1024, 1) ?> KB)</small>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteAttachment(<?= $attachment['attachment_id'] ?>)">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    <?php
                        $acceptValue = $fileExtAllowed ? '.' . implode(',.', array_map('trim', explode(',', $fileExtAllowed))) : '';
                        $existingCount = $isEdit ? count($attachments) : 0;
                        $availableSlots = max(0, $fileCountLimit - $existingCount);
                    ?>
                    <div id="file-input-list" data-file-limit="<?= (int) $fileCountLimit ?>" data-file-accept="<?= htmlspecialchars($acceptValue, ENT_QUOTES) ?>">
                        <?php for ($i = 0; $i < $availableSlots; $i++): ?>
                        <input type="file" name="files[]" class="form-control mb-2"<?= $acceptValue ? ' accept="' . htmlspecialchars($acceptValue, ENT_QUOTES) . '"' : '' ?>>
                        <?php endfor; ?>
                    </div>
                    <div class="form-text" id="file-help"><?= $availableSlots > 0
                        ? '저장(발행/임시저장) 시 함께 업로드됩니다.' . ($fileCountLimit ? ' (최대 ' . (int) $fileCountLimit . '개)' : '')
                        : '첨부 가능 개수(' . (int) $fileCountLimit . '개)를 모두 사용했습니다. 기존 파일을 삭제하면 추가할 수 있습니다.' ?></div>
                </div>
            </div>

            <!-- 관련 링크 (게시판 use_link 시 노출 / 등록 모드는 게시판 선택 시 동적 표시) -->
            <div class="card mb-4" id="link-card" style="<?= $useLink ? '' : 'display:none;' ?>">
                <div class="card-hero">
                    <i class="bi bi-link-45deg text-pastel-purple"></i>
                    <span>관련 링크</span>
                </div>
                <div class="card-body">
                    <!-- 새 링크 추가 (저장 시 반영) -->
                    <div class="input-group mb-3">
                        <input type="url" id="link-url-input" class="form-control" placeholder="https://">
                        <input type="text" id="link-title-input" class="form-control" placeholder="제목 (선택)">
                        <button type="button" class="btn btn-outline-secondary" id="link-add-btn"><i class="bi bi-plus-lg"></i> 추가</button>
                    </div>
                    <div id="pending-link-list" class="list-group list-group-flush"></div>
                    <div class="list-group list-group-flush" id="link-list">
                        <?php if ($isEdit && !empty($links)): ?>
                            <?php foreach ($links as $lnk): ?>
                            <div class="list-group-item d-flex justify-content-between align-items-center px-0" id="link-<?= $lnk['link_id'] ?>">
                                <a href="<?= htmlspecialchars($lnk['link_url']) ?>" target="_blank" rel="noopener" class="text-truncate">
                                    <i class="bi bi-link-45deg"></i>
                                    <?= htmlspecialchars($lnk['link_title'] ?: $lnk['link_url']) ?>
                                </a>
                                <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteLink(<?= $lnk['link_id'] ?>)">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <div id="link-hidden-inputs"></div>
                </div>
            </div>
        </div>

        <!-- 사이드바 -->
        <div class="col-lg-3">
            <!-- 게시판 선택 -->
            <div class="card mb-4">
                <div class="card-hero">
                    <i class="bi bi-folder text-pastel-green"></i>
                    <span>게시판</span>
                </div>
                <div class="card-body">
                    <select class="form-select" name="formData[board_id]" id="board_select" required <?= $isEdit ? 'disabled' : '' ?>>
                        <option value="">게시판 선택</option>
                        <?php
                        $currentGroup = '';
                        foreach ($boards ?? [] as $board):
                            $boardConfig = $board['config'];
                            $groupName = $board['group_name'] ?: '미분류';
                            if ($currentGroup !== $groupName):
                                if ($currentGroup !== ''):
                                    echo '</optgroup>';
                                endif;
                                $currentGroup = $groupName;
                                echo '<optgroup label="' . htmlspecialchars($groupName) . '">';
                            endif;
                        ?>
                        <option value="<?= $boardConfig->getBoardId() ?>" <?= ($article['board_id'] ?? $selectedBoardId) == $boardConfig->getBoardId() ? 'selected' : '' ?>>
                            <?= htmlspecialchars($boardConfig->getBoardName()) ?>
                        </option>
                        <?php endforeach; ?>
                        <?php if ($currentGroup !== ''): ?>
                        </optgroup>
                        <?php endif; ?>
                    </select>
                    <?php if ($isEdit): ?>
                    <input type="hidden" name="formData[board_id]" value="<?= $article['board_id'] ?>">
                    <?php endif; ?>
                </div>
            </div>

            <!-- 카테고리 -->
            <div class="card mb-4" id="category_section" style="<?= empty($categories) ? 'display:none;' : '' ?>">
                <div class="card-hero">
                    <i class="bi bi-tag text-pastel-purple"></i>
                    <span>카테고리</span>
                </div>
                <div class="card-body">
                    <select class="form-select" name="formData[category_id]" id="category_select">
                        <option value="">카테고리 선택</option>
                        <?php foreach ($categories ?? [] as $category): ?>
                        <option value="<?= $category['category_id'] ?>" <?= ($article['category_id'] ?? '') == $category['category_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($category['category_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- 옵션 -->
            <div class="card mb-4">
                <div class="card-hero">
                    <i class="bi bi-gear text-pastel-sky"></i>
                    <span>옵션</span>
                </div>
                <div class="card-body">
                    <div class="form-check form-switch mb-3">
                        <input type="checkbox"
                               class="form-check-input"
                               name="formData[is_notice]"
                               id="is_notice"
                               value="1"
                               <?= ($article['is_notice'] ?? false) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_notice">
                            <i class="bi bi-megaphone"></i> 공지사항
                        </label>
                    </div>
                    <div class="form-check form-switch mb-3">
                        <input type="checkbox"
                               class="form-check-input"
                               name="formData[is_secret]"
                               id="is_secret"
                               value="1"
                               <?= ($article['is_secret'] ?? false) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_secret">
                            <i class="bi bi-lock"></i> 비밀글
                        </label>
                    </div>
                </div>
            </div>

            <!-- 권한 설정 -->
            <div class="card mb-4">
                <div class="card-hero">
                    <i class="bi bi-shield-lock text-pastel-orange"></i>
                    <span>권한 설정</span>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label small">읽기 레벨</label>
                        <select class="form-select" name="formData[read_level]">
                            <option value="">게시판 설정 사용</option>
                            <?php for ($i = 0; $i <= 10; $i++): ?>
                            <option value="<?= $i ?>" <?= ($article['read_level'] ?? '') === $i ? 'selected' : '' ?>>
                                Lv.<?= $i ?><?= $i === 0 ? ' (전체)' : '' ?>
                            </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="mb-0">
                        <label class="form-label small">다운로드 레벨</label>
                        <select class="form-select" name="formData[download_level]">
                            <option value="">게시판 설정 사용</option>
                            <?php for ($i = 0; $i <= 10; $i++): ?>
                            <option value="<?= $i ?>" <?= ($article['download_level'] ?? '') === $i ? 'selected' : '' ?>>
                                Lv.<?= $i ?><?= $i === 0 ? ' (전체)' : '' ?>
                            </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>
            </div>

            <!-- 현재 상태 (수정 시) -->
            <?php if ($isEdit): ?>
            <div class="card">
                <div class="card-hero">
                    <i class="bi bi-info-circle text-pastel-blue"></i>
                    <span>정보</span>
                </div>
                <div class="card-body small">
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">상태</span>
                        <span>
                            <?php
                            $statusLabels = ['published' => '발행', 'draft' => '임시저장', 'deleted' => '삭제됨'];
                            echo $statusLabels[$article['status'] ?? 'published'] ?? '알 수 없음';
                            ?>
                        </span>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">조회수</span>
                        <span><?= number_format($article['view_count'] ?? 0) ?></span>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">댓글수</span>
                        <span><?= number_format($article['comment_count'] ?? 0) ?></span>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">작성일</span>
                        <span><?= substr($article['created_at'] ?? '', 0, 16) ?></span>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span class="text-muted">수정일</span>
                        <span><?= substr($article['updated_at'] ?? '', 0, 16) ?></span>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
</form>

<script>
// 상태 설정
function setStatus(status) {
    document.getElementById('article_status').value = status;

    // 에디터 동기화 (폼 제출 전 명시적 호출)
    if (typeof MubloEditor !== 'undefined' && MubloEditor.syncAll) {
        MubloEditor.syncAll();
    }

    return true;
}

// 저장 완료 콜백
MubloRequest.registerCallback('articleSaved', function(response) {
    if (response.result === 'success') {
        alert(response.message || '저장되었습니다.');
        if (response.data && response.data.redirect) {
            location.href = response.data.redirect;
        } else {
            location.href = '/admin/board/article';
        }
    } else {
        alert(response.message || '저장에 실패했습니다.');
    }
});

// 게시판 변경 시 카테고리 + 파일/링크 설정 로드 (등록 모드)
document.getElementById('board_select')?.addEventListener('change', function() {
    const boardId = this.value;
    const categorySection = document.getElementById('category_section');
    const categorySelect = document.getElementById('category_select');

    if (!boardId) {
        categorySection.style.display = 'none';
        categorySelect.innerHTML = '<option value="">카테고리 선택</option>';
        applyBoardFeatures(null);
        return;
    }

    // 게시판 설정(카테고리/파일/링크) 로드
    fetch('/admin/board/article/categories?board_id=' + boardId)
        .then(response => response.json())
        .then(data => {
            if (data.result === 'success' && data.data.categories.length > 0) {
                let options = '<option value="">카테고리 선택</option>';
                data.data.categories.forEach(function(cat) {
                    options += '<option value="' + cat.category_id + '">' + cat.category_name + '</option>';
                });
                categorySelect.innerHTML = options;
                categorySection.style.display = 'block';
            } else {
                categorySection.style.display = 'none';
                categorySelect.innerHTML = '<option value="">카테고리 선택</option>';
            }
            applyBoardFeatures(data.data && data.data.board ? data.data.board : null);
        })
        .catch(err => {
            console.error('게시판 설정 로드 실패:', err);
            categorySection.style.display = 'none';
            applyBoardFeatures(null);
        });
});

// 선택된 게시판의 파일/링크 사용 여부에 따라 첨부/링크 카드 동적 표시 (등록 모드)
function applyBoardFeatures(board) {
    const fileCard = document.getElementById('file-card');
    const linkCard = document.getElementById('link-card');

    if (fileCard) {
        if (board && board.use_file) {
            const limit = parseInt(board.file_count_limit || 0, 10);
            const exts = (board.file_extension_allowed || '').split(',').map(s => s.trim()).filter(Boolean);
            const accept = exts.length ? '.' + exts.join(',.') : '';

            const list = document.getElementById('file-input-list');
            list.dataset.fileLimit = limit;
            list.dataset.fileAccept = accept;
            list.innerHTML = ''; // 등록 모드: 기존 첨부 없음 → 제한 개수만큼 슬롯 생성
            for (let i = 0; i < limit; i++) {
                const input = document.createElement('input');
                input.type = 'file';
                input.name = 'files[]';
                input.className = 'form-control mb-2';
                if (accept) input.accept = accept;
                list.appendChild(input);
            }
            const hint = document.getElementById('file-limit-hint');
            if (hint) hint.textContent = limit ? ('최대 ' + limit + '개' + (board.file_extension_allowed ? ', ' + board.file_extension_allowed : '')) : '';
            const help = document.getElementById('file-help');
            if (help) help.textContent = '저장(발행/임시저장) 시 함께 업로드됩니다.' + (limit ? ' (최대 ' + limit + '개)' : '');

            fileCard.style.display = '';
        } else {
            fileCard.style.display = 'none';
        }
    }

    if (linkCard) {
        linkCard.style.display = (board && board.use_link) ? '' : 'none';
    }
}

// 첨부파일 삭제
function deleteAttachment(attachmentId) {
    if (!confirm('이 첨부파일을 삭제하시겠습니까?')) {
        return;
    }

    MubloRequest.requestJson('/admin/board/article/attachment-delete', {
        attachment_id: attachmentId
    }).then(response => {
        if (response.result === 'success') {
            document.getElementById('attachment-' + attachmentId)?.remove();
            addFileInputSlot(); // 삭제된 만큼 업로드 슬롯 확보
        } else {
            alert(response.message || '삭제에 실패했습니다.');
        }
    }).catch(err => {
        alert('삭제 중 오류가 발생했습니다.');
        console.error(err);
    });
}

// 첨부 슬롯 추가 (제한 - 기존첨부 범위 내)
function addFileInputSlot() {
    const list = document.getElementById('file-input-list');
    if (!list) return;
    const limit = parseInt(list.dataset.fileLimit || '0', 10);
    const currentInputs = list.querySelectorAll('input[type="file"]').length;
    const existing = document.querySelectorAll('[id^="attachment-"]').length;
    if (currentInputs + existing >= limit) return;

    const input = document.createElement('input');
    input.type = 'file';
    input.name = 'files[]';
    input.className = 'form-control mb-2';
    const accept = list.dataset.fileAccept || '';
    if (accept) input.accept = accept;
    list.appendChild(input);

    // 슬롯이 생겼으므로 "모두 사용" 안내문을 정상 문구로 갱신
    const help = document.getElementById('file-help');
    if (help) help.textContent = '저장(발행/임시저장) 시 함께 업로드됩니다.' + (limit ? ' (최대 ' + limit + '개)' : '');
}

// 기존 링크 삭제 (즉시)
function deleteLink(linkId) {
    if (!confirm('이 링크를 삭제하시겠습니까?')) {
        return;
    }

    MubloRequest.requestJson('/admin/board/article/link-delete', {
        link_id: linkId
    }).then(response => {
        if (response.result === 'success') {
            document.getElementById('link-' + linkId)?.remove();
        } else {
            alert(response.message || '삭제에 실패했습니다.');
        }
    }).catch(err => {
        alert('삭제 중 오류가 발생했습니다.');
        console.error(err);
    });
}

// 새 링크 추가 (저장 시 반영) — hidden input으로 폼에 포함
(function() {
    const addBtn = document.getElementById('link-add-btn');
    if (!addBtn) return;
    let linkIndex = 0;

    addBtn.addEventListener('click', function() {
        const urlInput = document.getElementById('link-url-input');
        const titleInput = document.getElementById('link-title-input');
        const url = urlInput.value.trim();
        const title = titleInput.value.trim();
        if (!url) { alert('링크 URL을 입력해주세요.'); return; }

        const idx = linkIndex++;
        const hidden = document.getElementById('link-hidden-inputs');
        const i1 = document.createElement('input');
        i1.type = 'hidden'; i1.name = 'formData[links][' + idx + '][url]'; i1.value = url; i1.id = 'plink-' + idx + '-url';
        const i2 = document.createElement('input');
        i2.type = 'hidden'; i2.name = 'formData[links][' + idx + '][title]'; i2.value = title; i2.id = 'plink-' + idx + '-title';
        hidden.appendChild(i1); hidden.appendChild(i2);

        const row = document.createElement('div');
        row.className = 'list-group-item d-flex justify-content-between align-items-center px-0';
        row.innerHTML = '<span class="text-truncate text-primary"><i class="bi bi-link-45deg"></i> ' +
            MubloRequest.escapeHtml(title || url) +
            ' <span class="badge bg-light text-muted ms-1">저장 대기</span></span>';
        const rm = document.createElement('button');
        rm.type = 'button'; rm.className = 'btn btn-sm btn-outline-danger';
        rm.innerHTML = '<i class="bi bi-x-lg"></i>';
        rm.addEventListener('click', function() {
            row.remove();
            document.getElementById('plink-' + idx + '-url')?.remove();
            document.getElementById('plink-' + idx + '-title')?.remove();
        });
        row.appendChild(rm);
        document.getElementById('pending-link-list').appendChild(row);

        urlInput.value = ''; titleInput.value = '';
    });
})();
</script>

<?= editor_js() ?>
