<?php

namespace Tests\Integration;

use Mublo\Infrastructure\Database\Database;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * 실 DB 통합 테스트 기반 클래스
 *
 * 왜 필요한가
 *   리포지토리 테스트가 전부 createMock(Database::class) 라서, 실제로 만들어지는 SQL 이
 *   검증된 적이 없다. QueryBuilder::selectRaw() 처럼 존재하지도 않는 메서드를 부르는
 *   코드가 오래 남아 있었고, 잘못된 컬럼명·JOIN 은 정적분석으로도 잡히지 않는다.
 *
 * 실행 조건
 *   환경변수가 있으면 실행하고, 없으면 스킵한다. CI 는 mariadb 서비스 컨테이너를 띄워 준다.
 *
 *     DB_TEST_HOST (기본 127.0.0.1)
 *     DB_TEST_PORT (기본 3306)
 *     DB_TEST_USER
 *     DB_TEST_PASS
 *     DB_TEST_NAME (기본 mublo_integration_test)
 *
 * 격리
 *   테스트 전용 데이터베이스를 만들고, 각 테스트가 자기 테이블을 정의한 뒤 끝나면 지운다.
 *   운영 DB 를 건드리지 않는다.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected static ?PDO $pdo = null;
    protected Database $db;

    /** @var string[] 이 테스트가 만든 테이블 */
    private array $createdTables = [];

    public static function setUpBeforeClass(): void
    {
        if (self::$pdo !== null) {
            return;
        }

        $user = getenv('DB_TEST_USER');
        if ($user === false || $user === '') {
            return; // setUp() 에서 스킵한다
        }

        $host = getenv('DB_TEST_HOST') ?: '127.0.0.1';
        $port = getenv('DB_TEST_PORT') ?: '3306';
        $pass = (string) getenv('DB_TEST_PASS');
        $name = getenv('DB_TEST_NAME') ?: 'mublo_integration_test';

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        try {
            $root = new PDO("mysql:host={$host};port={$port}", $user, $pass, $options);
            $root->exec("CREATE DATABASE IF NOT EXISTS `{$name}` DEFAULT CHARACTER SET utf8mb4");

            self::$pdo = new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", $user, $pass, $options);
        } catch (\Throwable) {
            self::$pdo = null; // 스킵
        }
    }

    protected function setUp(): void
    {
        if (self::$pdo === null) {
            $this->markTestSkipped('통합 테스트 DB 가 없습니다. DB_TEST_USER 환경변수를 설정하세요.');
        }

        $this->db = new Database(self::$pdo);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->createdTables) as $table) {
            self::$pdo->exec("DROP TABLE IF EXISTS `{$table}`");
        }
        $this->createdTables = [];
    }

    /**
     * 테스트용 테이블 생성. tearDown 에서 자동으로 지운다.
     *
     * @param string $columns "id INT PRIMARY KEY, name VARCHAR(50)" 형태
     */
    protected function createTable(string $table, string $columns): void
    {
        self::$pdo->exec("DROP TABLE IF EXISTS `{$table}`");
        self::$pdo->exec("CREATE TABLE `{$table}` ({$columns}) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->createdTables[] = $table;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    protected function seed(string $table, array $rows): void
    {
        foreach ($rows as $row) {
            $columns = implode(', ', array_map(fn($c) => "`{$c}`", array_keys($row)));
            $placeholders = implode(', ', array_fill(0, count($row), '?'));

            $statement = self::$pdo->prepare("INSERT INTO `{$table}` ({$columns}) VALUES ({$placeholders})");
            $statement->execute(array_values($row));
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function fetchAll(string $sql, array $bindings = []): array
    {
        $statement = self::$pdo->prepare($sql);
        $statement->execute($bindings);

        return $statement->fetchAll();
    }
}
