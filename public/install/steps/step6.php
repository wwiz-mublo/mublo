<?php
/**
 * Step 6: 설치 완료
 */

// 모든 설정이 완료되었는지 확인
if (!isset($_SESSION['db_config']) || !isset($_SESSION['domain_data']) || !isset($_SESSION['admin_data']) || !isset($_SESSION['security_data'])) {
    header('Location: ?step=5');
    exit;
}

// 설치 완료 처리
if (!$installer->isInstalled()) {
    $installer->finishInstallation();
}

$dbConfig = $_SESSION['db_config'];
$adminData = $_SESSION['admin_data'];
$domainData = $_SESSION['domain_data'];
$securityData = $_SESSION['security_data'];
$migrationResult = $_SESSION['migration_result'] ?? ['files' => []];

// 선택한 시작 킷 (없으면 기본)
$starterKitSlug = $_SESSION['starter_kit'] ?? null;
$starterKitName = null;
if ($starterKitSlug !== null) {
    $starterKitName = $installer->getStarterKits()[$starterKitSlug]['name'] ?? null;
}
?>

<div class="content">
    <h2>Step 6. 설치 완료</h2>
    <p>Mublo Framework 설치가 성공적으로 완료되었습니다.</p>

    <div class="alert alert-success">
        <strong>설치 성공!</strong><br>
        모든 설정이 완료되었습니다. 이제 사이트를 사용할 수 있습니다.
    </div>

    <h3>설치 요약</h3>

    <ul class="check-list">
        <li class="check-item ok">
            <div class="icon">V</div>
            <div class="info">
                <strong>데이터베이스 설정</strong>
                <span><?= htmlspecialchars($dbConfig['database']) ?> @ <?= htmlspecialchars($dbConfig['host']) ?></span>
            </div>
        </li>

        <li class="check-item ok">
            <div class="icon">V</div>
            <div class="info">
                <strong>테이블 생성</strong>
                <span><?= count($migrationResult['files']) ?>개 마이그레이션 파일 실행 완료</span>
            </div>
        </li>

        <li class="check-item ok">
            <div class="icon">V</div>
            <div class="info">
                <strong>도메인 설정</strong>
                <span><?= htmlspecialchars($domainData['domain_name']) ?> - <?= htmlspecialchars($domainData['site_title']) ?></span>
            </div>
        </li>

        <li class="check-item ok">
            <div class="icon">V</div>
            <div class="info">
                <strong>관리자 계정</strong>
                <span><?= htmlspecialchars($adminData['user_id']) ?></span>
            </div>
        </li>

        <li class="check-item ok">
            <div class="icon">V</div>
            <div class="info">
                <strong>보안 설정</strong>
                <span>암호화 키, CSRF 토큰, 해시 비용 설정 완료</span>
            </div>
        </li>

        <li class="check-item ok">
            <div class="icon">V</div>
            <div class="info">
                <strong>설정 파일 생성</strong>
                <span>config/database.php, config/app.php, config/security.php</span>
            </div>
        </li>

        <?php if ($starterKitName !== null): ?>
        <li class="check-item ok">
            <div class="icon">V</div>
            <div class="info">
                <strong>시작 킷</strong>
                <span><?= htmlspecialchars($starterKitName) ?> 적용됨 — 첫 접속 시 필요한 확장(게시판 등)이 자동 설치되고, 메인 상단 안내를 따라 게시판을 연결하면 완성됩니다</span>
            </div>
        </li>
        <?php else: ?>
        <li class="check-item ok">
            <div class="icon">V</div>
            <div class="info">
                <strong>미니멀 시작</strong>
                <span>빈 골격으로 시작했습니다 — 관리자의 확장관리에서 게시판·쇼핑몰·FAQ 등 원하는 기능을 켜고, 블록관리로 메인 페이지를 꾸미세요</span>
            </div>
        </li>
        <?php endif; ?>

        <li class="check-item ok">
            <div class="icon">V</div>
            <div class="info">
                <strong>설치 완료</strong>
                <span>storage/installed.lock 생성됨</span>
            </div>
        </li>
    </ul>

    <h3>보안 조치 (필수)</h3>

    <div class="alert alert-warning">
        <strong>중요: 보안을 위해 다음 작업을 수행하세요</strong><br><br>

        <strong>1. 설치 디렉토리 삭제 (필수)</strong><br>
        설치가 완료되었으므로 <code>public/install</code> 디렉토리를 삭제하세요.<br>
        <pre>
# 윈도우 (명령 프롬프트)
rmdir /s /q public\install

# 리눅스/맥 (터미널)
rm -rf public/install</pre>

        <strong>2. 설정 파일 권한 확인 (권장)</strong><br>
        설치기는 설정 파일을 소유자만 읽고 쓸 수 있는 <code>600</code>으로 설정합니다.
        서버에서 권한 변경이 지원되지 않았거나 배포 과정에서 달라졌다면 아래처럼 다시 설정하세요.<br>
        <pre>
# PHP가 파일 소유자와 같은 사용자로 실행되는 경우
chmod 600 config/database.php config/app.php config/security.php config/mail.php
chmod 600 storage/installed.lock

# PHP가 파일 소유자의 그룹 권한으로 읽는 서버는 640 사용
# chgrp www-data config/*.php storage/installed.lock
# chmod 640 config/database.php config/app.php config/security.php config/mail.php
# chmod 640 storage/installed.lock</pre>
        <code>config</code> 디렉토리 자체는 <code>444</code>로 변경하지 마세요.
        <code>storage</code>와 <code>public/storage</code>는 설치 후에도 쓰기 권한이 필요합니다.<br>
        설치를 위해 <code>707</code>을 사용했다면 <code>config</code>만 <code>755</code>로 반드시 되돌리세요.
        <code>storage</code>와 <code>public/storage</code>는 운영 중에도 쓰기가 필요하므로 <code>707</code>을 유지합니다.<br>
        Windows/IIS에서는 숫자 퍼미션 대신 파일 및 폴더의 ACL에서 PHP 실행 계정 권한을 확인하세요.
    </div>

    <h3>다음 단계</h3>

    <div class="alert alert-info">
        <strong>이제 무엇을 할 수 있나요?</strong><br><br>

        <strong>1. 관리자 페이지 접속</strong><br>
        - URL: <code><?= htmlspecialchars($_SERVER['REQUEST_SCHEME'] ?? 'http') ?>://<?= htmlspecialchars($_SERVER['HTTP_HOST']) ?>/admin</code><br>
        - 아이디: <code><?= htmlspecialchars($adminData['user_id']) ?></code><br>
        - 비밀번호: 설정한 비밀번호<br><br>

        <?php if ($starterKitName !== null): ?>
        <strong>2. 게시판 사용</strong><br>
        - 공지사항(<code>/board/notice</code>), 자유게시판(<code>/board/free</code>) 기본 제공<br>
        - 관리자 페이지에서 게시판 추가/수정/삭제 가능<br><br>
        <?php else: ?>
        <strong>2. 기능 켜기</strong><br>
        - 관리자 &gt; 확장관리에서 게시판·쇼핑몰·FAQ 등 원하는 패키지·플러그인을 활성화<br>
        - 활성화하면 관리자 메뉴에 해당 기능이 추가됩니다<br><br>
        <?php endif; ?>

        <strong>3. 사이트 설정</strong><br>
        - 메뉴 구성, 회원 등급 설정 등<br>
        - 플러그인 설치 및 패키지 추가<br>
        - 스킨 및 템플릿 커스터마이징<br><br>

        <strong>4. 문서 참고</strong><br>
        - 프레임워크 문서: <code>docs/</code> 디렉토리 참고<br>
        - 개발자 가이드, API 레퍼런스 등 제공
    </div>

    <div class="button-group">
        <a href="/" class="btn btn-secondary">메인 페이지</a>
        <a href="/admin" class="btn btn-success">관리자 페이지</a>
    </div>
</div>

<script>
// 세션 정리 (보안)
<?php
// 민감한 정보 제거
unset($_SESSION['db_config']['password']);
unset($_SESSION['admin_data']['password']);
unset($_SESSION['admin_data']['password_confirm']);
unset($_SESSION['security_data']);
?>
</script>
