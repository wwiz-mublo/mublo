<?php
/**
 * Mublo Framework Installer
 *
 * 웹 기반 설치 마법사
 */

// opcache 강제 무효화 (수정된 파일이 즉시 반영되도록)
if (function_exists('opcache_invalidate')) {
    $basePath = dirname(dirname(__DIR__)) . '/src/Core/Install/';
    opcache_invalidate($basePath . 'DatabaseConfigWriter.php', true);
    opcache_invalidate($basePath . 'Installer.php', true);
    opcache_invalidate(__FILE__, true);
    foreach (glob(__DIR__ . '/steps/*.php') as $f) {
        opcache_invalidate($f, true);
    }
}

// 웹 루트 경로 자동 감지 (직접 접근 시 index.php를 거치지 않으므로 여기서 정의)
if (!defined('MUBLO_PUBLIC_PATH')) {
    define('MUBLO_PUBLIC_PATH', dirname(__DIR__));
}

// Bootstrap 로드
require_once __DIR__ . '/../../bootstrap.php';

use Mublo\Core\Install\Installer;
use Mublo\Core\Install\LicenseAgreement;

$installer = new Installer();
$installerStylePath = __DIR__ . '/assets/style.css';
$installerStyleVersion = is_file($installerStylePath)
    ? (string) filemtime($installerStylePath)
    : '1';

// 이미 설치되었는지 확인
if ($installer->isInstalled()) {
    die('
    <!DOCTYPE html>
    <html lang="ko">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>이미 설치됨 - Mublo Framework</title>
        <link rel="stylesheet" href="./assets/style.css?v=' . rawurlencode($installerStyleVersion) . '">
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h1>Mublo Framework</h1>
                <p>설치가 이미 완료되었습니다</p>
            </div>
            <div class="content">
                <div class="alert alert-error">
                    <strong>보안 경고</strong><br>
                    설치가 이미 완료되었습니다. 보안을 위해 <code>public/install</code> 디렉토리를 삭제하세요.
                </div>
                <div class="button-group">
                    <a href="/" class="btn btn-primary">메인 페이지로 이동</a>
                </div>
            </div>
            <div class="footer">
                Mublo Framework v1.0.0 | &copy; 2026 All rights reserved
            </div>
        </div>
    </body>
    </html>
    ');
}

// 현재 단계 확인
$step = $_GET['step'] ?? '1';

// 보안 강화: step 파라미터는 반드시 숫자여야 함 (경로 탐색 방지)
if (!ctype_digit((string)$step)) {
    $step = '1';
}

// 세션 시작 (데이터 유지용)
session_start();

// 라이선스 동의 전에는 어떤 설치 단계도 실행하지 않는다.
$licenseAgreement = new LicenseAgreement();
$licenseError = null;
$licenseToken = $licenseAgreement->issueToken($_SESSION);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'accept_license') {
    $agreed = ($_POST['license_agree'] ?? '') === '1';
    $accepted = $licenseAgreement->accept(
        $_SESSION,
        (string) ($_POST['license_token'] ?? ''),
        $agreed
    );

    if ($accepted) {
        session_regenerate_id(true);
        header('Location: ?step=1');
        exit;
    }

    $licenseError = $agreed
        ? '요청이 만료되었거나 유효하지 않습니다. 다시 시도하세요.'
        : '라이선스에 동의해야 설치를 시작할 수 있습니다.';
}

$licenseAccepted = $licenseAgreement->isAccepted($_SESSION);

if (!$licenseAccepted) {
    $licenseText = $licenseAgreement->licenseText();

    ob_start();
    include __DIR__ . '/steps/license.php';
    $stepContent = ob_get_clean();
}

// 전체 초기화 요청 처리
if ($licenseAccepted && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset') {
    $dbConfig = $_SESSION['db_config'] ?? null;
    $result = $installer->resetInstallation($dbConfig);

    if ($result['success']) {
        // 세션이 초기화되었으므로 step1로 리다이렉트
        header('Location: ?step=1');
        exit;
    }

    // 실패 시 에러 메시지를 세션에 저장
    $_SESSION['reset_error'] = $result['message'];
    header('Location: ?step=' . $step);
    exit;
}

if ($licenseAccepted) {
    // AJAX 요청 처리를 위해 step 파일 먼저 체크
    $stepFile = __DIR__ . "/steps/step{$step}.php";
    if (!file_exists($stepFile)) {
        die('잘못된 단계입니다.');
    }

    // Step 파일을 버퍼에 캡처 (AJAX 요청이면 여기서 exit됨)
    ob_start();
    include $stepFile;
    $stepContent = ob_get_clean();
}

?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mublo Framework 설치</title>
    <link rel="stylesheet" href="./assets/style.css?v=<?= rawurlencode($installerStyleVersion) ?>">
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Mublo Framework</h1>
            <p>설치 마법사</p>
        </div>

        <?php if ($licenseAccepted): ?>
        <div class="progress-bar">
            <div class="progress-step <?= $step >= 1 ? 'active' : '' ?> <?= $step > 1 ? 'completed' : '' ?>" data-step="1">
                <span>환경 체크</span>
            </div>
            <div class="progress-step <?= $step >= 2 ? 'active' : '' ?> <?= $step > 2 ? 'completed' : '' ?>" data-step="2">
                <span>데이터베이스</span>
            </div>
            <div class="progress-step <?= $step >= 3 ? 'active' : '' ?> <?= $step > 3 ? 'completed' : '' ?>" data-step="3">
                <span>도메인 설정</span>
            </div>
            <div class="progress-step <?= $step >= 4 ? 'active' : '' ?> <?= $step > 4 ? 'completed' : '' ?>" data-step="4">
                <span>보안 설정</span>
            </div>
            <div class="progress-step <?= $step >= 5 ? 'active' : '' ?> <?= $step > 5 ? 'completed' : '' ?>" data-step="5">
                <span>관리자 계정</span>
            </div>
            <div class="progress-step <?= $step >= 6 ? 'active' : '' ?> <?= $step > 6 ? 'completed' : '' ?>" data-step="6">
                <span>설치 완료</span>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($licenseAccepted && isset($_SESSION['reset_error'])): ?>
        <div class="alert alert-error" style="margin: 12px 40px 0;">
            <strong>초기화 실패:</strong> <?= htmlspecialchars($_SESSION['reset_error']) ?>
        </div>
        <?php unset($_SESSION['reset_error']); endif; ?>

        <?php if ($licenseAccepted && (int)$step >= 2 && (int)$step < 6): ?>
        <div class="reset-bar">
            <form method="POST" onsubmit="return confirm('모든 설치 데이터(DB 테이블, 설정 파일)가 삭제됩니다.\n정말 초기화하시겠습니까?');">
                <input type="hidden" name="action" value="reset">
                <button type="submit" class="btn btn-danger">처음부터 다시 설치</button>
            </form>
        </div>
        <?php endif; ?>

        <?php
        // 단계별 페이지 출력 (이미 위에서 캡처됨)
        echo $stepContent;
        ?>

        <div class="footer">
            Mublo Framework v1.0.0 | &copy; 2026 All rights reserved
        </div>
    </div>
</body>
</html>
