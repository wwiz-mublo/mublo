<?php
$csrfToken = $mublo['security']['csrfToken'];
/**
 * Mypage - 회원탈퇴
 *
 * @var array[] $mypageMenus    사이드바 메뉴 목록
 * @var string  $currentSection 현재 활성 섹션
 */
?>

<?php ob_start(); ?>
<div class="mypage-withdraw">
    <div class="mypage-header">
        <h1 class="mypage-header__title">회원 탈퇴</h1>
        <p class="mypage-header__desc">계정을 삭제합니다. 진행 전 아래 유의사항을 꼭 확인하세요.</p>
    </div>

    <div id="withdraw-message" class="alert-danger" style="display: none;"></div>

    <div class="warning-box">
        <h4>탈퇴 시 유의사항</h4>
        <ul>
            <li>탈퇴 시 개인정보(이름, 이메일, 전화번호 등)가 즉시 삭제됩니다.</li>
            <li>아이디와 가입일, 탈퇴일은 보존됩니다.</li>
            <li>작성한 게시글 및 댓글은 삭제되지 않습니다.</li>
            <li>탈퇴 후 동일한 아이디로 재가입이 불가능할 수 있습니다.</li>
            <li>도메인(사이트)을 운영 중인 경우 탈퇴할 수 없습니다.</li>
        </ul>
    </div>

    <form id="withdraw-form">
        <input type="hidden" name="_token" value="<?= htmlspecialchars($csrfToken ?? '') ?>">

        <div class="withdraw-form-group">
            <label for="reason">탈퇴 사유 <span style="color:var(--muted-foreground); font-weight:normal;">(선택)</span></label>
            <textarea id="reason" name="reason" class="withdraw-form-control" rows="3"
                      placeholder="탈퇴 사유를 입력해주세요 (선택사항)" maxlength="500"></textarea>
        </div>

        <div class="withdraw-form-group">
            <label for="password">비밀번호 확인</label>
            <input type="password" id="password" name="password" class="withdraw-form-control"
                   placeholder="현재 비밀번호를 입력하세요" required>
        </div>

        <div class="checkbox-group">
            <input type="checkbox" id="confirm" name="confirm">
            <label for="confirm">위 내용을 확인했으며, 회원 탈퇴에 동의합니다.</label>
        </div>

        <div class="btn-group">
            <a href="/mypage/profile" class="btn btn-secondary">취소</a>
            <button type="button" class="btn btn-danger" id="btn-withdraw">탈퇴하기</button>
        </div>
    </form>
</div>

<script>
document.getElementById('btn-withdraw').addEventListener('click', function() {
    var msgEl    = document.getElementById('withdraw-message');
    var password = document.getElementById('password').value;

    if (!password) {
        msgEl.textContent = '비밀번호를 입력해주세요.';
        msgEl.style.display = 'block';
        return;
    }

    if (!document.getElementById('confirm').checked) {
        msgEl.textContent = '회원 탈퇴에 동의해주세요.';
        msgEl.style.display = 'block';
        return;
    }

    MubloRequest.showConfirm('정말로 탈퇴하시겠습니까? 이 작업은 되돌릴 수 없습니다.', function() {
        msgEl.style.display = 'none';

        var form     = document.getElementById('withdraw-form');
        var formData = new FormData(form);

        MubloRequest.sendRequest('/mypage/withdraw', {
            method: 'POST',
            body: formData,
        }).then(function(data) {
            // .then() 도달 = 성공 확정 (에러는 MubloRequest가 자동 alert + .catch로 전달)
            MubloRequest.showAlert(data.message || '회원 탈퇴가 완료되었습니다.', 'success', {
                onClose: function() {
                    location.href = (data.data && data.data.redirect) || '/';
                }
            });
        }).catch(function() {
            // 에러는 MubloRequest가 이미 alert 처리함
        });
    }, { type: 'warning' });
});
</script>
<?php $content = ob_get_clean(); ?>

<?php include __DIR__ . '/_layout.php'; ?>
