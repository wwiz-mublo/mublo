<?php
/**
 * QnA 프론트 - 문의 상세 & 스레드 (basic 스킨)
 *
 * @var array $inquiry  attachments 키에 다운로드 링크 목록 포함
 * @var array $replies  각 항목 attachments 키 포함
 */
use Mublo\Plugin\Qna\Enum\AuthorType;
use Mublo\Plugin\Qna\Enum\InquiryStatus;

$inquiry = $inquiry ?? [];
$replies = $replies ?? [];
$id      = (int) ($inquiry['post_id'] ?? 0);
$status  = InquiryStatus::tryFrom($inquiry['status'] ?? 'open');
$isOwner = (bool) ($isOwner ?? false);

/** 첨부 목록 렌더 (다운로드 링크) */
$renderAttachments = function (array $atts): string {
    if ($atts === []) {
        return '';
    }
    $items = '';
    foreach ($atts as $a) {
        $items .= '<li><a href="' . htmlspecialchars($a['url'] ?? '#') . '" rel="nofollow">'
            . '<i class="bi bi-paperclip"></i> <span class="qna-files__name">' . htmlspecialchars($a['filename'] ?? '첨부') . '</span>'
            . '</a></li>';
    }
    return '<ul class="qna-files">' . $items . '</ul>';
};

$this->assets->addCss('/serve/plugin/Qna/views/Front/skins/basic/style.css');
?>
<div class="qna-page">
    <a href="/qna" class="qna-back"><i class="bi bi-arrow-left"></i> 목록으로</a>

    <!-- 질문 -->
    <article class="qna-card qna-detail">
        <div class="qna-detail__top">
            <span class="qna-badge qna-badge--<?= $status?->badgeClass() ?>"><?= $status?->label() ?></span>
            <?php if (!empty($inquiry['is_secret'])): ?>
                <span class="qna-detail__meta"><i class="bi bi-lock-fill"></i> 비밀글</span>
            <?php endif; ?>
            <?php if ($isOwner): ?>
                <button type="button" id="qna-delete-btn" data-id="<?= $id ?>" class="qna-detail__delete">삭제</button>
            <?php endif; ?>
        </div>
        <h1 class="qna-detail__title"><?= htmlspecialchars($inquiry['title'] ?? '') ?></h1>
        <div class="qna-detail__meta">
            <span><?= htmlspecialchars($inquiry['created_at'] ?? '') ?></span>
        </div>
        <div class="qna-content"><?= nl2br(htmlspecialchars($inquiry['content'] ?? '')) ?></div>
        <?= $renderAttachments($inquiry['attachments'] ?? []) ?>
    </article>

    <!-- 답변 스레드 -->
    <?php if (!empty($replies)): ?>
    <div class="qna-thread-title">
        답변 및 추가 문의 <span class="qna-thread-title__count"><?= count($replies) ?></span>
    </div>
    <section class="qna-thread">
        <?php foreach ($replies as $r):
            $type    = AuthorType::tryFrom($r['author_type'] ?? 'member');
            $isAdmin = $type === AuthorType::Admin;
        ?>
            <div class="qna-msg qna-msg--<?= $type?->value ?>">
                <div class="qna-msg__avatar"><i class="bi <?= $isAdmin ? 'bi-headset' : 'bi-person-fill' ?>"></i></div>
                <div class="qna-msg__body">
                    <div class="qna-msg__head">
                        <span class="qna-msg__author"><?= $type?->label() ?></span>
                        <span class="qna-msg__date"><?= htmlspecialchars($r['created_at'] ?? '') ?></span>
                    </div>
                    <div class="qna-msg__bubble">
                        <div class="qna-content"><?= nl2br(htmlspecialchars($r['content'] ?? '')) ?></div>
                        <?= $renderAttachments($r['attachments'] ?? []) ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </section>
    <?php endif; ?>

    <?php if ($isOwner): ?>
    <!-- 추가 문의 작성 -->
    <div class="qna-card qna-composer" style="margin-top:1.5rem">
        <h3 class="qna-composer__title">추가 문의</h3>
        <textarea id="qna-reply-content" class="qna-textarea" placeholder="추가로 궁금한 점을 남겨주세요."></textarea>
        <div class="qna-composer__bar">
            <div class="qna-uploader">
                <label class="qna-uploader__btn" for="qna-reply-file"><i class="bi bi-paperclip"></i> 파일 첨부</label>
                <input type="file" id="qna-reply-file" multiple>
            </div>
            <button type="button" id="qna-reply-btn" data-id="<?= $id ?>" class="qna-btn qna-btn--primary">등록</button>
        </div>
        <ul id="qna-reply-file-list" class="qna-chips"></ul>
    </div>
    <?php else: ?>
    <!-- 비소유자(운영자 열람) — 프론트는 접수창구, 답변은 관리자에서 -->
    <div class="qna-actions" style="margin-top:1.5rem">
        <a href="/admin/qna/<?= $id ?>/edit?activeCode=K_Qna_001" class="qna-btn qna-btn--ghost">관리자에서 답변하기</a>
    </div>
    <?php endif; ?>
</div>

<script>
(function () {
    var attachments = [];
    var fileInput = document.getElementById('qna-reply-file');
    var listEl = document.getElementById('qna-reply-file-list');

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
                var fd = new FormData();
                fd.append('file', file);
                MubloRequest.sendRequest({ method: 'POST', url: '/qna/upload', payloadType: 'form', data: fd })
                    .then(function (res) {
                        if (res && res.data && res.data.temp_path) { attachments.push(res.data); renderList(); }
                    })
                    .catch(function (e) { alert((e && e.message) || '업로드 실패'); });
            });
        });
    }

    document.getElementById('qna-delete-btn')?.addEventListener('click', function () {
        if (!confirm('문의를 삭제하시겠습니까?\n답변과 첨부파일도 함께 삭제되며 되돌릴 수 없습니다.')) return;
        var btn = this;
        btn.disabled = true;
        MubloRequest.requestJson('/qna/' + btn.dataset.id, {}, { method: 'DELETE' })
            .then(function () { location.href = '/qna'; })
            .catch(function (e) { btn.disabled = false; alert((e && e.message) || '삭제 실패'); });
    });

    document.getElementById('qna-reply-btn')?.addEventListener('click', function () {
        var id = this.dataset.id;
        var btn = this;
        var content = document.getElementById('qna-reply-content').value;
        if (!content.trim()) { alert('내용을 입력해 주세요.'); return; }
        btn.disabled = true;
        MubloRequest.requestJson('/qna/' + id + '/reply', {
            content: content,
            attachments: attachments
        }, { method: 'POST' }).then(function () { location.reload(); })
          .catch(function (e) { btn.disabled = false; alert((e && e.message) || '등록 실패'); });
    });
})();
</script>
