<?php
namespace Mublo\Infrastructure\Database;

use PDO;
use PDOException;
use Mublo\Infrastructure\Crypto\CryptoManager;

/**
 * Database Manager
 *
 * 데이터베이스 연결 관리 (싱글톤)
 * - config/database.php 설정 기반 연결
 * - 연결 재사용
 * - 에러 처리
 */
class DatabaseManager
{
    protected static ?DatabaseManager $instance = null;
    protected ?Database $database = null;
    protected array $config = [];
    protected CryptoManager $crypto;

    /**
     * Private Constructor (싱글톤)
     */
    private function __construct()
    {
        $this->crypto = new CryptoManager();
    }

    /**
     * 싱글톤 인스턴스 반환
     *
     * @return DatabaseManager
     */
    public static function getInstance(): DatabaseManager
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * 데이터베이스 설정
     *
     * @param array $config 설정 배열
     * @return self
     */
    public function setConfig(array $config): self
    {
        $this->config = $config;
        return $this;
    }

    /**
     * config/database.php에서 설정 로드
     *
     * @return self
     * @throws DatabaseException
     */
    public function loadFromConfig(): self
    {
        $configPath = MUBLO_CONFIG_PATH . '/database.php';

        if (!file_exists($configPath)) {
            throw DatabaseException::configError("Database config file not found: {$configPath}");
        }

        $rawConfig = require $configPath;

        // 현재 connection 이름 (기본: mysql)
        $connectionName = $rawConfig['connection'] ?? 'mysql';

        // connections 배열에서 해당 connection 설정 가져오기
        if (!isset($rawConfig['connections'][$connectionName])) {
            throw DatabaseException::configError("Connection not found: {$connectionName}");
        }

        $config = $rawConfig['connections'][$connectionName];
        $driver = $config['driver'] ?? 'mysql';

        // 암호화된 비밀번호 복호화
        $password = $config['password'] ?? '';
        if (!empty($config['_encrypted']) && !empty($config['_encrypt_key'])) {
            $password = $this->crypto->decrypt($password, $config['_encrypt_key']);
        }

        // SQLite는 host, username, password 불필요
        if ($driver === 'sqlite') {
            if (empty($config['database'])) {
                throw DatabaseException::configError('Missing required config: database');
            }

            $this->config = [
                'driver'   => 'sqlite',
                'database' => $config['database'],
            ];
        } else {
            // MySQL, PostgreSQL 등
            $required = ['host', 'database', 'username'];

            foreach ($required as $key) {
                if (empty($config[$key])) {
                    throw DatabaseException::configError("Missing required config: {$key}");
                }
            }

            $this->config = [
                'driver'    => $driver,
                'host'      => $config['host'],
                'port'      => $config['port'] ?? 3306,
                'database'  => $config['database'],
                'username'  => $config['username'],
                'password'  => $password,
                'charset'   => $config['charset'] ?? 'utf8mb4',
                'collation' => $config['collation'] ?? 'utf8mb4_unicode_ci',
            ];
        }

        return $this;
    }

    /**
     * 설정 로드 (loadFromConfig 별칭)
     *
     * @return self
     * @throws DatabaseException
     * @deprecated Use loadFromConfig() instead
     */
    public function loadFromEnv(): self
    {
        return $this->loadFromConfig();
    }

    /**
     * 데이터베이스 연결
     *
     * @return Database
     * @throws DatabaseException
     */
    public function connect(): Database
    {
        if ($this->database !== null) {
            return $this->database;
        }

        if (empty($this->config)) {
            $this->loadFromConfig();
        }

        try {
            $pdo = $this->createPdo();
            $this->database = new Database($pdo);

            return $this->database;
        } catch (PDOException $e) {
            throw DatabaseException::connectionFailed($e->getMessage(), $e);
        }
    }

    /**
     * PDO 인스턴스 생성
     *
     * @return PDO
     * @throws PDOException
     */
    protected function createPdo(): PDO
    {
        $driver = $this->config['driver'];
        $database = $this->config['database'];

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        // SQLite
        if ($driver === 'sqlite') {
            $dsn = "sqlite:{$database}";

            return new PDO($dsn, null, null, $options);
        }

        // MySQL, PostgreSQL 등
        $host = $this->config['host'];
        $port = $this->config['port'];
        $charset = $this->config['charset'];

        $dsn = "{$driver}:host={$host};port={$port};dbname={$database};charset={$charset}";

        // MySQL 전용 설정
        if ($driver === 'mysql') {
            $options[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET NAMES {$charset} COLLATE {$this->config['collation']}";
        }

        return new PDO(
            $dsn,
            $this->config['username'],
            $this->config['password'],
            $options
        );
    }

    /**
     * 현재 Database 인스턴스 반환
     *
     * @return Database|null
     */
    public function getDatabase(): ?Database
    {
        return $this->database;
    }

    /**
     * 연결 테스트
     *
     * @return bool
     */
    public function testConnection(): bool
    {
        try {
            $db = $this->connect();
            $db->select("SELECT 1");
            return true;
        } catch (DatabaseException $e) {
            return false;
        }
    }

    /**
     * 연결 종료
     */
    public function disconnect(): void
    {
        $this->database = null;
    }

    /**
     * Clone 방지 (싱글톤)
     */
    private function __clone() {}

    /**
     * Unserialize 방지 (싱글톤)
     */
    public function __wakeup()
    {
        throw new \Exception('Cannot unserialize singleton');
    }
}
