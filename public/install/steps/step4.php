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
$defaultEncryptKey = bin2hex(random_bytes(32));
$defaultCsrfKey = bin2hex(random_bytes(32));

// POST 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $securityData = [
        'encrypt_key' => trim($_POST['encrypt_key'] ?? ''),
        'password_hash_cost' => (int) ($_POST['password_hash_cost'] ?? 12),
        'csrf_token_key' => trim($_POST['csrf_token_key'] ?? ''),
        'csrf_token_ttl' => (int) ($_POST['csrf_token_ttl'] ?? 3600),
    ];

    // 유효성 검사
    if (empty($securityData['encrypt_key'])) {
        $error = '암호화 키를 입력하세요.';
    } elseif (strlen($securityData['encrypt_key']) < 32) {
        $error = '암호화 키는 최소 32자 이상이어야 합니다.';
    } elseif (empty($securityData['csrf_token_key'])) {
        $error = 'CSRF 토큰 키를 입력하세요.';
    } elseif (strlen($securityData['csrf_token_key']) < 32) {
        $error = 'CSRF 토큰 키는 최소 32자 이상이어야 합니다.';
    } elseif ($securityData['password_hash_cost'] < 10 || $securityData['password_hash_cost'] > 14) {
        $error = '비밀번호 해시 비용은 10~14 사이여야 합니다.';
    } else {
        // 세션에 저장
        $_SESSION['security_data'] = $securityData;

        // 설정 파일 생성 (app.php, security.php, mail.php, ai.php)
        $adminEmail = $_SESSION['domain_data']['admin_email'] ?? '';
        $result = $installer->generateSecurityConfigWithData($securityData, $adminEmail);

        if (!$result) {
            $error = '설정 파일 생성 실패';
        } else {
            // 다음 단계로 (관리자 계정)
            header('Location: ?step=5');
            exit;
        }
    }
}

// 세션에 이미 값이 있으면 사용
$encryptKey = $_SESSION['security_data']['encrypt_key'] ?? $defaultEncryptKey;
$csrfKey = $_SESSION['security_data']['csrf_token_key'] ?? $defaultCsrfKey;
$hashCost = $_SESSION['security_data']['password_hash_cost'] ?? 12;
$csrfTtl = $_SESSION['security_data']['csrf_token_ttl'] ?? 3600;
?>

<div class="content">
    <h2>Step 4. 보안 설정</h2>
    <p>암호화 키 및 보안 관련 설정을 구성합니다. 기본값은 안전한 랜덤 값으로 생성되어 있습니다.</p>

    <?php if ($error): ?>
    <div class="alert alert-error">
        <strong>오류:</strong> <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <form method="POST">
        <h3>암호화 설정</h3>

        <div class="form-group">
            <label>암호화 키 (Encrypt Key) *</label>
            <input type="text" name="encrypt_key"
                   value="<?= htmlspecialchars($encryptKey) ?>"
                   required minlength="32"
                   style="font-family: 'SF Mono', SFMono-Regular, Consolas, monospace; font-size: 12px;">
            <small>쿠키, 토큰, 민감 정보 암호화에 사용됩니다. 최소 32자 (64자 권장)</small>
        </div>

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

        <h3>CSRF 토큰 설정</h3>

        <div class="form-group">
            <label>CSRF 토큰 키 *</label>
            <input type="text" name="csrf_token_key"
                   value="<?= htmlspecialchars($csrfKey) ?>"
                   required minlength="32"
                   style="font-family: 'SF Mono', SFMono-Regular, Consolas, monospace; font-size: 12px;">
            <small>CSRF 공격 방지용 토큰 생성에 사용됩니다. 최소 32자</small>
        </div>

        <div class="form-group">
            <label>토큰 유효시간 (초) *</label>
            <select name="csrf_token_ttl" required>
                <option value="1800" <?= $csrfTtl == 1800 ? 'selected' : '' ?>>30분 (1800초)</option>
                <option value="3600" <?= $csrfTtl == 3600 ? 'selected' : '' ?>>1시간 (3600초, 권장)</option>
                <option value="7200" <?= $csrfTtl == 7200 ? 'selected' : '' ?>>2시간 (7200초)</option>
                <option value="86400" <?= $csrfTtl == 86400 ? 'selected' : '' ?>>24시간 (86400초)</option>
            </select>
            <small>CSRF 토큰이 유효한 시간입니다</small>
        </div>

        <div class="alert alert-info">
            <strong>보안 참고사항:</strong><br>
            * 암호화 키와 CSRF 키는 설치 후 변경하면 기존 세션이 무효화됩니다<br>
            * 이 값들은 config/app.php와 config/security.php에 저장됩니다<br>
            * 프로덕션 환경에서는 이 값들을 안전하게 백업해 두세요
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
