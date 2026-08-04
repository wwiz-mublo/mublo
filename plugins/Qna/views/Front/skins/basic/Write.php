<?php
/**
 * QnA 프론트 - 문의 작성 폼 (basic 스킨)
 *
 * @var array $config  플러그인 설정 (allow_secret, allow_file 등)
 */
$config      = $config ?? [];
$allowFile   = (int) ($config['allow_file'] ?? 1) === 1;
$fileMaxMb   = (int) ($config['file_max_mb'] ?? 5);
$fileMaxCnt  = (int) ($config['file_max_count'] ?? 2);
$fileExts    = (string) ($config['file_allowed_ext'] ?? '');
$acceptAttr  = $fileExts === '' ? '' : '.' . implode(',.', array_map('trim', explode(',', $fileExts)));

$this->assets->addCss('/serve/plugin/Qna/views/Front/skins/basic/style.css');
?>
<div class="qna-page">
    <div class="qna-page__header">
        <div class="qna-page__title-wrap">
            <i class="bi bi-patch-check qna-page__icon"></i>
            <h1 class="qna-page__title"><?= htmlspecialchars($pageTitle ?? '질문 - Q&A') ?></h1>
        </div>
        <p class="qna-page__subtitle">궁금한 점을 남겨주시면 순차적으로 답변드립니다.</p>
    </div>

    <form id="qna-write-form" class="qna-card qna-form" onsubmit="return false;">
        <div class="qna-field">
            <label class="qna-field__label" for="qna-title">제목</label>
            <input type="text" id="qna-title" name="title" class="qna-input" placeholder="제목을 입력해 주세요" required>
        </div>

        <div class="qna-field">
            <label class="qna-field__label" for="qna-content">내용</label>
            <textarea id="qna-content" name="content" class="qna-textarea" placeholder="문의하실 내용을 자세히 적어주세요." required></textarea>
        </div>

        <?php if ($allowFile): ?>
        <div class="qna-field qna-uploader">
            <label class="qna-field__label">
                첨부파일
                <span class="qna-field__hint">최대 <?= $fileMaxCnt ?>개 · 개당 <?= $fileMaxMb ?>MB<?= $fileExts !== '' ? ' · ' . htmlspecialchars($fileExts) : '' ?></span>
            </label>
            <label class="qna-uploader__btn" for="qna-file">
                <i class="bi bi-paperclip"></i> 파일 선택
            </label>
            <input type="file" id="qna-file" accept="<?= htmlspecialchars($acceptAttr) ?>" multiple>
            <ul id="qna-file-list" class="qna-chips"></ul>
        </div>
        <?php endif; ?>

        <div class="qna-form__actions">
            <a href="/qna" class="qna-btn qna-btn--ghost">취소</a>
            <button type="button" id="qna-submit" class="qna-btn qna-btn--primary">등록</button>
        </div>
    </form>
</div>

<script>
(function () {
    var attachments = [];
    var allowFile = <?= $allowFile ? 'true' : 'false' ?>;
    var maxCount = <?= $fileMaxCnt ?>;
    var maxMb = <?= $fileMaxMb ?>;

    var fileInput = document.getElementById('qna-file');
    var listEl = document.getElementById('qna-file-list');

    function renderList() {
        if (!listEl) return;
        listEl.innerHTML = '';
        attachments.forEach(function (f, idx) {
            var li = document.createElement('li');
            li.className = 'qna-chip';
            var name = document.createElement('span');
            name.className = 'qna-chip__name';
            name.textContent = f.filename;
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'qna-chip__x';
            btn.setAttribute('aria-label', '삭제');
            btn.textContent = '×';
            btn.addEventListener('click', function () { attachments.splice(idx, 1); renderList(); });
            li.appendChild(name);
            li.appendChild(btn);
            listEl.appendChild(li);
        });
    }

    if (fileInput) {
        fileInput.addEventListener('change', function () {
            var files = Array.prototype.slice.call(this.files || []);
            this.value = '';
            files.forEach(function (file) {
                if (attachments.length >= maxCount) {
                    alert('첨부는 최대 ' + maxCount + '개까지 가능합니다.');
                    return;
                }
                if (file.size > maxMb * 1024 * 1024) {
                    alert('[' + file.name + '] 파일이 ' + maxMb + 'MB를 초과했습니다.');
                    return;
                }
                var fd = new FormData();
                fd.append('file', file);
                MubloRequest.sendRequest({ method: 'POST', url: '/qna/upload', payloadType: 'form', data: fd })
                    .then(function (res) {
                        if (res && res.data && res.data.temp_path) {
                            attachments.push(res.data);
                            renderList();
                        }
                    })
                    .catch(function (e) { alert((e && e.message) || '업로드 실패'); });
            });
        });
    }

    document.getElementById('qna-submit')?.addEventListener('click', function () {
        var form = document.getElementById('qna-write-form');
        var btn = this;
        if (!form.title.value.trim()) { alert('제목을 입력해 주세요.'); form.title.focus(); return; }
        if (!form.content.value.trim()) { alert('내용을 입력해 주세요.'); form.content.focus(); return; }
        btn.disabled = true;
        MubloRequest.requestJson('/qna', {
            title: form.title.value,
            content: form.content.value,
            attachments: allowFile ? attachments : []
        }, { method: 'POST' }).then(function (res) {
            location.href = '/qna/' + (res.data && res.data.inquiry_id ? res.data.inquiry_id : '');
        }).catch(function (e) { btn.disabled = false; alert((e && e.message) || '등록 실패'); });
    });
})();
</script>
