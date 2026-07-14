<?php
/**
 * Step 3: 도메인 설정
 * (Step 4에서 이동 - 관리자 생성 전에 도메인이 필요함)
 */

// DB 설정이 없으면 이전 단계로
if (!isset($_SESSION['db_config'])) {
    header('Location: ?step=2');
    exit;
}

$error = '';
$success = '';

// 번들 시작 킷 목록 (선택 UI + slug 검증)
$starterKits = $installer->getStarterKits();

// POST 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $domainData = [
        'domain_name' => trim($_POST['domain_name'] ?? ''),
        'site_title' => trim($_POST['site_title'] ?? ''),
        'site_subtitle' => trim($_POST['site_subtitle'] ?? ''),
        'timezone' => $_POST['timezone'] ?? 'Asia/Seoul',
        'admin_email' => trim($_POST['admin_email'] ?? ''),
    ];

    // 시작 킷 선택 ('default' 또는 매니페스트의 slug 만 허용)
    $starterKit = $_POST['starter_kit'] ?? 'default';
    if ($starterKit === 'default' || !isset($starterKits[$starterKit])) {
        $starterKit = null;
    }

    // 유효성 검사
    if (empty($domainData['domain_name'])) {
        $error = '도메인명을 입력하세요.';
    } elseif (empty($domainData['site_title'])) {
        $error = '사이트 제목을 입력하세요.';
    } else {
        // 도메인 설정 (domain_id = 1 생성) — 시작 킷의 레이아웃/필요 확장 반영
        $result = $installer->setupDomain($_SESSION['db_config'], $domainData, null, $starterKit);

        if (!$result['success']) {
            $error = $result['message'];
        } else {
            // 시더 실행 (도메인 생성 후 초기 데이터 삽입)
            $seederResult = $installer->runSeeders($_SESSION['db_config']);
            if (!$seederResult['success']) {
                $error = $seederResult['message'];
            } else {
                // 블록 시더 실행 (시작 킷 선택 시 킷 골격, 아니면 기본 환영 블록)
                $domainId = (int) ($result['domain_id'] ?? 1);
                $installer->runBlockSeeder($_SESSION['db_config'], $domainId, $starterKit);

                // 세션에 저장
                $_SESSION['domain_data'] = $domainData;
                $_SESSION['domain_result'] = $result;
                $_SESSION['starter_kit'] = $starterKit;

                // 다음 단계로 (관리자 생성)
                header('Location: ?step=4');
                exit;
            }
        }
    }
}

// 현재 도메인 자동 감지
$currentDomain = $_SERVER['HTTP_HOST'] ?? 'localhost';
?>

<div class="content">
    <h2>Step 3. 도메인 설정</h2>
    <p>사이트의 기본 도메인 및 사이트 정보를 설정합니다.</p>

    <?php if ($error): ?>
    <div class="alert alert-error">
        <strong>오류:</strong> <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <form method="POST">
        <div class="form-group">
            <label>도메인명 *</label>
            <input type="text" name="domain_name"
                   value="<?= htmlspecialchars($_POST['domain_name'] ?? $currentDomain) ?>"
                   required placeholder="example.com">
            <small>현재 접속 중인 도메인: <?= htmlspecialchars($currentDomain) ?></small>
        </div>

        <div class="form-group">
            <label>사이트 제목 *</label>
            <input type="text" name="site_title"
                   value="<?= htmlspecialchars($_POST['site_title'] ?? '') ?>"
                   required placeholder="우리 사이트">
            <small>브라우저 탭 및 검색엔진에 표시될 제목</small>
        </div>

        <div class="form-group">
            <label>사이트 부제목</label>
            <input type="text" name="site_subtitle"
                   value="<?= htmlspecialchars($_POST['site_subtitle'] ?? '') ?>"
                   placeholder="함께 만들어가는 커뮤니티">
            <small>선택사항: 사이트 설명 또는 슬로건</small>
        </div>

        <div class="two-column">
            <div class="form-group">
                <label>관리자 이메일</label>
                <input type="email" name="admin_email"
                       value="<?= htmlspecialchars($_POST['admin_email'] ?? '') ?>"
                       placeholder="admin@example.com">
                <small>선택사항: 시스템 알림 수신용</small>
            </div>

            <div class="form-group">
                <label>타임존 *</label>
                <select name="timezone" required>
                    <option value="Asia/Seoul" selected>한국 (Asia/Seoul)</option>
                    <option value="Asia/Tokyo">일본 (Asia/Tokyo)</option>
                    <option value="Asia/Shanghai">중국 (Asia/Shanghai)</option>
                    <option value="America/New_York">미국 동부</option>
                    <option value="America/Los_Angeles">미국 서부</option>
                    <option value="Europe/London">영국 (London)</option>
                    <option value="UTC">UTC</option>
                </select>
            </div>
        </div>

        <?php if (!empty($starterKits)): ?>
        <div class="form-group">
            <label>시작 킷 (메인화면 골격)</label>
            <small style="display:block;margin:0 0 10px;">사이트 성격에 맞는 메인화면 골격을 골라 시작하세요. 설치 후 블록관리에서 자유롭게 바꿀 수 있습니다.</small>

            <div class="kit-grid">
                <label class="kit-card">
                    <input type="radio" name="starter_kit" value="default"
                           <?= ($_POST['starter_kit'] ?? 'default') === 'default' ? 'checked' : '' ?>>
                    <strong>기본 (미니멀)</strong>
                    <span class="kit-summary">환영 블록만 있는 빈 캔버스에서 직접 시작</span>
                </label>

                <?php foreach ($starterKits as $slug => $kitMeta): ?>
                <label class="kit-card">
                    <input type="radio" name="starter_kit" value="<?= htmlspecialchars($slug) ?>"
                           <?= ($_POST['starter_kit'] ?? '') === $slug ? 'checked' : '' ?>>
                    <strong><?= htmlspecialchars($kitMeta['name']) ?></strong>
                    <?php if (!empty($kitMeta['preview'])): ?>
                    <img src="<?= htmlspecialchars($kitMeta['preview']) ?>" alt="">
                    <?php endif; ?>
                    <span class="kit-summary"><?= htmlspecialchars($kitMeta['summary']) ?></span>
                </label>
                <?php endforeach; ?>
            </div>
            <small style="display:block;margin-top:8px;">킷을 선택하면 필요한 확장(게시판 등)이 자동 활성화되고, 첫 접속 시 설치가 마무리됩니다. 게시판 연결 방법은 적용 후 메인 상단 안내에 표시됩니다.</small>
        </div>
        <?php endif; ?>

        <div class="alert alert-info">
            <strong>참고사항:</strong><br>
            * 도메인은 설치 후 관리자 페이지에서 추가/수정할 수 있습니다<br>
            * 타임존은 서버 위치가 아닌 서비스 지역 기준으로 설정하세요
        </div>

        <div class="button-group">
            <button type="button" class="btn btn-secondary" onclick="location.href='?step=2'">
                이전 단계
            </button>
            <button type="submit" class="btn btn-primary">
                다음 단계
            </button>
        </div>
    </form>
</div>
