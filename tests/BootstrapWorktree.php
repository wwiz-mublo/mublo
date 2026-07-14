<?php
/**
 * Worktree 전용 Bootstrap — vendor 경로를 메인 프레임워크로 지정
 */
define('MUBLO_ROOT_PATH', __DIR__ . '/..');
define('MUBLO_SRC_PATH', MUBLO_ROOT_PATH . '/src');
define('MUBLO_CONFIG_PATH', MUBLO_ROOT_PATH . '/config');
define('MUBLO_PUBLIC_PATH', MUBLO_ROOT_PATH . '/public');
define('MUBLO_STORAGE_PATH', MUBLO_ROOT_PATH . '/storage');
define('MUBLO_PUBLIC_STORAGE_PATH', MUBLO_PUBLIC_PATH . '/storage');
define('MUBLO_PLUGIN_PATH', MUBLO_ROOT_PATH . '/plugins');
define('MUBLO_PACKAGE_PATH', MUBLO_ROOT_PATH . '/packages');
define('MUBLO_ASSETS_PATH', MUBLO_PUBLIC_PATH . '/assets');
define('MUBLO_VIEW_PATH', MUBLO_ROOT_PATH . '/views');
define('MUBLO_TEMPLATE_PATH', MUBLO_ROOT_PATH . '/templates');
define('MUBLO_ASSET_URI', '/assets');
define('MUBLO_PUBLIC_STORAGE_URI', '/storage');

// 메인 프레임워크 vendor 사용
$frameworkVendor = realpath(MUBLO_ROOT_PATH . '/../../../../vendor/autoload.php');
if ($frameworkVendor === false) {
    // 대안: 같은 depth
    $frameworkVendor = realpath(MUBLO_ROOT_PATH . '/../../../vendor/autoload.php');
}
require_once $frameworkVendor;

// 배포본과 동일하게 비어 있는 config 디렉토리에서도 AI 기본 설정을 준비한다.
if (!(new \Mublo\Core\Install\Installer())->generateAiConfig()) {
    throw new \RuntimeException('테스트용 AI 설정 파일을 생성할 수 없습니다.');
}

// Worktree Shop 네임스페이스 등록
spl_autoload_register(function ($class) {
    $worktreeBase = MUBLO_ROOT_PATH;
    $map = [
        'Tests\\Shop\\'          => $worktreeBase . '/packages/Shop/tests/',
        'Mublo\\Packages\\Shop\\' => $worktreeBase . '/packages/Shop/',
    ];
    foreach ($map as $prefix => $base) {
        if (strncmp($prefix, $class, strlen($prefix)) === 0) {
            $relative = substr($class, strlen($prefix));
            $file = $base . str_replace('\\', '/', $relative) . '.php';
            if (file_exists($file)) {
                require_once $file;
                return;
            }
        }
    }
});
