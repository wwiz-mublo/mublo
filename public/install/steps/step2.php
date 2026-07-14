<?php
/**
 * Step 2: 데이터베이스 설정
 */

$error = '';
$success = '';

// POST 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dbConfig = [
        'host' => $_POST['db_host'] ?? 'localhost',
        'port' => $_POST['db_port'] ?? 3306,
        'database' => $_POST['db_database'] ?? '',
        'username' => $_POST['db_username'] ?? '',
        'password' => $_POST['db_password'] ?? '',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
    ];

    // AJAX 연결 테스트
    if (isset($_POST['action']) && $_POST['action'] === 'test') {
        header('Content-Type: application/json');
        try {
            $result = $installer->testDatabaseConnection($dbConfig);
            echo json_encode($result);
        } catch (\Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => '연결 테스트 중 오류 발생: ' . $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }
        exit;
    }

    // DB 설정 저장 및 마이그레이션
    if (isset($_POST['action']) && $_POST['action'] === 'save') {
        // 연결 테스트 버튼을 누르지 않아도 지원 버전과 접속 가능 여부를 먼저 검증한다.
        $connectionResult = $installer->testDatabaseConnection($dbConfig);
        if (!$connectionResult['success']) {
            $error = $connectionResult['message'];
        } elseif (!$installer->saveDatabaseConfig($dbConfig)) {
            // DB 설정 파일 생성
            $error = 'config/database.php 파일 생성 실패';
        } else {
            // 마이그레이션 실행 (연결 실패 시 자체적으로 에러 반환)
            $migrationResult = $installer->runMigrations($dbConfig);

            if (!$migrationResult['success']) {
                $error = $migrationResult['message'];
            } else {
                // 세션에 DB 설정 저장
                $_SESSION['db_config'] = $dbConfig;
                $_SESSION['migration_result'] = $migrationResult;

                // 다음 단계로
                header('Location: ?step=3');
                exit;
            }
        }
    }
}

// 기본값
$dbConfig = $_SESSION['db_config'] ?? [
    'host' => 'localhost',
    'port' => 3306,
    'database' => '',
    'username' => 'root',
    'password' => '',
];
?>

<div class="content">
    <h2>Step 2. 데이터베이스 설정</h2>
    <p>데이터베이스 연결 정보를 입력하세요. 데이터베이스가 없으면 자동으로 생성됩니다.</p>
    <p class="help-text">
        최소 MySQL 5.7.8 또는 MariaDB 10.3이 필요합니다.
        신규 운영은 MySQL 8.4 LTS 또는 MariaDB 10.11 LTS 이상을 권장합니다.
    </p>

    <?php if ($error): ?>
    <div class="alert alert-error">
        <strong>오류:</strong> <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <?php if ($success): ?>
    <div class="alert alert-success">
        <?= htmlspecialchars($success) ?>
    </div>
    <?php endif; ?>

    <form method="POST" id="dbForm">
        <input type="hidden" name="action" value="save">

        <div class="two-column">
            <div class="form-group">
                <label>DB 호스트 *</label>
                <input type="text" name="db_host" value="<?= htmlspecialchars($dbConfig['host']) ?>" required>
                <small>일반적으로 localhost 또는 127.0.0.1</small>
            </div>

            <div class="form-group">
                <label>DB 포트 *</label>
                <input type="number" name="db_port" value="<?= htmlspecialchars($dbConfig['port']) ?>" required>
                <small>MySQL 기본 포트는 3306</small>
            </div>
        </div>

        <div class="form-group">
            <label>데이터베이스명 *</label>
            <input type="text" name="db_database" value="<?= htmlspecialchars($dbConfig['database']) ?>" required>
            <small>설치할 데이터베이스 이름 (없으면 자동 생성)</small>
        </div>

        <div class="two-column">
            <div class="form-group">
                <label>DB 사용자명 *</label>
                <input type="text" name="db_username" value="<?= htmlspecialchars($dbConfig['username']) ?>" required>
            </div>

            <div class="form-group">
                <label>DB 비밀번호</label>
                <input type="password" name="db_password" value="<?= htmlspecialchars($dbConfig['password'] ?? '') ?>">
            </div>
        </div>

        <div id="testResult"></div>

        <div class="button-group">
            <button type="button" class="btn btn-secondary" onclick="location.href='?step=1'">
                ← 이전 단계
            </button>
            <button type="button" class="btn btn-secondary" onclick="testConnection()">
                연결 테스트
            </button>
            <button type="submit" class="btn btn-primary">
                다음 단계 →
            </button>
        </div>
    </form>
</div>

<script>
function testConnection() {
    const form = document.getElementById('dbForm');
    const formData = new FormData(form);
    formData.set('action', 'test');

    const resultDiv = document.getElementById('testResult');
    resultDiv.innerHTML = '<div class="alert alert-info">연결 테스트 중...</div>';

    fetch('?step=2', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const dbCreatedMsg = data.db_created ? '<br>데이터베이스가 자동으로 생성되었습니다.' : '';
            const dbEngine = data.server_engine === 'mariadb' ? 'MariaDB' : 'MySQL';
            resultDiv.innerHTML = `
                <div class="alert alert-success">
                    <strong>✓ ${data.message}</strong><br>
                    ${dbEngine} 버전: ${data.server_version}${dbCreatedMsg}
                </div>
            `;
        } else {
            resultDiv.innerHTML = `
                <div class="alert alert-error">
                    <strong>✗ 연결 실패</strong><br>
                    ${data.message}
                </div>
            `;
        }
    })
    .catch(error => {
        resultDiv.innerHTML = `
            <div class="alert alert-error">
                <strong>JavaScript 오류:</strong> ${error.message}<br>
                네트워크 탭(F12)에서 자세한 내용을 확인하세요.
            </div>
        `;
        console.error('Fetch error:', error);
    });
}
</script>
