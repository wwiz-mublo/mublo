<?php
/**
 * Admin Profile - 내 정보
 *
 * @var string $pageTitle
 * @var array  $user 로그인한 관리자 정보
 */
?>
<div class="page-container">
    <div class="page-title">
        <div class="page-title-text">
            <h3><?= htmlspecialchars($pageTitle ?? '내 정보') ?></h3>
            <p>관리자 계정 정보를 확인하고 수정합니다.</p>
        </div>
    </div>

    <!-- 기본 정보 -->
    <div class="page-block">
        <div class="card">
            <div class="card-hero">
                <i class="bi bi-person-circle text-pastel-blue"></i>
                <span>계정 정보</span>
            </div>
            <div class="card-body">
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label">아이디</label>
                        <input type="text" class="form-control" value="<?= htmlspecialchars($user['user_id'] ?? '') ?>" disabled>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">회원등급</label>
                        <input type="text" class="form-control" value="<?= htmlspecialchars($user['level_name'] ?? '관리자') ?>" disabled>
                    </div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label">가입일</label>
                        <input type="text" class="form-control" value="<?= htmlspecialchars(substr($user['created_at'] ?? '', 0, 10)) ?>" disabled>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">최근 로그인</label>
                        <input type="text" class="form-control" value="<?= htmlspecialchars(substr($user['last_login_at'] ?? '', 0, 16)) ?>" disabled>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 수정 폼 -->
    <form method="post" id="profile-form">
        <div class="page-block">
            <div class="card">
                <div class="card-hero">
                    <i class="bi bi-pencil-square text-pastel-green"></i>
                    <span>정보 수정</span>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">닉네임</label>
                            <div class="input-group">
                                <input type="text" name="formData[nickname]" id="profile-nickname" class="form-control"
                                       value="<?= htmlspecialchars($user['nickname'] ?? '') ?>"
                                       data-original="<?= htmlspecialchars($user['nickname'] ?? '') ?>" placeholder="2~20자">
                                <button type="button" class="btn btn-outline-secondary" id="btn-check-nickname">중복확인</button>
                            </div>
                            <div class="form-text" id="feedback-nickname">2~20자로 입력</div>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">현재 비밀번호</label>
                            <input type="password" name="formData[current_password]" id="profile-current-password" class="form-control"
                                   placeholder="비밀번호 변경 시 입력" autocomplete="current-password">
                            <div class="form-text">비밀번호를 변경할 때만 입력하세요.</div>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">새 비밀번호</label>
                            <input type="password" name="formData[new_password]" id="profile-new-password" class="form-control"
                                   minlength="<?= (int) ($passwordMinLength ?? 6) ?>"
                                   placeholder="변경할 경우에만 입력">
                            <div class="form-text"><?= htmlspecialchars($passwordHint ?? '최소 6자 이상') ?></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">새 비밀번호 확인</label>
                            <input type="password" name="formData[new_password_confirm]" id="profile-new-password-confirm" class="form-control"
                                   placeholder="새 비밀번호를 다시 입력">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="sticky-act sticky-status">
            <button type="button" class="btn btn-primary" id="btn-profile-save">
                <i class="bi bi-check-lg"></i> 저장
            </button>
        </div>
    </form>
</div>

<script>
(function () {
    const form = document.getElementById('profile-form');
    const nickInput = document.getElementById('profile-nickname');
    const nickFeedback = document.getElementById('feedback-nickname');
    const btnCheckNick = document.getElementById('btn-check-nickname');
    const pwInput = document.getElementById('profile-new-password');
    const pwConfirm = document.getElementById('profile-new-password-confirm');
    const btnSave = document.getElementById('btn-profile-save');

    // 비밀번호 정책 (도메인 설정 반영, 백엔드 검증과 동일 규칙)
    const PW = {
        min: <?= (int) ($passwordMinLength ?? 6) ?>,
        number: <?= !empty($passwordRequireNumber) ? 'true' : 'false' ?>,
        special: <?= !empty($passwordRequireSpecial) ? 'true' : 'false' ?>
    };

    function setFeedback(el, msg, type) {
        if (!el) return;
        el.textContent = msg;
        el.className = 'form-text text-' + (type === 'ok' ? 'success' : type === 'err' ? 'danger' : 'muted');
    }

    // 닉네임 변경 시 중복확인 상태 초기화
    if (nickInput) {
        nickInput.addEventListener('input', function () {
            nickInput.dataset.checked = 'false';
            nickInput.classList.remove('is-valid', 'is-invalid');
            setFeedback(nickFeedback, '2~20자로 입력', 'muted');
        });
    }

    // 닉네임 중복확인
    if (btnCheckNick) {
        btnCheckNick.addEventListener('click', function () {
            const value = (nickInput.value || '').trim();
            if (!value) { setFeedback(nickFeedback, '닉네임을 입력해주세요.', 'err'); return; }
            btnCheckNick.disabled = true;
            MubloRequest.requestJson('/admin/profile/check-nickname', { value: value }, { method: 'POST' })
                .then(function (res) {
                    btnCheckNick.disabled = false;
                    if (res.result === 'success' && !res.data.duplicate) {
                        setFeedback(nickFeedback, res.message || '사용 가능한 닉네임입니다.', 'ok');
                        nickInput.classList.add('is-valid');
                        nickInput.classList.remove('is-invalid');
                        nickInput.dataset.checked = 'true';
                    } else {
                        setFeedback(nickFeedback, res.message || '사용할 수 없는 닉네임입니다.', 'err');
                        nickInput.classList.add('is-invalid');
                        nickInput.classList.remove('is-valid');
                        nickInput.dataset.checked = 'false';
                    }
                })
                .catch(function () {
                    btnCheckNick.disabled = false;
                    setFeedback(nickFeedback, '확인 중 오류가 발생했습니다.', 'err');
                });
        });
    }

    // 저장: 클라이언트 검증 후 제출
    btnSave.addEventListener('click', function () {
        const nickVal = nickInput ? nickInput.value.trim() : '';
        // 닉네임이 비었거나 이전과 같으면 변경 아님 (비밀번호 미입력과 동일하게 조용히 무시)
        const nickChanged = nickVal !== '' && nickVal !== (nickInput.dataset.original || '');
        const pwEntered = pwInput && pwInput.value !== '';

        // 변경 사항이 없으면 조용히 무시 (요청도 보내지 않음)
        if (!nickChanged && !pwEntered) {
            return;
        }

        // 닉네임이 변경됐는데 중복확인을 안 했으면 차단
        if (nickChanged && nickInput.dataset.checked !== 'true') {
            setFeedback(nickFeedback, '닉네임 중복확인을 해주세요.', 'err');
            nickInput.focus();
            return;
        }

        // 비밀번호는 입력됐을 때만 검증 (규칙: 백엔드 PasswordPolicy와 동일)
        const pw = pwInput ? pwInput.value : '';
        if (pw !== '') {
            if (pw.length < PW.min) { MubloRequest.showAlert('비밀번호는 최소 ' + PW.min + '자 이상이어야 합니다.', 'warning'); pwInput.focus(); return; }
            if (PW.number && !/[0-9]/.test(pw)) { MubloRequest.showAlert('비밀번호에 숫자를 포함해야 합니다.', 'warning'); pwInput.focus(); return; }
            if (PW.special && !/[^\p{L}\p{N}\s]/u.test(pw)) { MubloRequest.showAlert('비밀번호에 특수문자를 포함해야 합니다.', 'warning'); pwInput.focus(); return; }
            if (pw !== (pwConfirm ? pwConfirm.value : '')) { MubloRequest.showAlert('새 비밀번호가 일치하지 않습니다.', 'warning'); pwConfirm.focus(); return; }
        }

        btnSave.disabled = true;
        MubloRequest.sendRequest({
            method: 'POST',
            url: '/admin/profile/update',
            payloadType: MubloRequest.PayloadType.FORM,
            data: new FormData(form),
            loading: true
        }).then(function (res) {
            btnSave.disabled = false;
            if (res && res.result === 'success') {
                MubloRequest.showToast(res.message || '저장되었습니다.', 'success');
                if (nickInput) { nickInput.dataset.original = nickInput.value.trim(); nickInput.dataset.checked = 'false'; nickInput.classList.remove('is-valid'); }
                if (pwInput) pwInput.value = '';
                if (pwConfirm) pwConfirm.value = '';
                setFeedback(nickFeedback, '2~20자로 입력', 'muted');
            }
        }).catch(function () {
            // 검증/서버 오류 알림은 sendRequest(handleError)가 실제 메시지로 자동 표시 → 여기선 상태만 복구
            btnSave.disabled = false;
        });
    });
})();
</script>
