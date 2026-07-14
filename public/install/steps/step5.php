<?php
/**
 * Step 5: 관리자 계정 생성
 * (보안 설정 후 - 설정된 해시 비용 사용)
 */

// DB 설정, 도메인, 보안 설정이 없으면 이전 단계로
if (!isset($_SESSION['db_config']) || !isset($_SESSION['domain_data']) || !isset($_SESSION['security_data'])) {
    header('Location: ?step=4');
    exit;
}

$error = '';
$success = '';

// POST 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $adminData = [
        'user_id' => trim($_POST['admin_id'] ?? ''),
        'password' => $_POST['admin_password'] ?? '',
        'password_confirm' => $_POST['admin_password_confirm'] ?? '',
    ];

    // 유효성 검사
    if (empty($adminData['user_id'])) {
        $error = '관리자 아이디를 입력하세요.';
    } elseif (strlen($adminData['user_id']) < 4) {
        $error = '관리자 아이디는 최소 4자 이상이어야 합니다.';
    } elseif (empty($adminData['password'])) {
        $error = '비밀번호를 입력하세요.';
    } elseif (strlen($adminData['password']) < 8) {
        $error = '비밀번호는 최소 8자 이상이어야 합니다.';
    } elseif ($adminData['password'] !== $adminData['password_confirm']) {
        $error = '비밀번호가 일치하지 않습니다.';
    } else {
        // 관리자 생성 (보안 설정의 해시 비용 전달)
        $result = $installer->createAdmin(
            $_SESSION['db_config'],
            $adminData,
            $_SESSION['security_data']['password_hash_cost']
        );

        if (!$result['success']) {
            $error = $result['message'];
        } else {
            // 기본 도메인(domain_id=1)의 소유자를 최고관리자로 설정
            $ownerResult = $installer->updateDomainOwner(
                $_SESSION['db_config'],
                $result['member_id']
            );

            if (!$ownerResult['success']) {
                // 소유자 설정 실패해도 설치는 진행 (경고만)
                error_log('Domain owner update failed: ' . $ownerResult['message']);
            }

            // 세션에 저장
            $_SESSION['admin_data'] = $adminData;
            $_SESSION['admin_result'] = $result;

            // 다음 단계로 (설치 완료)
            header('Location: ?step=6');
            exit;
        }
    }
}

$hashCost = $_SESSION['security_data']['password_hash_cost'] ?? 12;
?>

<div class="content">
    <h2>Step 5. 관리자 계정 생성</h2>
    <p>사이트를 관리할 최고관리자 계정을 생성합니다.</p>

    <?php if ($error): ?>
    <div class="alert alert-error">
        <strong>오류:</strong> <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <form method="POST">
        <div class="form-group">
            <label>관리자 아이디 *</label>
            <input type="text" name="admin_id" value="<?= htmlspecialchars($_POST['admin_id'] ?? '') ?>"
                   required minlength="4" maxlength="20" pattern="[a-zA-Z0-9_]+"
                   placeholder="admin">
            <small>영문, 숫자, 언더스코어(_)만 사용 가능, 최소 4자</small>
        </div>

        <div class="two-column">
            <div class="form-group">
                <label>비밀번호 *</label>
                <input type="password" name="admin_password" required minlength="8">
                <small>최소 8자 이상</small>
            </div>

            <div class="form-group">
                <label>비밀번호 확인 *</label>
                <input type="password" name="admin_password_confirm" required minlength="8">
            </div>
        </div>

        <div class="alert alert-info">
            <strong>보안 팁:</strong><br>
            * 추측하기 어려운 강력한 비밀번호를 사용하세요<br>
            * 영문 대소문자, 숫자, 특수문자를 조합하면 더 안전합니다<br>
            * 관리자 아이디는 'admin'보다는 고유한 이름을 사용하세요<br>
            * 비밀번호 해시 비용: <?= $hashCost ?> (이전 단계에서 설정됨)
        </div>

        <div class="button-group">
            <button type="button" class="btn btn-secondary" onclick="location.href='?step=4'">
                이전 단계
            </button>
            <button type="submit" class="btn btn-primary">
                설치 완료
            </button>
        </div>
    </form>
</div>
