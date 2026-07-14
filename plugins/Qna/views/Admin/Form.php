<?php
/**
 * QnA 관리 - 문의 상세 & 답변 스레드 (List/Form 컨벤션의 Form 뷰)
 *
 * @var string $pageTitle
 * @var array  $inquiry
 * @var array  $replies  스레드 글(운영자 내부 메모 포함)
 * @var array  $statuses
 */
use Mublo\Plugin\Qna\Enum\AuthorType;

$pageTitle = $pageTitle ?? 'Q&A';
$inquiry   = $inquiry ?? [];
$replies   = $replies ?? [];
$id        = (int) ($inquiry['post_id'] ?? 0);

// 작성자 표시 — 목록과 동일한 스냅샷 폴백(author_name → 회원 #N → 비회원)
$authorName = trim((string) ($inquiry['author_name'] ?? ''));
if ($authorName === '') {
    $mid        = (int) ($inquiry['member_id'] ?? 0);
    $authorName = $mid > 0 ? '회원 #' . $mid : '비회원';
}

/** 첨부 목록 렌더 (다운로드 링크) */
$renderAttachments = function (array $atts): string {
    if ($atts === []) {
        return '';
    }
    $items = '';
    foreach ($atts as $a) {
        $items .= '<a href="' . htmlspecialchars($a['url'] ?? '#') . '" class="btn btn-sm btn-outline-secondary me-1 mb-1" rel="nofollow">'
            . '<i class="bi bi-paperclip"></i> ' . htmlspecialchars($a['filename'] ?? '첨부') . '</a>';
    }
    return '<div class="mt-2">' . $items . '</div>';
};
?>
<div class="page-container">
    <div class="page-title">
        <div class="page-title-text">
            <h3><?= htmlspecialchars($pageTitle) ?> #<?= $id ?></h3>
            <p>문의 내용을 확인하고 답변을 작성합니다.</p>
        </div>
        <div class="page-title-actions">
            <a href="/admin/qna" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-list"></i> 목록
            </a>
        </div>
    </div>

    <!-- 문의 본문 -->
    <div class="page-block">
        <div class="card mb-3">
            <div class="card-body">
                <h5><?= htmlspecialchars($inquiry['title'] ?? '') ?></h5>
                <div class="text-muted small mb-2">
                    <?= htmlspecialchars($authorName) ?> ·
                    <?= htmlspecialchars($inquiry['created_at'] ?? '') ?>
                </div>
                <div class="qna-content"><?= nl2br(htmlspecialchars($inquiry['content'] ?? '')) ?></div>
                <?= $renderAttachments($inquiry['attachments'] ?? []) ?>
            </div>
        </div>
    </div>

    <!-- 답변 스레드 -->
    <div class="page-block">
        <div class="qna-thread mb-3">
            <?php foreach ($replies as $r): $type = AuthorType::tryFrom($r['author_type'] ?? 'member'); ?>
                <div class="card mb-2 <?= $type === AuthorType::Admin ? 'border-primary' : '' ?>">
                    <div class="card-body py-2">
                        <div class="small fw-bold mb-1">
                            <?= $type?->label() ?>
                            <?php if (!empty($r['is_private'])): ?>
                                <span class="badge bg-warning text-dark">내부메모</span>
                            <?php endif; ?>
                            <span class="text-muted fw-normal ms-2"><?= htmlspecialchars($r['created_at'] ?? '') ?></span>
                        </div>
                        <div class="qna-content"><?= nl2br(htmlspecialchars($r['content'] ?? '')) ?></div>
                        <?= $renderAttachments($r['attachments'] ?? []) ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- 운영자 답변 폼 -->
    <div class="page-block">
        <div class="card">
            <div class="card-body">
                <h6>답변 작성</h6>
                <textarea id="reply-content" class="form-control mb-2" rows="4"></textarea>
                <div class="mb-2">
                    <input type="file" class="form-control" id="reply-file" multiple>
                    <ul id="reply-file-list" class="list-unstyled small mt-1 mb-0"></ul>
                </div>
                <div class="form-check mb-2">
                    <input type="checkbox" class="form-check-input" id="reply-private">
                    <label class="form-check-label" for="reply-private">내부 메모(문의자 비노출)</label>
                </div>
                <button type="button" class="btn btn-primary btn-sm" id="btn-reply" data-id="<?= $id ?>">답변 등록</button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var attachments = [];
    var fileInput = document.getElementById('reply-file');
    var listEl = document.getElementById('reply-file-list');

    function renderList() {
        if (!listEl) return;
        listEl.innerHTML = '';
        attachments.forEach(function (f, idx) {
            var li = document.createElement('li');
            var ico = document.createElement('i');
            ico.className = 'bi bi-paperclip text-muted';
            li.appendChild(ico);
            li.appendChild(document.createTextNode(' ' + f.filename + ' '));
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn btn-link btn-sm text-danger p-0';
            btn.textContent = '삭제';
            btn.addEventListener('click', function () { attachments.splice(idx, 1); renderList(); });
            li.appendChild(btn);
            listEl.appendChild(li);
        });
    }

    if (fileInput) {
        fileInput.addEventListener('change', function () {
            var files = Array.prototype.slice.call(this.files || []);
            this.value = '';
            files.forEach(function (file) {
                var fd = new FormData();
                fd.append('file', file);
                MubloRequest.sendRequest({ method: 'POST', url: '/admin/qna/upload', payloadType: 'form', data: fd })
                    .then(function (res) {
                        if (res && res.data && res.data.temp_path) { attachments.push(res.data); renderList(); }
                    })
                    .catch(function (e) { MubloRequest.showAlert((e && e.message) || '업로드 실패', 'error'); });
            });
        });
    }

    document.getElementById('btn-reply')?.addEventListener('click', function () {
        var id = this.dataset.id;
        MubloRequest.requestJson('/admin/qna/' + id + '/reply', {
            content: document.getElementById('reply-content').value,
            is_private: document.getElementById('reply-private').checked ? 1 : 0,
            attachments: attachments
        }, { method: 'POST' }).then(function () { location.reload(); })
          .catch(function (e) { alert((e && e.message) || '등록 실패'); });
    });
})();
</script>
