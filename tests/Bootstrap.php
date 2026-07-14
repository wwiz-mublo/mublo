<?php
/**
 * tests/Bootstrap.php
 *
 * 테스트 환경 부트스트랩
 */

// 루트 경로 정의
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

// Composer Autoload
require_once MUBLO_ROOT_PATH . '/vendor/autoload.php';

// 배포본과 동일하게 비어 있는 config 디렉토리에서도 AI 기본 설정을 준비한다.
if (!(new \Mublo\Core\Install\Installer())->generateAiConfig()) {
    throw new \RuntimeException('테스트용 AI 설정 파일을 생성할 수 없습니다.');
}

// 환경 설정
if (file_exists(MUBLO_ROOT_PATH . '/.env.testing')) {
    $dotenv = \Dotenv\Dotenv::createImmutable(MUBLO_ROOT_PATH, '.env.testing');
    $dotenv->load();
} elseif (file_exists(MUBLO_ROOT_PATH . '/.env')) {
    $dotenv = \Dotenv\Dotenv::createImmutable(MUBLO_ROOT_PATH);
    $dotenv->load();
}
