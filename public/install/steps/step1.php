<?php
/**
 * Step 1: 환경 체크
 */

$checks = $installer->checkEnvironment();
$canInstall = in_array($checks['overall_status'], ['OK', 'WARNING'], true);
$permissionFailures = array_filter(
    $checks['permissions'],
    static fn (array $info): bool => $info['status'] === 'FAIL'
);
$requiresOtherWrite = array_filter(
    $permissionFailures,
    static fn (array $info): bool => $info['access_class'] === 'other'
);
// optional(plugins/·packages/)은 못 써도 설치를 막지 않는다 — 확장 zip 업로드만 못 쓸 뿐이라
// FAIL 목록과 분리해 "이것만 안 됩니다" 로 안내한다.
$permissionWarnings = array_filter(
    $checks['permissions'],
    static fn (array $info): bool => $info['status'] === 'WARNING'
);

// "www-data (GID 33)" 처럼 라벨에 GID 가 붙어 오므로 명령 예시에는 이름만 쓴다.
$phpGroupHint = '<PHP 그룹>';
foreach ($permissionWarnings as $info) {
    if (!empty($info['php_group'])) {
        $phpGroupHint = trim((string) preg_replace('/\s*\(GID \d+\)$/', '', $info['php_group']));
        break;
    }
}

$purposeLabels = [
    'install' => '설치 중 쓰기',
    'optional' => '확장 업로드에만 필요',
];
$statusClasses = ['OK' => 'ok', 'WARNING' => 'warning', 'FAIL' => 'fail'];
$statusIcons = ['OK' => '✓', 'WARNING' => '!', 'FAIL' => '✗'];
?>

<div class="content">
    <h2>Step 1. 환경 체크</h2>
    <p>서버 환경이 Mublo Framework 요구사항을 충족하는지 확인합니다.</p>

    <!-- PHP 버전 체크 -->
    <h3>PHP 버전</h3>
    <ul class="check-list">
        <li class="check-item <?= $checks['php_version']['status'] === 'OK' ? 'ok' : 'fail' ?>">
            <div class="icon"><?= $checks['php_version']['status'] === 'OK' ? '✓' : '✗' ?></div>
            <div class="info">
                <strong>PHP <?= $checks['php_version']['current'] ?></strong>
                <span><?= $checks['php_version']['message'] ?></span>
            </div>
        </li>
    </ul>

    <!-- 필수 확장 모듈 -->
    <h3>필수 확장 모듈</h3>
    <ul class="check-list module-check-grid">
        <?php foreach ($checks['extensions']['required'] as $ext => $info): ?>
        <li class="check-item <?= $info['status'] === 'OK' ? 'ok' : 'fail' ?>">
            <div class="icon"><?= $info['status'] === 'OK' ? '✓' : '✗' ?></div>
            <div class="info">
                <strong><?= $ext ?></strong>
                <span><?= $info['message'] ?></span>
            </div>
        </li>
        <?php endforeach; ?>
    </ul>

    <!-- 권장 확장 모듈 -->
    <h3>권장 확장 모듈</h3>
    <ul class="check-list module-check-grid">
        <?php foreach ($checks['extensions']['recommended'] as $ext => $info): ?>
        <li class="check-item <?= $info['status'] === 'OK' ? 'ok' : 'warning' ?>">
            <div class="icon"><?= $info['status'] === 'OK' ? '✓' : '!' ?></div>
            <div class="info">
                <strong><?= $ext ?></strong>
                <span><?= $info['message'] ?></span>
            </div>
        </li>
        <?php endforeach; ?>
    </ul>

    <!-- 디렉토리 권한 -->
    <h3>디렉토리 권한</h3>
    <ul class="check-list">
        <?php foreach ($checks['permissions'] as $label => $info): ?>
        <li class="check-item <?= $statusClasses[$info['status']] ?? 'fail' ?>">
            <div class="icon"><?= $statusIcons[$info['status']] ?? '✗' ?></div>
            <div class="info">
                <strong>
                    <?= htmlspecialchars($label) ?>
                    <small class="permission-purpose">
                        <?= $purposeLabels[$info['purpose']] ?? '운영 중 계속 쓰기' ?>
                    </small>
                </strong>
                <span><?= htmlspecialchars($info['message']) ?> - <?= htmlspecialchars($info['path']) ?></span>
                <?php
                $permissionMeta = array_filter([
                    !empty($info['mode']) ? '퍼미션 ' . $info['mode'] : null,
                    !empty($info['owner']) ? '소유자 ' . $info['owner'] : null,
                    !empty($info['group']) ? '그룹 ' . $info['group'] : null,
                    !empty($info['php_user']) ? 'PHP ' . $info['php_user'] : null,
                    !empty($info['php_group']) ? 'PHP 그룹 ' . $info['php_group'] : null,
                ]);
                ?>
                <?php if ($permissionMeta !== []): ?>
                    <span class="permission-meta"><?= htmlspecialchars(implode(' · ', $permissionMeta)) ?></span>
                <?php endif; ?>
                <span class="permission-guidance"><?= htmlspecialchars($info['guidance']) ?></span>
            </div>
        </li>
        <?php endforeach; ?>
    </ul>

    <?php if ($permissionFailures !== []): ?>
        <div class="alert alert-warning permission-help" style="margin-top: 16px;">
            <?php if ($requiresOtherWrite !== []): ?>
                <strong>이 공유호스팅에서는 707 권한이 필요합니다</strong>
                <ol>
                    <li>FTP 또는 파일 관리자에서 <code>config</code>, <code>storage</code>, <code>public/storage</code> 디렉토리를 <code>707</code>로 변경하세요.</li>
                    <li>이 페이지에서 <b>다시 체크</b>를 누르고 설치를 진행하세요.</li>
                    <li>설치 완료 후 <code>config</code>만 <code>755</code>로 반드시 되돌리세요.</li>
                    <li><code>storage</code>와 <code>public/storage</code>는 운영 중 쓰기가 필요하므로 <code>707</code>을 유지하세요.</li>
                </ol>
                <p><code>777</code>과 하위 파일까지 바꾸는 재귀 권한 변경은 사용하지 마세요.</p>
            <?php else: ?>
                <strong>디렉토리 쓰기 권한을 확인하세요</strong>
                <ol>
                    <li>PHP가 계정 소유자로 실행되면 <code>755</code>, 현재 그룹으로 실행되면 <code>775</code>를 사용하세요.</li>
                    <li>그래도 실패하는 공유호스팅에서는 세 디렉토리를 <code>707</code>로 변경한 뒤 다시 확인하세요.</li>
                    <li>설치 완료 후 <code>config</code>는 <code>755</code>로 되돌리고, 두 storage 디렉토리는 쓰기 권한을 유지하세요.</li>
                </ol>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="alert alert-info" style="margin-top: 16px;">
            <strong>권한 확인 완료</strong><br>
            숫자 퍼미션이 아니라 PHP가 실제 파일을 생성하고 삭제한 결과입니다.
            <code>storage</code>와 <code>public/storage</code>는 설치 후에도 현재 쓰기 권한을 유지하세요.
        </div>
    <?php endif; ?>

    <?php if ($permissionWarnings !== []): ?>
        <div class="alert alert-warning permission-help" style="margin-top: 16px;">
            <strong>확장 zip 업로드는 사용할 수 없습니다 (설치는 그대로 진행됩니다)</strong>
            <p>
                <?= htmlspecialchars(implode(', ', array_keys($permissionWarnings))) ?>
                디렉토리에 PHP가 쓸 수 없어, 관리자 화면의 <b>확장 업로드</b> 기능만 막힙니다.
                플러그인·패키지는 FTP로 직접 올려도 똑같이 동작합니다.
            </p>
            <p>
                업로드 기능까지 쓰려면 해당 디렉토리에 그룹 쓰기를 여세요.
                (예: <code>chgrp <?= htmlspecialchars($phpGroupHint) ?> plugins &amp;&amp; chmod 775 plugins</code>)
                실행 코드가 들어가는 디렉토리이므로 <code>707</code>·<code>777</code>은 권하지 않습니다.
            </p>
        </div>
    <?php endif; ?>

    <?php if (!$canInstall): ?>
    <div class="alert alert-error" style="margin-top: 20px;">
        <strong>설치를 진행할 수 없습니다</strong><br>
        위에 표시된 필수 요구사항을 충족한 후 페이지를 새로고침하세요.
    </div>
    <?php endif; ?>

    <div class="button-group">
        <button class="btn btn-secondary" onclick="location.reload()">다시 체크</button>
        <button class="btn btn-primary" onclick="location.href='?step=2'" <?= !$canInstall ? 'disabled' : '' ?>>
            다음 단계 →
        </button>
    </div>
</div>
