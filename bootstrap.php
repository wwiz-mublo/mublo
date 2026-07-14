<?php
/**
 * bootstrap.php
 *
 * 프레임워크 부트스트랩 파일
 *
 * 역할:
 * - 파일 시스템 경로 상수 정의
 * - Composer Autoload 로딩
 * - .env 환경 변수 로딩
 * - PHP 런타임 설정
 * - Application 객체 생성
 *
 * 금지:
 * - 라우팅 처리
 * - 인증 / CSRF / 세션 로직
 * - DB 접근
 * - 플러그인 실행
 * - HTML / URL 출력
 */

// ==================================================
// Filesystem Path Constants
// - 도메인과 무관한 절대 경로만 정의
// ==================================================
define('MUBLO_ROOT_PATH', __DIR__);
define('MUBLO_SRC_PATH', MUBLO_ROOT_PATH . '/src');
define('MUBLO_CONFIG_PATH', MUBLO_ROOT_PATH . '/config');
// MUBLO_PUBLIC_PATH: index.php에서 미리 정의된 경우 사용 (www, public_html 등 지원)
// 미정의 시 DOCUMENT_ROOT(웹 요청) 또는 /public(CLI) 사용
if (!defined('MUBLO_PUBLIC_PATH')) {
    $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
    if ($docRoot && is_dir($docRoot)) {
        define('MUBLO_PUBLIC_PATH', rtrim($docRoot, '/'));
    } else {
        define('MUBLO_PUBLIC_PATH', MUBLO_ROOT_PATH . '/public');
    }
    unset($docRoot);
}
define('MUBLO_STORAGE_PATH', MUBLO_ROOT_PATH . '/storage');
define('MUBLO_PUBLIC_STORAGE_PATH', MUBLO_PUBLIC_PATH . '/storage');
define('MUBLO_PLUGIN_PATH', MUBLO_ROOT_PATH . '/plugins');
define('MUBLO_PACKAGE_PATH', MUBLO_ROOT_PATH . '/packages');
define('MUBLO_ASSETS_PATH', MUBLO_PUBLIC_PATH . '/assets');
define('MUBLO_VIEW_PATH', MUBLO_ROOT_PATH . '/views');

// ==================================================
// URI Constants (Domain-agnostic)
// - 도메인 / 스킴 / 포트 포함 금지
// ==================================================
define('MUBLO_ASSET_URI', '/assets');
define('MUBLO_PUBLIC_STORAGE_URI', '/storage');

// ==================================================
// Composer Autoload
// ==================================================
require_once MUBLO_ROOT_PATH . '/vendor/autoload.php';

// ==================================================
// Helper Functions (env, base_path, etc.)
// ==================================================
require_once MUBLO_SRC_PATH . '/Helper/EnvHelpers.php';

// ==================================================
// Load Environment Variables (.env)
// ==================================================
use Dotenv\Dotenv;

// APP_ENV 는 OS 환경변수 기준으로만 정한다(.env 로드 전에는 .env 안의 APP_ENV 를 알 수 없다).
$appEnv = $_ENV['APP_ENV'] ?? 'production';

// Dotenv createImmutable 은 '먼저 정의된 값'이 이긴다. 따라서 우선순위가 높은(구체적/local)
// 파일을 앞에 둬야 .env.local / .env.{env} 가 .env 를 override 한다.
// (과거엔 .env 를 먼저 로드해 변형 파일이 조용히 무시됐다.)
$dotenv = Dotenv::createImmutable(MUBLO_ROOT_PATH, [
    '.env.' . $appEnv . '.local',
    '.env.' . $appEnv,
    '.env.local',
    '.env',
]);
$dotenv->safeLoad();

// ==================================================
// PHP Runtime Configuration
// ==================================================
$isDebug = ($_ENV['APP_DEBUG'] ?? 'false') === 'true';

if ($isDebug) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    ini_set('log_errors', '1');
    error_reporting(E_ALL);
} else {
    // 운영: 화면에는 숨기되(display off) 로그에는 남긴다.
    // error_reporting(0)은 로깅까지 꺼서 장애 원인 추적을 불가능하게 만든다.
    // deprecation은 상위 PHP에서 노이즈가 크므로 제외하고 나머지(에러·경고·공지)는 기록.
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('log_errors', '1');
    error_reporting(E_ALL & ~E_DEPRECATED);
}

// 기본 타임존
date_default_timezone_set('Asia/Seoul');

// ==================================================
// Boot Application
// ==================================================
use Mublo\Core\App\Application;

return new Application();
