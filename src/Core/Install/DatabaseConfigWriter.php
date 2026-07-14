<?php

namespace Mublo\Core\Install;

use Mublo\Infrastructure\Database\MigrationErrorPolicy;
use PDO;
use PDOException;
use Exception;
use Mublo\Infrastructure\Crypto\CryptoManager;
use Mublo\Infrastructure\Database\SqlStatementSplitter;

/**
 * DatabaseConfigWriter
 *
 * DB 연결 테스트 및 config/database.php 파일 생성
 */
class DatabaseConfigWriter
{
    private CryptoManager $crypto;
    private DatabaseServerCompatibility $serverCompatibility;

    public function __construct(?DatabaseServerCompatibility $serverCompatibility = null)
    {
        $this->crypto = new CryptoManager();
        $this->serverCompatibility = $serverCompatibility ?? new DatabaseServerCompatibility();
    }
    /**
     * DB 연결 테스트
     */
    public function testConnection(array $config): array
    {
        // DDL(CREATE DATABASE / USE)에 식별자로 보간되는 값 검증 — 설치 시 SQL 인젝션 방지.
        foreach (['database' => 'DB 이름', 'charset' => '문자셋', 'collation' => '콜레이션'] as $key => $label) {
            $value = (string) ($config[$key] ?? '');
            if (!preg_match('/^[A-Za-z0-9_]+$/', $value)) {
                return [
                    'success' => false,
                    'message' => "{$label}에 허용되지 않은 문자가 있습니다. (영문/숫자/밑줄만 가능)",
                ];
            }
        }

        try {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;charset=%s',
                $config['host'],
                $config['port'],
                $config['charset']
            );

            $pdo = new PDO(
                $dsn,
                $config['username'],
                $config['password'],
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]
            );

            // 지원되지 않는 서버에서 DB를 생성하거나 마이그레이션을 시작하지 않는다.
            $rawServerVersion = (string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
            $compatibility = $this->serverCompatibility->inspect($rawServerVersion);
            if (!$compatibility['supported']) {
                return [
                    'success' => false,
                    'message' => $compatibility['message'],
                    'server_engine' => $compatibility['engine'],
                    'server_version' => $compatibility['version'] ?? $rawServerVersion,
                    'minimum_version' => $compatibility['minimum'],
                ];
            }

            // 데이터베이스 존재 확인
            $stmt = $pdo->prepare("SHOW DATABASES LIKE ?");
            $stmt->execute([$config['database']]);
            $dbExists = $stmt->rowCount() > 0;
            $stmt->closeCursor();

            if (!$dbExists) {
                // 데이터베이스가 없으면 자동 생성
                try {
                    $pdo->exec("CREATE DATABASE `{$config['database']}`
                               CHARACTER SET {$config['charset']}
                               COLLATE {$config['collation']}");
                    $dbCreated = true;
                } catch (PDOException $createException) {
                    return [
                        'success' => false,
                        'message' => "데이터베이스 생성 실패: {$createException->getMessage()}. CREATE DATABASE 권한이 있는지 확인하세요.",
                    ];
                }
            } else {
                $dbCreated = false;
            }

            // 데이터베이스 선택
            $pdo->exec("USE `{$config['database']}`");

            $message = $dbCreated
                ? "DB 연결 성공 (데이터베이스 '{$config['database']}' 생성됨)"
                : 'DB 연결 성공';

            return [
                'success' => true,
                'message' => $message,
                'server_engine' => $compatibility['engine'],
                'server_version' => $compatibility['version'],
                'minimum_version' => $compatibility['minimum'],
                'db_created' => $dbCreated,
            ];
        } catch (PDOException $e) {
            return [
                'success' => false,
                'message' => 'DB 연결 실패: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * config/database.php 파일 생성
     */
    public function writeConfig(array $config): bool
    {
        $configPath = MUBLO_CONFIG_PATH . '/database.php';

        $content = $this->buildConfigContent($config);

        $result = file_put_contents($configPath, $content);

        if ($result === false) {
            return false;
        }

        // DB 자격 증명이 포함되므로 소유자만 읽고 쓸 수 있게 제한한다.
        // 그룹 읽기가 필요한 서버는 설치 후 운영자가 0640으로 조정한다.
        @chmod($configPath, 0600);

        return true;
    }

    /**
     * config/database.php 내용 생성 (파일 쓰기와 분리 — 순수 함수)
     *
     * 설치 폼 값을 var_export로 PHP 리터럴 직렬화해 설정파일 코드 인젝션을 막는다.
     *
     * 비밀번호 obfuscation: _encrypt_key는 암호문과 같은 파일에 저장되므로 이는 '암호화'가
     * 아니라 소스/백업의 우발적 노출에 대비한 at-rest obfuscation이다. 실제 보호는
     * config/가 웹 docroot(public/) 바깥에 있고 파일 권한으로 제한된다는 점에서 온다.
     */
    public function buildConfigContent(array $config): string
    {
        $encryptKey = $this->crypto->generateShortKey();
        $encryptedPassword = $this->crypto->encrypt($config['password'] ?? '', $encryptKey);

        $host       = var_export((string) ($config['host'] ?? ''), true);
        $port       = (int) ($config['port'] ?? 3306);
        $database   = var_export((string) ($config['database'] ?? ''), true);
        $username   = var_export((string) ($config['username'] ?? ''), true);
        $password   = var_export((string) $encryptedPassword, true);
        $charset    = var_export((string) ($config['charset'] ?? 'utf8mb4'), true);
        $collation  = var_export((string) ($config['collation'] ?? 'utf8mb4_unicode_ci'), true);
        $encryptKeyLit = var_export((string) $encryptKey, true);
        $createdAt  = var_export($this->getCurrentDateTime(), true);

        return <<<PHP
<?php
/**
 * Database Configuration
 * Auto-generated by Mublo Framework Installer
 */

return [
    'connection' => 'mysql',

    'connections' => [
        'mysql' => [
            'driver' => 'mysql',
            'host' => {$host},
            'port' => {$port},
            'database' => {$database},
            'username' => {$username},
            'password' => {$password},
            'charset' => {$charset},
            'collation' => {$collation},
            '_encrypted' => true,
            '_encrypt_key' => {$encryptKeyLit},
            '_created_at' => {$createdAt},
        ],
    ],

    'options' => [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ],
];

PHP;
    }

    /**
     * 현재 시간 반환
     */
    private function getCurrentDateTime(): string
    {
        return date('Y-m-d H:i:s');
    }

    /**
     * mysqli 연결 생성 (마이그레이션 전용)
     *
     * PDO는 DDL 다량 실행 시 unbuffered query 에러가 발생하므로
     * 마이그레이션/시더에는 mysqli를 사용.
     */
    private function createMysqli(array $config): \mysqli
    {
        $mysqli = new \mysqli(
            $config['host'],
            $config['username'],
            $config['password'],
            $config['database'],
            (int) $config['port']
        );

        if ($mysqli->connect_error) {
            throw new \RuntimeException('DB 연결 실패: ' . $mysqli->connect_error);
        }

        $mysqli->set_charset($config['charset'] ?? 'utf8mb4');

        return $mysqli;
    }

    /**
     * SQL 파일의 개별 문장을 순차 실행
     *
     * 세미콜론으로 분리 후 각각 query()로 실행.
     * 멱등성 에러(중복 컬럼/키 등)는 건너뛰고 계속 진행.
     */
    private function executeSqlViaMysqli(\mysqli $mysqli, string $sql): void
    {
        // 문자열·주석 안의 ; 를 안전하게 보존하며 문 분할
        $queries = (new SqlStatementSplitter())->split($sql);

        foreach ($queries as $query) {
            try {
                $mysqli->query($query);
            } catch (\mysqli_sql_exception $e) {
                if (MigrationErrorPolicy::canIgnoreMysqli($e->getCode(), $query)) {
                    continue;
                }
                throw $e;
            }
        }
    }

    /**
     * DB 마이그레이션 실행
     */
    public function runMigrations(array $config): array
    {
        try {
            $mysqli = $this->createMysqli($config);

            // 연결 테스트 버튼을 거치지 않고 바로 제출해도 하한 미만 서버에서
            // 파괴적인 재설치 초기화가 시작되지 않도록 마이그레이션 직전에 재검증한다.
            $compatibility = $this->serverCompatibility->inspect((string) $mysqli->server_info);
            if (!$compatibility['supported']) {
                $mysqli->close();
                return [
                    'success' => false,
                    'message' => $compatibility['message'],
                ];
            }

            // 마이그레이션 파일 경로
            $migrationPath = MUBLO_ROOT_PATH . '/database/migrations';

            if (!is_dir($migrationPath)) {
                $mysqli->close();
                return [
                    'success' => false,
                    'message' => '마이그레이션 디렉토리가 존재하지 않습니다.',
                ];
            }

            // 마이그레이션 파일 로드
            $files = glob($migrationPath . '/*.sql');
            sort($files);

            $executed = [];

            // 기존 테이블 삭제 (재설치 지원)
            $this->dropExistingTablesMysqli($mysqli);

            // 마이그레이션 추적 테이블 선행 생성
            $mysqli->query("CREATE TABLE IF NOT EXISTS `schema_migrations` (
                `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `source`      ENUM('core', 'plugin', 'package') NOT NULL DEFAULT 'plugin',
                `name`        VARCHAR(100) NOT NULL,
                `file`        VARCHAR(200) NOT NULL,
                `checksum`    CHAR(64) NULL,
                `executed_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY `uk_migration` (`source`, `name`, `file`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // Core 마이그레이션 실행
            foreach ($files as $file) {
                $sql = file_get_contents($file);
                $sql = $this->stripSqlComments($sql);
                if (!empty(trim($sql))) {
                    $this->executeSqlViaMysqli($mysqli, $sql);
                }
                $executed[] = basename($file);
            }

            // 마이그레이션 이력 기록
            $this->recordCoreMigrationsMysqli($mysqli, $executed);

            // default:true 패키지 마이그레이션 실행
            $defaultPackages = $this->getDefaultPackages();
            foreach ($defaultPackages as $packageName) {
                $pkgPath = MUBLO_PACKAGE_PATH . '/' . $packageName . '/database/migrations';
                if (!is_dir($pkgPath)) {
                    continue;
                }

                $pkgFiles = glob($pkgPath . '/*.sql') ?: [];
                sort($pkgFiles);

                foreach ($pkgFiles as $file) {
                    $sql = file_get_contents($file);
                    $sql = $this->stripSqlComments($sql);
                    if (!empty(trim($sql))) {
                        $this->executeSqlViaMysqli($mysqli, $sql);
                    }
                    $executed[] = $packageName . '/' . basename($file);
                }

                $this->recordPackageMigrationsMysqli($mysqli, $packageName, $pkgFiles);
            }

            $mysqli->close();

            return [
                'success' => true,
                'message' => count($executed) . '개 마이그레이션 파일 실행 완료',
                'files' => $executed,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => '마이그레이션 실행 실패: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * SQL 파일에서 -- 주석 라인 제거
     */
    private function stripSqlComments(string $sql): string
    {
        $lines = explode("\n", $sql);
        $lines = array_filter($lines, fn($line) => !str_starts_with(trim($line), '--'));
        return implode("\n", $lines);
    }

    /**
     * 실행된 Core 마이그레이션을 schema_migrations 테이블에 기록
     */
    private function recordCoreMigrationsMysqli(\mysqli $mysqli, array $filenames): void
    {
        $stmt = $mysqli->prepare(
            "INSERT IGNORE INTO `schema_migrations` (source, name, file, checksum) VALUES ('core', '__core__', ?, ?)"
        );
        if (!$stmt) {
            return;
        }
        foreach ($filenames as $filename) {
            $path = MUBLO_ROOT_PATH . '/database/migrations/' . $filename;
            $checksum = is_file($path) ? hash_file('sha256', $path) : null;
            $stmt->bind_param('ss', $filename, $checksum);
            $stmt->execute();
        }
        $stmt->close();
    }

    /**
     * manifest.json에서 default:true인 패키지 목록 반환
     */
    private function getDefaultPackages(): array
    {
        $packagePath = MUBLO_PACKAGE_PATH;
        if (!is_dir($packagePath)) {
            return [];
        }

        $defaults = [];
        foreach (glob($packagePath . '/*/manifest.json') as $manifestFile) {
            $manifest = json_decode(file_get_contents($manifestFile), true);
            if (!empty($manifest['default']) && !empty($manifest['name'])) {
                $defaults[] = $manifest['name'];
            }
        }

        return $defaults;
    }

    /**
     * 패키지 마이그레이션 이력 기록
     */
    private function recordPackageMigrationsMysqli(\mysqli $mysqli, string $packageName, array $files): void
    {
        $stmt = $mysqli->prepare(
            "INSERT IGNORE INTO `schema_migrations` (source, name, file, checksum) VALUES ('package', ?, ?, ?)"
        );
        if (!$stmt) {
            return;
        }
        foreach ($files as $file) {
            $basename = basename($file);
            $checksum = hash_file('sha256', $file);
            $stmt->bind_param('sss', $packageName, $basename, $checksum);
            $stmt->execute();
        }
        $stmt->close();
    }

    private function dropExistingTablesMysqli(\mysqli $mysqli): void
    {
        $mysqli->query('SET FOREIGN_KEY_CHECKS = 0');

        $result = $mysqli->query('SHOW TABLES');
        $tables = [];
        if ($result) {
            while ($row = $result->fetch_row()) {
                $tables[] = $row[0];
            }
            $result->free();
        }

        foreach ($tables as $table) {
            $mysqli->query("DROP TABLE IF EXISTS `{$table}`");
        }

        $mysqli->query('SET FOREIGN_KEY_CHECKS = 1');
    }
}
