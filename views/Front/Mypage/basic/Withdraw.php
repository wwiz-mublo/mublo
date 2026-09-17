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

        <?php
            // 회원정보 수정과 같은 구성이다 — 확인을 마치면 SNS 칸은 사라지고,
            // 비밀번호 칸은 자리를 지키며 확인됐음을 알린다.
            $confirmed = !empty($reauthConfirmed);
            $showSnsColumn = !$confirmed && !empty($reauthOptions);
        ?>
        <div class="withdraw-form-row">
            <div class="withdraw-form-group">
                <label for="password">비밀번호 확인</label>
                <?php if ($confirmed): ?>
                <input type="text" id="password" class="withdraw-form-control"
                       value="본인 확인이 완료되었습니다" disabled>
                <?php else: ?>
                <input type="password" id="password" name="password" class="withdraw-form-control"
                       placeholder="현재 비밀번호를 입력하세요">
                <?php endif; ?>
            </div>
            <?php if ($showSnsColumn): ?>
            <div class="withdraw-form-group">
                <div class="form-group-title">가입한 계정으로 확인</div>
                <?php foreach ($reauthOptions as $optionHtml): ?>
                    <?= $optionHtml ?>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
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
    // 본인 확인을 마치면 비밀번호 칸은 안내용으로 비활성 상태가 된다 — 입력을 받지 않는다.
    var passwordEl = document.getElementById('password');

    if (passwordEl && !passwordEl.disabled && !passwordEl.value) {
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

        MubloRequest.sendRequest({
            method: 'POST',
            url: '/mypage/withdraw',
            payloadType: 'form',
            data: formData,
        }).then(function(data) {
            // .then() 도달 = 성공 확정 (에러는 MubloRequest가 자동 alert + .catch로 전달)
            MubloRequest.showAlert(data.message || '회원 탈퇴가 완료되었습니다.', 'success', {
                onClose: function() {
                    location.href = (data.data && data.data.redirect) || '/';
                }
            });
        }).catch(function(error) {
            // MubloRequest 가 띄우는 알림창은 그대로 둔다(주의를 끄는 역할). 다만 차단
            // 사유에는 주문번호가 여러 개 들어가는데, 알림창은 닫으면 사라지고 복사도
            // 어렵다. 그 번호를 들고 주문 내역으로 가야 하는 사람을 위해 화면에도 남긴다.
            //
            // 서버는 첫 줄에 상황을, 그 아래 줄마다 주문 한 건을 담아 보낸다. 알림창은
            // 가운데 정렬이라 개행만으로 충분하지만, 여기서는 목록으로 그린다.
            var reason = (error && error.message) ? error.message : '';
            if (!reason) {
                return;
            }

            var lines   = reason.split('\n');
            var summary = lines.shift();

            msgEl.textContent = '';

            var paragraph = document.createElement('p');
            paragraph.className = 'withdraw-block__summary';
            paragraph.textContent = summary;
            msgEl.appendChild(paragraph);

            if (lines.length) {
                var list = document.createElement('ul');
                list.className = 'withdraw-block__orders';
                lines.forEach(function(line) {
                    var item = document.createElement('li');
                    item.textContent = line;
                    list.appendChild(item);
                });
                msgEl.appendChild(list);
            }

            msgEl.style.display = 'block';
            msgEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
        });
    }, { type: 'warning' });
});
</script>
<?php $content = ob_get_clean(); ?>

<?php include __DIR__ . '/_layout.php'; ?>
