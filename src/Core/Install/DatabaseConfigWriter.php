<?php
declare(strict_types=1);

namespace Mublo\Core\Install;

use Mublo\Core\ConfigFile;
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
    /** 지정한 데이터베이스가 없음 (ER_BAD_DB_ERROR) */
    private const ER_BAD_DB = 1049;

    /** 데이터베이스는 있으나 이 계정에 권한이 없음 (ER_DBACCESS_DENIED_ERROR) */
    private const ER_DBACCESS_DENIED = 1044;

    /** 사용자명/비밀번호 불일치 (ER_ACCESS_DENIED_ERROR) */
    private const ER_ACCESS_DENIED = 1045;

    /** 서버에 접속 자체가 불가 (CR_CONN_HOST_ERROR / CR_UNKNOWN_HOST) */
    private const CR_CONN_HOST_ERROR = 2002;
    private const CR_UNKNOWN_HOST = 2005;

    private CryptoManager $crypto;
    private DatabaseServerCompatibility $serverCompatibility;

    public function __construct(?DatabaseServerCompatibility $serverCompatibility = null)
    {
        $this->crypto = new CryptoManager();
        $this->serverCompatibility = $serverCompatibility ?? new DatabaseServerCompatibility();
    }
    /**
     * DB 연결 테스트
     *
     * 존재 여부를 서버에 "물어보지" 않고 그냥 붙어본다. 목록 조회(SHOW DATABASES)는
     * 글로벌 권한을 요구해서, 그 권한을 주지 않는 공유호스팅에서는 접속이 멀쩡한데도
     * 문장 자체가 거절당했다. 정작 설치에 필요한 권한은 "그 DB에 붙을 수 있는가" 하나뿐이고,
     * 그건 붙어보면 알 수 있다. 목록에 보인다고 쓸 수 있는 것도 아니므로 더 정확하기도 하다.
     *
     * 실제 설치 단계가 쓰는 연결(dbname 포함)과 같은 방식으로 붙는다는 점도 중요하다 —
     * 테스트가 통과했는데 설치가 실패하는 경우를 만들지 않는다.
     */
    public function testConnection(array $config): array
    {
        // DDL(CREATE DATABASE)에 식별자로 보간되는 값 검증 — 설치 시 SQL 인젝션 방지.
        foreach (['database' => 'DB 이름', 'charset' => '문자셋', 'collation' => '콜레이션'] as $key => $label) {
            $value = (string) ($config[$key] ?? '');
            if (!preg_match('/^[A-Za-z0-9_]+$/', $value)) {
                return [
                    'success' => false,
                    'message' => "{$label}에 허용되지 않은 문자가 있습니다. (영문/숫자/밑줄만 가능)",
                ];
            }
        }

        $dbCreated = false;
        $compatibility = null;

        try {
            $pdo = $this->openConnection($config, true);
        } catch (PDOException $e) {
            // 없는 DB 외의 실패는 여기서 끝낸다. 계정 오류나 서버 접속 불가를
            // "DB가 없나 보다" 로 넘겨 CREATE 를 시도하면 원인이 흐려진다.
            if ($this->mysqlErrorCode($e) !== self::ER_BAD_DB) {
                return [
                    'success' => false,
                    'message' => $this->connectionFailureMessage($e, $config),
                ];
            }

            // DB가 없다 — 여기서부터가 자동 생성 경로다.
            try {
                $serverPdo = $this->openConnection($config, false);
            } catch (PDOException $serverException) {
                return [
                    'success' => false,
                    'message' => $this->connectionFailureMessage($serverException, $config),
                ];
            }

            // 지원되지 않는 서버에 DB를 만들어 두고 나중에 막히지 않도록 생성 전에 판정한다.
            $compatibility = $this->inspectServer($serverPdo);
            if (!$compatibility['supported']) {
                return $this->unsupportedServerResult($compatibility);
            }

            try {
                $serverPdo->exec("CREATE DATABASE `{$config['database']}`
                                  CHARACTER SET {$config['charset']}
                                  COLLATE {$config['collation']}");
            } catch (PDOException $createException) {
                return [
                    'success' => false,
                    'message' => "데이터베이스 '{$config['database']}' 가 존재하지 않고, 이 계정에는 생성 권한도 없습니다."
                        . ' 호스팅 관리 페이지에서 데이터베이스를 먼저 만든 뒤 그 이름을 입력하세요.'
                        . ' (서버 응답: ' . $createException->getMessage() . ')',
                ];
            }

            $dbCreated = true;

            try {
                $pdo = $this->openConnection($config, true);
            } catch (PDOException $reconnectException) {
                return [
                    'success' => false,
                    'message' => "데이터베이스 '{$config['database']}' 를 만들었으나 접속하지 못했습니다: "
                        . $reconnectException->getMessage(),
                ];
            }
        }

        // 생성 경로에서 이미 판정했다면 다시 묻지 않는다 — 같은 서버다.
        if ($compatibility === null) {
            $compatibility = $this->inspectServer($pdo);
            if (!$compatibility['supported']) {
                return $this->unsupportedServerResult($compatibility);
            }
        }

        return [
            'success' => true,
            'message' => $dbCreated
                ? "DB 연결 성공 (데이터베이스 '{$config['database']}' 생성됨)"
                : 'DB 연결 성공',
            'server_engine' => $compatibility['engine'],
            'server_version' => $compatibility['version'],
            'minimum_version' => $compatibility['minimum'],
            'db_created' => $dbCreated,
        ];
    }

    /**
     * 설치용 PDO 연결 생성
     *
     * $withDatabase 가 true 면 실제 설치 단계와 동일하게 dbname 을 포함해 붙는다.
     * false 는 자동 생성(CREATE DATABASE) 전용 — 없는 DB에는 붙을 수 없기 때문이다.
     */
    private function openConnection(array $config, bool $withDatabase): PDO
    {
        $dsn = $withDatabase
            ? sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                $config['host'],
                $config['port'],
                $config['database'],
                $config['charset']
            )
            : sprintf(
                'mysql:host=%s;port=%s;charset=%s',
                $config['host'],
                $config['port'],
                $config['charset']
            );

        return new PDO(
            $dsn,
            $config['username'],
            $config['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    }

    /**
     * @return array{supported: bool, engine: string, version: string|null, minimum: string|null, message: string, raw: string}
     */
    private function inspectServer(PDO $pdo): array
    {
        // testConnection() 은 예외를 던지지 않고 항상 결과 배열을 돌려주는 계약이다
        // (step2 의 저장 경로는 이 호출을 감싸지 않는다). 버전을 읽지 못하면 판별 불가로
        // 떨어뜨려 실패 배열이 되게 한다 — 호환성을 확인하지 못한 채 통과시키지 않는다.
        try {
            $raw = (string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
        } catch (PDOException) {
            $raw = '';
        }

        return $this->serverCompatibility->inspect($raw) + ['raw' => $raw];
    }

    /**
     * @param array{supported: bool, engine: string, version: string|null, minimum: string|null, message: string, raw: string} $compatibility
     */
    private function unsupportedServerResult(array $compatibility): array
    {
        return [
            'success' => false,
            'message' => $compatibility['message'],
            'server_engine' => $compatibility['engine'],
            'server_version' => $compatibility['version'] ?? $compatibility['raw'],
            'minimum_version' => $compatibility['minimum'],
        ];
    }

    /**
     * PDOException 에서 MySQL 에러 번호를 뽑는다.
     *
     * 접속 단계(new PDO)에서 던져진 예외는 errorInfo 가 비어 있는 경우가 있어
     * 메시지의 `SQLSTATE[HY000] [1049] ...` 형태를 폴백으로 읽는다. SQLSTATE 는
     * 5자리라 4자리만 받는 패턴에 걸리지 않는다.
     */
    private function mysqlErrorCode(PDOException $e): int
    {
        $code = (int) ($e->errorInfo[1] ?? 0);
        if ($code > 0) {
            return $code;
        }

        if (preg_match('/\[(\d{4})\]/', $e->getMessage(), $matches) === 1) {
            return (int) $matches[1];
        }

        return 0;
    }

    /**
     * 접속 실패 원인을 운영자가 고칠 수 있는 문장으로 바꾼다.
     *
     * 이전에는 모든 실패가 'DB 연결 실패: <드라이버 원문>' 하나로 합쳐져서,
     * 비밀번호가 틀린 것인지 DB 이름이 틀린 것인지 구분되지 않았다.
     */
    private function connectionFailureMessage(PDOException $e, array $config): string
    {
        return match ($this->mysqlErrorCode($e)) {
            // 권한이 제한된 계정에는 서버가 "없는 DB"와 "권한 없는 DB"를 구분해 주지 않는다.
            // 둘 다 1044로 돌아오므로 양쪽 해법을 함께 안내한다.
            self::ER_DBACCESS_DENIED => "데이터베이스 '{$config['database']}' 를 사용할 수 없습니다."
                . ' 이름이 정확한지 확인하시고, 아직 만들지 않았다면 호스팅 관리 페이지에서 데이터베이스를 먼저 만든 뒤'
                . ' 그 이름을 입력하세요. 이름이 맞다면 이 계정에 해당 데이터베이스 권한이 부여되어 있는지 확인이 필요합니다.',
            self::ER_ACCESS_DENIED => 'DB 사용자명 또는 비밀번호가 올바르지 않습니다.',
            self::CR_CONN_HOST_ERROR, self::CR_UNKNOWN_HOST =>
                "데이터베이스 서버({$config['host']}:{$config['port']})에 접속할 수 없습니다."
                . ' 호스트와 포트를 확인하세요. 공유호스팅은 localhost 가 아닌 별도 DB 서버 주소를 쓰는 경우가 있습니다.',
            default => 'DB 연결 실패: ' . $e->getMessage(),
        };
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

        // 같은 요청에서 이미 "없음" 으로 캐시됐을 수 있다 — 방금 쓴 값이 보이게 비운다.
        ConfigFile::reset();

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
