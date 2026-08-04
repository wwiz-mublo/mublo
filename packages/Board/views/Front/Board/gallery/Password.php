<?php
/**
 * 비회원 글 비밀번호 확인
 *
 * @var string $boardSlug
 * @var int $articleId
 * @var array $board
 */
$boardSlug = htmlspecialchars($boardSlug ?? '');
$articleId = (int) ($articleId ?? 0);
$boardName = htmlspecialchars($board['board_name'] ?? '게시판');
?>
<div class="board-password">
    <div class="board-password__card">
        <h4 class="board-password__title">비밀번호 확인</h4>
        <p class="board-password__desc">이 글은 비밀번호가 설정되어 있습니다.<br>글 작성 시 입력한 비밀번호를 입력해주세요.</p>
        <div class="board-password__form">
            <input type="password" id="guest-password" class="board-password__input" placeholder="비밀번호" maxlength="50" autofocus>
            <button type="button" id="guest-password-btn" class="board-password__btn">확인</button>
        </div>
        <div id="guest-password-error" class="board-password__error" style="display:none"></div>
        <a href="/board/<?= $boardSlug ?>" class="board-password__back">목록으로 돌아가기</a>
    </div>
</div>

<style>
.board-password { display:flex; justify-content:center; align-items:center; min-height:300px; padding:40px 20px; }
.board-password__card { text-align:center; max-width:400px; width:100%; }
.board-password__title { font-size:1.25rem; font-weight:700; margin-bottom:8px; }
.board-password__desc { font-size:0.875rem; color:#666; margin-bottom:20px; line-height:1.5; }
.board-password__form { display:flex; gap:8px; margin-bottom:12px; }
.board-password__input { flex:1; padding:10px 14px; border:1px solid #ddd; border-radius:8px; font-size:0.9rem; outline:none; }
.board-password__input:focus { border-color:#3071ff; }
.board-password__btn { padding:10px 24px; background:#3071ff; color:#fff; border:none; border-radius:8px; font-weight:600; cursor:pointer; }
.board-password__btn:hover { background:#1a5ae0; }
.board-password__error { color:#ef4444; font-size:0.8rem; margin-bottom:8px; }
.board-password__back { font-size:0.8rem; color:#888; text-decoration:none; }
.board-password__back:hover { color:#333; }
</style>

<script>
(function() {
    var btn = document.getElementById('guest-password-btn');
    var input = document.getElementById('guest-password');
    var errorEl = document.getElementById('guest-password-error');

    function submit() {
        var password = input.value.trim();
        if (!password) { input.focus(); return; }

        btn.disabled = true;
        btn.textContent = '확인 중...';

        MubloRequest.requestJson('/board/<?= $boardSlug ?>/password-check', {
            article_id: <?= $articleId ?>,
            password: password
        }).then(function(res) {
            if (res.data?.redirect) {
                location.href = res.data.redirect;
            }
        }).catch(function(err) {
            errorEl.textContent = err?.message || '비밀번호가 일치하지 않습니다.';
            errorEl.style.display = '';
            btn.disabled = false;
            btn.textContent = '확인';
            input.focus();
        });
    }

    btn.addEventListener('click', submit);
    input.addEventListener('keydown', function(e) { if (e.key === 'Enter') submit(); });
})();
</script>
