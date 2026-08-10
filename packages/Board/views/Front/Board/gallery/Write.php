<?php
/**
 * Board Write/Edit Form (gallery skin)
 *
 * 게시글 작성/수정 폼 갤러리 스킨
 *
 * @var array $board 게시판 설정 (toArray)
 * @var array|null $article 게시글 데이터 (수정 시)
 * @var bool $isEdit 수정 모드 여부
 * @var bool $isLoggedIn 로그인 여부
 */

$board = $board ?? [];
$article = $article ?? [];
$isEdit = $isEdit ?? false;
$categories = $categories ?? [];
$attachments = $attachments ?? [];
$links = $links ?? [];

$boardSlug = htmlspecialchars($board['board_slug'] ?? '');
$boardName = htmlspecialchars($board['board_name'] ?? '');
$useSecret = !empty($board['use_secret']);
$isSecretBoard = !empty($board['is_secret_board']);
$useCategory = !empty($board['use_category']);
$useFile = !empty($board['use_file']);
$useLink = !empty($board['use_link']);
$allowGuest = !empty($board['allow_guest']) || (int)($board['write_level'] ?? 1) === 0;
$fileCountLimit = (int) ($board['file_count_limit'] ?? 5);
$fileExtAllowed = htmlspecialchars($board['file_extension_allowed'] ?? '');

$articleId = (int) ($article['article_id'] ?? 0);
$title = htmlspecialchars($article['title'] ?? '');
$content = $article['content'] ?? '';

$actionUrl = $isEdit
    ? '/board/' . $boardSlug . '/edit/' . $articleId
    : '/board/' . $boardSlug . '/write';

$this->assets->addCss('/serve/package/Board/views/Front/Board/gallery/_assets/css/board.css');
?>

<?= editor_css() ?>

<div class="board-write">
    <!-- 게시판 헤더 -->
    <div class="board-write__header">
        <h2 class="board-write__board-name">
            <a href="/board/<?= $boardSlug ?>"><?= $boardName ?></a>
        </h2>
        <p class="board-write__desc"><?= $isEdit ? '게시글 수정' : '게시글 작성' ?></p>
    </div>

    <form name="frm" id="frm">
        <?php if ($isEdit): ?>
            <input type="hidden" name="formData[article_id]" value="<?= $articleId ?>">
        <?php endif; ?>

        <!-- 비회원 정보 -->
        <?php if (!$isEdit && !$isLoggedIn && $allowGuest): ?>
        <div class="board-write__guest">
            <div class="board-write__field">
                <label class="board-write__label" for="author_name">이름 <span class="board-write__required">*</span></label>
                <input type="text" id="author_name" name="formData[author_name]"
                       class="board-write__input" placeholder="이름" maxlength="50"
                       value="<?= htmlspecialchars($article['author_name'] ?? '') ?>">
            </div>
            <div class="board-write__field">
                <label class="board-write__label" for="author_password">비밀번호 <span class="board-write__required">*</span></label>
                <input type="password" id="author_password" name="formData[author_password]"
                       class="board-write__input" placeholder="비밀번호" maxlength="50">
            </div>
        </div>
        <?php endif; ?>

        <!-- 카테고리 -->
        <?php if ($useCategory && !empty($categories)): ?>
        <div class="board-write__field">
            <label class="board-write__label" for="category_id">카테고리</label>
            <select id="category_id" name="formData[category_id]" class="board-write__select">
                <option value="">카테고리 선택</option>
                <?php foreach ($categories as $cat): ?>
                <option value="<?= $cat['category_id'] ?>"
                        <?= (int) ($article['category_id'] ?? 0) === $cat['category_id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($cat['category_name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>

        <!-- 제목 -->
        <div class="board-write__field">
            <label class="board-write__label" for="article_title">제목 <span class="board-write__required">*</span></label>
            <input type="text" id="article_title" name="formData[title]"
                   class="board-write__input board-write__input--title"
                   placeholder="제목을 입력하세요"
                   value="<?= $title ?>" maxlength="255" required>
        </div>

        <!-- 내용 (에디터) -->
        <div class="board-write__field">
            <label class="board-write__label">내용</label>
            <?php // toolbarMobile 은 에디터 어댑터가 data-* 로 넘긴다. 이 옵션을 모르는 에디터는 무시한다. ?>
            <?= editor_html('article_content', $content, [
                'name' => 'formData[content]',
                'height' => 400,
                'toolbar' => 'full',
                'toolbarMobile' => 'compact',
                'placeholder' => '내용을 입력하세요',
            ]) ?>
        </div>

        <!-- 파일 첨부 -->
        <?php if ($useFile): ?>
        <div class="board-write__field">
            <label class="board-write__label">파일 첨부 <span class="board-write__hint">(최대 <?= $fileCountLimit ?>개<?= $fileExtAllowed ? ', ' . $fileExtAllowed : '' ?>)</span></label>
            <div class="board-write__file-list" id="file-list">
                <?php if ($isEdit): ?>
                    <?php foreach ($attachments as $att): ?>
                    <div class="board-write__file-item" data-attachment-id="<?= $att['attachment_id'] ?>">
                        <span class="board-write__file-name"><?= htmlspecialchars($att['original_name']) ?></span>
                        <span class="board-write__file-size">(<?= number_format($att['file_size'] / 1024, 1) ?>KB)</span>
                        <button type="button" class="board-write__file-remove">삭제</button>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div class="board-write__file-upload">
                <input type="file" id="file-input" multiple style="display:none"
                       <?= $fileExtAllowed ? 'accept=".' . implode(',.', explode(',', str_replace(' ', '', $fileExtAllowed))) . '"' : '' ?>>
                <button type="button" class="board-write__btn board-write__btn--file" id="file-add-btn">파일 선택</button>
                <span class="board-write__file-count" id="file-count-label"></span>
            </div>
        </div>
        <?php endif; ?>

        <!-- 링크 -->
        <?php if ($useLink): ?>
        <div class="board-write__field">
            <label class="board-write__label">관련 링크</label>
            <div class="board-write__link-list" id="link-list">
                <?php if ($isEdit): ?>
                    <?php foreach ($links as $lnk): ?>
                    <div class="board-write__link-item" data-link-id="<?= $lnk['link_id'] ?>">
                        <a href="<?= htmlspecialchars($lnk['link_url']) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($lnk['link_title'] ?: $lnk['link_url']) ?></a>
                        <button type="button" class="board-write__link-remove" onclick="removeLink(<?= $lnk['link_id'] ?>)">삭제</button>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div class="board-write__link-add">
                <input type="url" id="link-url" class="board-write__input" placeholder="https://" style="flex:1">
                <input type="text" id="link-title" class="board-write__input" placeholder="링크 제목 (선택)" style="flex:1">
                <button type="button" class="board-write__btn board-write__btn--link" id="link-add-btn">추가</button>
            </div>
            <div id="link-hidden-inputs"></div>
        </div>
        <?php endif; ?>

        <!-- 옵션 -->
        <?php if ($isSecretBoard): ?>
        <div class="board-write__options">
            <span class="board-write__secret-notice">🔒 이 게시판의 모든 글은 비밀글로 작성됩니다.</span>
            <input type="hidden" name="formData[is_secret]" value="1">
        </div>
        <?php elseif ($useSecret): ?>
        <div class="board-write__options">
            <label class="board-write__checkbox-label">
                <input type="checkbox" name="formData[is_secret]" value="1"
                       <?= !empty($article['is_secret']) ? 'checked' : '' ?>>
                비밀글
            </label>
        </div>
        <?php endif; ?>

        <!-- 버튼 -->
        <div class="board-write__actions">
            <a href="<?= $isEdit ? '/board/' . $boardSlug . '/view/' . $articleId : '/board/' . $boardSlug ?>"
               class="board-write__btn board-write__btn--cancel">취소</a>
            <button type="button"
                    class="board-write__btn board-write__btn--submit mublo-submit"
                    data-target="<?= $actionUrl ?>"
                    data-callback="articleSaved">
                <?= $isEdit ? '수정' : '등록' ?>
            </button>
        </div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const boardSlug = <?= json_encode((string) $boardSlug, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const articleId = <?= $articleId ?>;
    const isEdit = <?= $isEdit ? 'true' : 'false' ?>;

    MubloRequest.registerCallback('articleSaved', function(response) {
        if (response.result === 'success') {
            var warnings = (response.data && response.data.warnings) || [];
            if (warnings.length) {
                MubloRequest.showAlert('일부 첨부 파일이 제외되었습니다:\n\n' + warnings.join('\n'), 'warning');
            }
            if (response.data && response.data.redirect) {
                location.href = response.data.redirect;
            } else {
                location.href = '/board/' + boardSlug;
            }
        } else {
            MubloRequest.showAlert(response.message || '저장에 실패했습니다.', 'error');
        }
    });

    <?php if ($useFile): ?>
    // 파일 누적 관리
    const fileInput = document.getElementById('file-input');
    const fileAddBtn = document.getElementById('file-add-btn');
    const fileList = document.getElementById('file-list');
    const fileCountLabel = document.getElementById('file-count-label');
    const fileLimit = <?= $fileCountLimit ?>;
    const pendingFiles = [];
    const existingCount = <?= $isEdit ? count($attachments) : 0 ?>;
    let removedCount = 0;

    function updateFileCount() {
        const total = (existingCount - removedCount) + pendingFiles.filter(Boolean).length;
        fileCountLabel.textContent = total > 0 ? total + '/' + fileLimit + '개' : '';
    }

    function renderPendingFile(file, index) {
        const item = document.createElement('div');
        item.className = 'board-write__file-item';
        item.dataset.pendingIndex = index;
        item.innerHTML =
            '<span class="board-write__file-name">' + MubloRequest.escapeHtml(file.name) + '</span>' +
            '<span class="board-write__file-size">(' + (file.size / 1024).toFixed(1) + 'KB)</span>' +
            '<button type="button" class="board-write__file-remove">삭제</button>';
        item.querySelector('.board-write__file-remove').addEventListener('click', function() {
            pendingFiles[index] = null;
            item.remove();
            syncFileInput();
            updateFileCount();
        });
        fileList.appendChild(item);
    }

    function syncFileInput() {
        const dt = new DataTransfer();
        pendingFiles.forEach(function(f) { if (f) dt.items.add(f); });
        let realInput = document.getElementById('file-input-real');
        if (!realInput) {
            realInput = document.createElement('input');
            realInput.type = 'file';
            realInput.name = 'files[]';
            realInput.id = 'file-input-real';
            realInput.multiple = true;
            realInput.style.display = 'none';
            fileInput.parentNode.appendChild(realInput);
        }
        realInput.files = dt.files;
    }

    fileAddBtn.addEventListener('click', function() {
        fileInput.click();
    });

    fileInput.addEventListener('change', function() {
        const files = Array.from(this.files);
        if (!files.length) return;

        const currentCount = (existingCount - removedCount) + pendingFiles.filter(Boolean).length;
        const available = fileLimit - currentCount;
        if (available <= 0) {
            MubloRequest.showAlert('최대 ' + fileLimit + '개까지 첨부할 수 있습니다.', 'warning');
            this.value = '';
            return;
        }

        const toAdd = files.slice(0, available);
        if (toAdd.length < files.length) {
            MubloRequest.showAlert('최대 ' + fileLimit + '개까지 첨부할 수 있습니다. ' + toAdd.length + '개만 추가됩니다.', 'warning');
        }

        toAdd.forEach(function(file) {
            const idx = pendingFiles.length;
            pendingFiles.push(file);
            renderPendingFile(file, idx);
        });
        syncFileInput();
        updateFileCount();
        this.value = '';
    });

    <?php if ($isEdit): ?>
    // 기존 파일 삭제 이벤트 위임
    fileList.addEventListener('click', function(e) {
        const btn = e.target.closest('.board-write__file-remove');
        if (!btn) return;
        const item = btn.closest('[data-attachment-id]');
        if (!item) return;
        const attachmentId = item.dataset.attachmentId;
        MubloRequest.showConfirm('파일을 삭제하시겠습니까?', function() {
            MubloRequest.sendRequest({
                url: '/board/' + boardSlug + '/file/delete',
                method: 'POST',
                data: { attachment_id: parseInt(attachmentId) },
                payloadType: MubloRequest.PayloadType.JSON,
                loading: true
            }).then(function() {
                item.remove();
                removedCount++;
                updateFileCount();
            });
        }, { type: 'warning' });
    });
    <?php endif; ?>

    updateFileCount();
    <?php endif; ?>

    <?php if ($useLink): ?>
    // 링크 추가 UI
    let linkIndex = 0;
    const linkAddBtn = document.getElementById('link-add-btn');
    if (linkAddBtn) {
        linkAddBtn.addEventListener('click', function() {
            const url = document.getElementById('link-url').value.trim();
            const title = document.getElementById('link-title').value.trim();
            if (!url) { MubloRequest.showAlert('링크 URL을 입력해주세요.', 'warning'); return; }

            <?php if ($isEdit): ?>
            // 수정 모드: AJAX로 즉시 저장
            MubloRequest.sendRequest({
                url: '/board/' + boardSlug + '/link/add',
                method: 'POST',
                data: { article_id: articleId, link_url: url, link_title: title },
                payloadType: MubloRequest.PayloadType.JSON,
                loading: true
            }).then(function(res) {
                if (res.result === 'success' && res.data && res.data.link) {
                    const lnk = res.data.link;
                    addLinkRow(lnk.link_id, lnk.link_url, lnk.link_title || lnk.link_url, true);
                    document.getElementById('link-url').value = '';
                    document.getElementById('link-title').value = '';
                }
            });
            <?php else: ?>
            // 작성 모드: hidden input으로 폼에 포함
            const idx = linkIndex++;
            const container = document.getElementById('link-hidden-inputs');
            const inp1 = document.createElement('input');
            inp1.type = 'hidden'; inp1.name = 'formData[links][' + idx + '][url]'; inp1.value = url;
            inp1.id = 'link-hidden-' + idx + '-url';
            const inp2 = document.createElement('input');
            inp2.type = 'hidden'; inp2.name = 'formData[links][' + idx + '][title]'; inp2.value = title;
            inp2.id = 'link-hidden-' + idx + '-title';
            container.appendChild(inp1);
            container.appendChild(inp2);

            addLinkRow('new-' + idx, url, title || url, false);
            document.getElementById('link-url').value = '';
            document.getElementById('link-title').value = '';
            <?php endif; ?>
        });
    }

    function addLinkRow(id, url, displayText, isServer) {
        const item = document.createElement('div');
        item.className = 'board-write__link-item';
        item.dataset.linkId = id;
        item.innerHTML =
            '<a href="' + MubloRequest.escapeHtml(url) + '" target="_blank" rel="noopener">' +
            MubloRequest.escapeHtml(displayText) + '</a>' +
            '<button type="button" class="board-write__link-remove">삭제</button>';
        item.querySelector('.board-write__link-remove').addEventListener('click', function() {
            if (isServer) {
                removeLink(id);
            } else {
                const idx = String(id).replace('new-', '');
                const h1 = document.getElementById('link-hidden-' + idx + '-url');
                const h2 = document.getElementById('link-hidden-' + idx + '-title');
                if (h1) h1.remove();
                if (h2) h2.remove();
                item.remove();
            }
        });
        document.getElementById('link-list').appendChild(item);
    }

    function removeLink(linkId) {
        MubloRequest.showConfirm('링크를 삭제하시겠습니까?', function() {
            MubloRequest.sendRequest({
                url: '/board/' + boardSlug + '/link/delete',
                method: 'POST',
                data: { link_id: linkId },
                payloadType: MubloRequest.PayloadType.JSON,
                loading: true
            }).then(function() {
                const item = document.querySelector('[data-link-id="' + linkId + '"]');
                if (item) item.remove();
            });
        }, { type: 'warning' });
    }
    <?php endif; ?>
});
</script>

<?= editor_js() ?>
