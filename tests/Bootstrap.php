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

// security.php 는 설치기가 만드는 파일이라 깨끗한 체크아웃에는 없다. 없을 때만
// 만든다 — 있는 파일을 덮으면 그 설치본의 암호화 키가 바뀌어 기존 암호문을
// 읽지 못하게 된다.
if (!file_exists(MUBLO_CONFIG_PATH . '/security.php')) {
    if (!is_dir(MUBLO_CONFIG_PATH)) {
        mkdir(MUBLO_CONFIG_PATH, 0755, true);
    }

    $testSecurityConfig = <<<'PHP'
<?php
/**
 * 테스트 전용 보안 설정. tests/Bootstrap.php 가 파일이 없을 때만 만든다.
 */

return [
    'password' => [
        'algo' => PASSWORD_DEFAULT,
        'cost' => 4,
    ],
    'csrf' => [
        'token_ttl' => 3600,
        'token_key' => 'test-csrf-token-key',
    ],
    'session' => [
        'lifetime' => 120,
        'cookie_secure' => false,
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
    ],
    'encryption' => [
        'key' => '00000000000000000000000000000000000000000000000000000000000000ff',
        'cipher' => 'aes-256-gcm',
    ],
    'search' => [
        'pepper' => '00000000000000000000000000000000000000000000000000000000000000aa',
    ],
    'trusted_proxies' => [],
];
PHP;

    if (file_put_contents(MUBLO_CONFIG_PATH . '/security.php', $testSecurityConfig) === false) {
        throw new \RuntimeException('테스트용 보안 설정 파일을 생성할 수 없습니다.');
    }
}

// 환경 설정
if (file_exists(MUBLO_ROOT_PATH . '/.env.testing')) {
    $dotenv = \Dotenv\Dotenv::createImmutable(MUBLO_ROOT_PATH, '.env.testing');
    $dotenv->load();
} elseif (file_exists(MUBLO_ROOT_PATH . '/.env')) {
    $dotenv = \Dotenv\Dotenv::createImmutable(MUBLO_ROOT_PATH);
    $dotenv->load();
}
