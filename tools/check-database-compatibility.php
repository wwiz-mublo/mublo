<?php

/**
 * 지원 DB 실서버 호환성 스모크 검사.
 *
 * 전용 테스트 DB를 초기화하므로 DB_TEST_NAME에 반드시 "test"가 포함되어야 한다.
 * Core 신규 설치 전체와 공개 Package/Plugin 마이그레이션, 016 부분 적용 복구를 검증한다.
 */

use Mublo\Core\Extension\MigrationRunner;
use Mublo\Core\Install\Installer;
use Mublo\Infrastructure\Database\Database;

require_once __DIR__ . '/../bootstrap.php';

$host = getenv('DB_TEST_HOST') ?: '127.0.0.1';
$port = (int) (getenv('DB_TEST_PORT') ?: 3306);
$user = getenv('DB_TEST_USER') ?: 'root';
$password = (string) getenv('DB_TEST_PASS');
$databaseName = getenv('DB_TEST_NAME') ?: 'mublo_compatibility_test';

if (!preg_match('/^[A-Za-z0-9_]+$/', $databaseName) || stripos($databaseName, 'test') === false) {
    fwrite(STDERR, "DB_TEST_NAME은 안전한 테스트 전용 DB 이름이어야 하며 'test'를 포함해야 합니다.\n");
    exit(1);
}

$config = [
    'host' => $host,
    'port' => $port,
    'database' => $databaseName,
    'username' => $user,
    'password' => $password,
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
];

$fail = static function (string $message): never {
    fwrite(STDERR, "[DB COMPAT] FAIL: {$message}\n");
    exit(1);
};

$installer = new Installer();
$connection = $installer->testDatabaseConnection($config);
if (!$connection['success']) {
    $fail((string) ($connection['message'] ?? 'DB 연결/버전 검사 실패'));
}

printf(
    "[DB COMPAT] %s %s\n",
    strtoupper((string) $connection['server_engine']),
    (string) $connection['server_version']
);

$installResult = $installer->runMigrations($config);
if (!$installResult['success']) {
    $fail((string) ($installResult['message'] ?? 'Core 신규 설치 마이그레이션 실패'));
}

$pdo = new PDO(
    "mysql:host={$host};port={$port};dbname={$databaseName};charset=utf8mb4",
    $user,
    $password,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);
$runner = new MigrationRunner(new Database($pdo));

$migrationTargets = [
    ['package', 'Board', MUBLO_PACKAGE_PATH . '/Board/database/migrations'],
    ['package', 'Shop', MUBLO_PACKAGE_PATH . '/Shop/database/migrations'],
];

foreach (glob(MUBLO_PLUGIN_PATH . '/*/database/migrations', GLOB_ONLYDIR) ?: [] as $path) {
    $migrationTargets[] = ['plugin', basename(dirname(dirname($path))), $path];
}
foreach (glob(MUBLO_PACKAGE_PATH . '/Board/Plugins/*/database/migrations', GLOB_ONLYDIR) ?: [] as $path) {
    $pluginName = basename(dirname(dirname($path)));
    $migrationTargets[] = ['plugin', 'Board/' . $pluginName, $path];
}

foreach ($migrationTargets as [$source, $name, $path]) {
    $result = $runner->run($source, $name, $path);
    if (!$result['success']) {
        $fail("{$source}:{$name} 마이그레이션 실패: " . ($result['error'] ?? 'unknown error'));
    }
}

$missingChecksums = (int) $pdo->query(
    "SELECT COUNT(*) FROM `schema_migrations` WHERE `checksum` IS NULL OR `checksum` = ''"
)->fetchColumn();
if ($missingChecksums !== 0) {
    $fail("checksum이 기록되지 않은 마이그레이션 이력이 {$missingChecksums}건 있습니다.");
}

$hasColumn = static function (string $table, string $column) use ($pdo, $databaseName): bool {
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS '
        . 'WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $statement->execute([$databaseName, $table, $column]);
    return (int) $statement->fetchColumn() === 1;
};

$columnMeta = static function (string $table, string $column) use ($pdo, $databaseName): ?array {
    $statement = $pdo->prepare(
        'SELECT DATA_TYPE, IS_NULLABLE, COLUMN_TYPE FROM information_schema.COLUMNS '
        . 'WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $statement->execute([$databaseName, $table, $column]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
};

$indexColumns = static function (string $table, string $index) use ($pdo, $databaseName): array {
    $statement = $pdo->prepare(
        'SELECT COLUMN_NAME FROM information_schema.STATISTICS '
        . 'WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ? AND NON_UNIQUE = 0 '
        . 'ORDER BY SEQ_IN_INDEX ASC'
    );
    $statement->execute([$databaseName, $table, $index]);
    return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
};

if (!$hasColumn('block_rows', 'dismissible') || !$hasColumn('block_rows', 'dismiss_hours')) {
    $fail('Core 신규 설치 후 block_rows의 topbar dismiss 컬럼이 누락되었습니다.');
}

// 부분 적용 회귀: 첫 컬럼만 존재하고 016 이력은 없는 상태에서도 두 번째 컬럼이 추가돼야 한다.
$pdo->exec('ALTER TABLE `block_rows` DROP COLUMN `dismiss_hours`');
$deleteHistory = $pdo->prepare(
    "DELETE FROM `schema_migrations` WHERE `source` = 'core' AND `name` = '__core__' AND `file` = ?"
);
$deleteHistory->execute(['016_add_topbar_dismiss_to_block_rows.sql']);

$upgradeResult = $runner->run('core', '__core__', MUBLO_ROOT_PATH . '/database/migrations');
if (!$upgradeResult['success']) {
    $fail('016 부분 적용 복구 실패: ' . ($upgradeResult['error'] ?? 'unknown error'));
}
if (!$hasColumn('block_rows', 'dismissible') || !$hasColumn('block_rows', 'dismiss_hours')) {
    $fail('016 부분 적용 복구 후 필요한 컬럼이 모두 존재하지 않습니다.');
}

// 017(menu_items 레이아웃 오버라이드)도 개별 ALTER 로 나뉘어 있다. 같은 부분 적용 회귀 검증:
// 첫 컬럼(layout_type)만 남기고 나머지를 지운 뒤 017 이력을 삭제해도, 재실행이 나머지 4개 컬럼을 채워야 한다.
$menuLayoutColumns = [
    'layout_type', 'sidebar_left_width', 'sidebar_left_mobile',
    'sidebar_right_width', 'sidebar_right_mobile',
];
foreach ($menuLayoutColumns as $column) {
    if (!$hasColumn('menu_items', $column)) {
        $fail("Core 신규 설치 후 menu_items의 레이아웃 오버라이드 컬럼이 누락되었습니다: {$column}");
    }
}

foreach (['sidebar_left_width', 'sidebar_left_mobile', 'sidebar_right_width', 'sidebar_right_mobile'] as $column) {
    $pdo->exec("ALTER TABLE `menu_items` DROP COLUMN `{$column}`");
}
$deleteHistory->execute(['017_add_menu_layout_override.sql']);

$upgrade017 = $runner->run('core', '__core__', MUBLO_ROOT_PATH . '/database/migrations');
if (!$upgrade017['success']) {
    $fail('017 부분 적용 복구 실패: ' . ($upgrade017['error'] ?? 'unknown error'));
}
foreach ($menuLayoutColumns as $column) {
    if (!$hasColumn('menu_items', $column)) {
        $fail("017 부분 적용 복구 후 menu_items 레이아웃 컬럼이 누락되었습니다: {$column}");
    }
}

// 컬럼 존재만이 아니라 스키마 계약까지 검증한다:
// - IS_NULLABLE=YES 는 "NULL = 상속" 규약의 근거다. 실수로 NOT NULL 이 되면 상속 의미가 깨진다.
// - DATA_TYPE / unsigned 도 마이그레이션 의도와 일치해야 한다.
$menuLayoutColumnSpec = [
    'layout_type'          => ['type' => 'tinyint',  'unsigned' => true],
    'sidebar_left_width'   => ['type' => 'smallint', 'unsigned' => true],
    'sidebar_left_mobile'  => ['type' => 'tinyint',  'unsigned' => false],
    'sidebar_right_width'  => ['type' => 'smallint', 'unsigned' => true],
    'sidebar_right_mobile' => ['type' => 'tinyint',  'unsigned' => false],
];
foreach ($menuLayoutColumnSpec as $column => $spec) {
    $meta = $columnMeta('menu_items', $column);
    if ($meta === null) {
        $fail("menu_items.{$column} 컬럼 메타를 읽을 수 없습니다.");
        continue;
    }
    if (strtoupper((string) $meta['IS_NULLABLE']) !== 'YES') {
        $fail("menu_items.{$column} 은 NULL 허용이어야 합니다(NULL=상속 계약). 실제 IS_NULLABLE={$meta['IS_NULLABLE']}");
    }
    if (strtolower((string) $meta['DATA_TYPE']) !== $spec['type']) {
        $fail("menu_items.{$column} DATA_TYPE 불일치: 기대 {$spec['type']}, 실제 {$meta['DATA_TYPE']}");
    }
    $isUnsigned = stripos((string) $meta['COLUMN_TYPE'], 'unsigned') !== false;
    if ($isUnsigned !== $spec['unsigned']) {
        $expected = $spec['unsigned'] ? 'unsigned' : 'signed';
        $fail("menu_items.{$column} unsigned 불일치: 기대 {$expected}, 실제 {$meta['COLUMN_TYPE']}");
    }
}

if ($indexColumns('manual_pages', 'uk_book_slug') !== ['book_id', 'slug']) {
    $fail('manual_pages.uk_book_slug UNIQUE 인덱스가 없거나 컬럼 순서가 올바르지 않습니다.');
}

foreach ([
    ['shop_returns', 'domain_id'],
    ['shop_returns', 'responsibility'],
    ['shop_returns', 'exchange_shipping_fee'],
    ['shop_returns', 'fee_status'],
    ['shop_exchange_items', 'stock_reservation_status'],
    ['shop_exchange_items', 'source_restocked_at'],
    ['shop_claim_logs', 'return_id'],
    ['shop_shipments', 'claim_id'],
    ['shop_shipments', 'shipment_role'],
    ['shop_config', 'claim_state_actions'],
] as [$table, $column]) {
    if (!$hasColumn($table, $column)) {
        $fail("Shop 교환 스키마 컬럼이 누락되었습니다: {$table}.{$column}");
    }
}
if ($indexColumns('shop_exchange_items', 'uk_return') !== ['return_id']) {
    $fail('shop_exchange_items.uk_return UNIQUE 멱등 인덱스가 없습니다.');
}

echo '[DB COMPAT] OK: Core fresh install, public extensions, partial 016/017 upgrade, schema contracts' . PHP_EOL;
