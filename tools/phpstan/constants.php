<?php
/**
 * PHPStan 전용 경로 상수 스텁. 실행되지 않고 scanFiles 로만 읽힌다.
 *
 * 경로 상수는 bootstrap.php 가 런타임에 define() 하므로 정적분석이 보지 못한다.
 * bootstrap.php 자체를 bootstrapFiles 로 태우면 오토로드와 환경변수 적재까지
 * 수행하므로, 선언만 담은 스텁을 따로 둔다.
 *
 * 값은 bootstrap.php 와 같은 실제 경로여야 한다. 빈 문자열이면
 * require(MUBLO_CONFIG_PATH . '/security.php') 가 '/security.php' 로 풀려
 * "파일 없음" 오탐이 생긴다.
 *
 * bootstrap.php 에 상수를 추가하면 여기도 같이 추가한다.
 */

// dirname() 은 스캔 시점에 접히지 않아 상수가 등록되지 않는다. __DIR__ 연결은 접힌다.
define('MUBLO_ROOT_PATH', __DIR__ . '/../..');
define('MUBLO_SRC_PATH', MUBLO_ROOT_PATH . '/src');
define('MUBLO_CONFIG_PATH', MUBLO_ROOT_PATH . '/config');
define('MUBLO_PUBLIC_PATH', MUBLO_ROOT_PATH . '/public');
define('MUBLO_STORAGE_PATH', MUBLO_ROOT_PATH . '/storage');
define('MUBLO_PUBLIC_STORAGE_PATH', MUBLO_PUBLIC_PATH . '/storage');
define('MUBLO_PLUGIN_PATH', MUBLO_ROOT_PATH . '/plugins');
define('MUBLO_PACKAGE_PATH', MUBLO_ROOT_PATH . '/packages');
define('MUBLO_ASSETS_PATH', MUBLO_PUBLIC_PATH . '/assets');
define('MUBLO_VIEW_PATH', MUBLO_ROOT_PATH . '/views');
define('MUBLO_ASSET_URI', '/assets');
