<?php
/**
 * Admin Login View
 *
 * 관리자 로그인 페이지
 * - 부트스트랩 표준 마크업, 프레임스킨 색상테마(--bs-default-*)를 그대로 따른다.
 * - 기본은 부트스트랩 유틸리티. 커스텀 보강은 login.css(테마변수 기반).
 */
$skin = $adminFrameSkin ?? 'basic';
?>

<link rel="stylesheet" href="/serve/admin/<?= htmlspecialchars($skin) ?>/css/login.css?<?= adminAssetVersion('/css/login.css', $skin) ?>">

<div class="admin-login d-flex align-items-center justify-content-center min-vh-100 p-3">
    <div class="w-100" style="max-width: 420px;">
        <div class="text-center mb-4">
            <span class="d-inline-flex align-items-center justify-content-center bg-default text-bg-default rounded-3 mb-3" style="width: 48px; height: 48px;">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-layout-dashboard-icon lucide-layout-dashboard"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg>
            </span>
            <h1 class="h4 fw-bold mb-1"><?= htmlspecialchars($pageTitle ?? '관리자') ?></h1>
            <p class="text-muted small mb-0">계정 정보를 입력하여 로그인하세요</p>
        </div>

        <div class="card shadow-sm">
            <div class="card-body">
                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger py-2 small mb-3">
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="/admin/login" autocomplete="off">
                    <input type="hidden" name="_token" value="<?= htmlspecialchars($csrfToken ?? '') ?>">

                    <div class="mb-4">
                        <label for="user_id" class="form-label">아이디</label>
                        <input
                            type="text"
                            id="user_id"
                            name="formData[user_id]"
                            class="form-control"
                            value="<?= htmlspecialchars($user_id ?? '') ?>"
                            placeholder="아이디를 입력하세요"
                            autocomplete="off"
                            required
                        >
                    </div>

                    <div class="mb-4">
                        <label for="password" class="form-label">비밀번호</label>
                        <input
                            type="password"
                            id="password"
                            name="formData[password]"
                            class="form-control"
                            placeholder="비밀번호를 입력하세요"
                            required
                        >
                    </div>

                    <button type="submit" class="btn btn-default w-100 mt-3">로그인</button>
                </form>
            </div>
        </div>

        <p class="text-center text-muted small mt-4 mb-0">
            &copy; <?= date('Y') ?> <?= htmlspecialchars($siteTitle ?? 'Mublo', ENT_QUOTES, 'UTF-8') ?>
        </p>
    </div>
</div>

<?php
$host = $_SERVER['HTTP_HOST'] ?? '';
$isDemo = str_starts_with(explode('.', $host)[0], 'demo');
if ($isDemo): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var uid = document.getElementById('user_id');
    var pwd = document.getElementById('password');
    if (uid && !uid.value) uid.value = 'demo';
    if (pwd && !pwd.value) pwd.value = '123456';
});
</script>
<?php endif; ?>
