<?php
/**
 * Step 4: 보안 설정
 * (관리자 계정 생성 전에 해시 비용 설정 필요)
 */

// 이전 단계 완료 확인
if (!isset($_SESSION['db_config']) || !isset($_SESSION['domain_data'])) {
    header('Location: ?step=3');
    exit;
}

$error = '';

// 기본값 생성 (랜덤)
$defaultCsrfKey = bin2hex(random_bytes(32));

// POST 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $securityData = [
        'password_hash_cost' => (int) ($_POST['password_hash_cost'] ?? 12),
        'csrf_token_key' => trim($_POST['csrf_token_key'] ?? ''),
        'csrf_token_ttl' => (int) ($_POST['csrf_token_ttl'] ?? 3600),
    ];

    // 유효성 검사
    if (empty($securityData['csrf_token_key'])) {
        $error = '파일 다운로드 서명 키를 입력하세요.';
    } elseif (strlen($securityData['csrf_token_key']) < 32) {
        $error = '파일 다운로드 서명 키는 최소 32자 이상이어야 합니다.';
    } elseif ($securityData['password_hash_cost'] < 10 || $securityData['password_hash_cost'] > 14) {
        $error = '비밀번호 해시 비용은 10~14 사이여야 합니다.';
    } else {
        // 세션에 담기만 한다. 설정 파일 생성은 마지막 설치 단계에서 수행한다.
        $_SESSION['security_data'] = $securityData;

        header('Location: ?step=5');
        exit;
    }
}

// 세션에 이미 값이 있으면 사용
$csrfKey = $_SESSION['security_data']['csrf_token_key'] ?? $defaultCsrfKey;
$hashCost = $_SESSION['security_data']['password_hash_cost'] ?? 12;
$csrfTtl = $_SESSION['security_data']['csrf_token_ttl'] ?? 3600;
?>

<div class="content">
    <h2>Step 4. 보안 설정</h2>
    <p>보안 관련 설정을 구성합니다. 회원 정보 암호화에 쓰이는 키는 안전한 랜덤 값으로 자동 생성됩니다.</p>

    <?php if ($error): ?>
    <div class="alert alert-error">
        <strong>오류:</strong> <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <form method="POST">
        <h3>비밀번호 해싱 설정</h3>

        <div class="form-group">
            <label>해시 비용 (Hash Cost) *</label>
            <select name="password_hash_cost" required>
                <option value="10" <?= $hashCost == 10 ? 'selected' : '' ?>>10 (빠름, 보안 낮음)</option>
                <option value="11" <?= $hashCost == 11 ? 'selected' : '' ?>>11</option>
                <option value="12" <?= $hashCost == 12 ? 'selected' : '' ?>>12 (권장)</option>
                <option value="13" <?= $hashCost == 13 ? 'selected' : '' ?>>13</option>
                <option value="14" <?= $hashCost == 14 ? 'selected' : '' ?>>14 (느림, 보안 높음)</option>
            </select>
            <small>값이 높을수록 보안이 강화되지만 로그인 속도가 느려집니다</small>
        </div>

        <h3>파일 다운로드 서명 키</h3>

        <div class="form-group">
            <label>서명 키 *</label>
            <div class="input-action">
                <input type="text" name="csrf_token_key" id="signingKey"
                       value="<?= htmlspecialchars($csrfKey) ?>"
                       required minlength="32"
                       style="font-family: 'SF Mono', SFMono-Regular, Consolas, monospace; font-size: 12px;">
                <button type="button" id="regenSigningKey" class="btn btn-accent">재생성</button>
            </div>
            <small>
                첨부파일 다운로드 링크의 위·변조를 막는 서명에 사용됩니다. 최소 32자.<br>
                <strong>설치 후 이 값을 바꾸면 이미 발급된 다운로드 링크가 모두 무효가 됩니다.</strong>
            </small>
        </div>

        <h3>CSRF 토큰 설정</h3>

        <div class="form-group">
            <label>유휴 허용 시간 (초) *</label>
            <select name="csrf_token_ttl" required>
                <option value="0" <?= $csrfTtl == 0 ? 'selected' : '' ?>>제한 없음 (세션 수명만 적용)</option>
                <option value="1800" <?= $csrfTtl == 1800 ? 'selected' : '' ?>>30분 (1800초)</option>
                <option value="3600" <?= $csrfTtl == 3600 ? 'selected' : '' ?>>1시간 (3600초, 권장)</option>
                <option value="7200" <?= $csrfTtl == 7200 ? 'selected' : '' ?>>2시간 (7200초)</option>
            </select>
            <small>
                마지막 활동 이후 이 시간이 지나면 토큰이 만료됩니다. 사용 중에는 시간이 다시
                시작되므로 작업 도중 끊기지 않습니다. 세션 수명(기본 120분)보다 긴 값은 의미가 없습니다.
            </small>
        </div>

        <div class="alert alert-info">
            <strong>보안 참고사항:</strong><br>
            * 이 설정과 자동 생성된 암호화 키는 <code>config/security.php</code>에 저장됩니다<br>
            * 설치가 끝나면 이 파일을 안전한 곳에 백업하세요. 파일을 잃어버리면 암호화된 회원 정보를 되살릴 수 없습니다<br>
            * 다운로드 서명 키를 나중에 바꾸면 이미 발급된 다운로드 링크가 무효가 됩니다
        </div>

        <div class="button-group">
            <button type="button" class="btn btn-secondary" onclick="location.href='?step=3'">
                이전 단계
            </button>
            <button type="submit" class="btn btn-primary">
                다음 단계
            </button>
        </div>
    </form>
</div>

<script>
// 서명 키 재생성 — 설치 단계에서는 아직 발급된 다운로드 토큰이 없으므로 안전하다.
// 서버 왕복 없이 브라우저의 CSPRNG 로 만든다.
document.getElementById('regenSigningKey')?.addEventListener('click', function () {
    var bytes = new Uint8Array(32);
    crypto.getRandomValues(bytes);
    document.getElementById('signingKey').value =
        Array.from(bytes, function (b) { return b.toString(16).padStart(2, '0'); }).join('');
});
</script>
