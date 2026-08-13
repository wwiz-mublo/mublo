<?php

namespace Tests\Unit\Core\Install;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Mublo\Core\Install\DatabaseConfigWriter;
use PDOException;

/**
 * DatabaseConfigWriterTest
 *
 * 설치 시 설정파일 생성/DDL 식별자 검증의 보안 회귀 테스트.
 */
class DatabaseConfigWriterTest extends TestCase
{
    private DatabaseConfigWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writer = new DatabaseConfigWriter();
    }

    /**
     * 악의적 입력이 생성된 설정파일에 실행 가능한 PHP 코드로 새지 않아야 한다.
     * (설정파일 코드 인젝션 방지)
     */
    public function testBuildConfigContentIsInjectionSafe(): void
    {
        $malicious = "'; system(\$_GET['x']); //";
        $config = [
            'host'      => "127.0.0.1'; echo 'x",
            'port'      => 3306,
            'database'  => 'mydb',
            'username'  => $malicious,
            'password'  => "p@ss'\"\\word",
            'charset'   => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ];

        $content = $this->writer->buildConfigContent($config);

        // 생성된 내용을 실제로 평가해도(=require 시뮬레이션) 값이 문자열로만 복원되어야 한다.
        $loaded = eval('?>' . $content);
        $conn = $loaded['connections']['mysql'];

        $this->assertSame($malicious, $conn['username']);
        $this->assertSame("127.0.0.1'; echo 'x", $conn['host']);
        // 비밀번호는 obfuscation되어 원문과 다르되, 구조는 유지되어야 한다.
        $this->assertTrue($conn['_encrypted']);
        $this->assertNotSame('', $conn['_encrypt_key']);
    }

    /**
     * DDL에 식별자로 들어가는 값(DB명/charset/collation)에 위험 문자가 있으면
     * DB 연결 전에 거부해야 한다. (설치 시 SQL 인젝션 방지)
     */
    public function testTestConnectionRejectsUnsafeDatabaseName(): void
    {
        $result = $this->writer->testConnection([
            'host'      => '127.0.0.1',
            'port'      => 3306,
            'database'  => 'ev`il); DROP',
            'username'  => 'u',
            'password'  => 'p',
            'charset'   => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('DB 이름', $result['message']);
    }

    public function testTestConnectionRejectsUnsafeCharset(): void
    {
        $result = $this->writer->testConnection([
            'host'      => '127.0.0.1',
            'port'      => 3306,
            'database'  => 'mydb',
            'username'  => 'u',
            'password'  => 'p',
            'charset'   => 'utf8mb4; DROP',
            'collation' => 'utf8mb4_unicode_ci',
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('문자셋', $result['message']);
    }

    /**
     * 접속 단계 예외는 errorInfo 가 비어 있을 수 있어 메시지에서 에러 번호를 읽는다.
     * 여기서 번호를 잘못 읽으면 "DB 없음" 판정이 어긋나 자동 생성 경로가 통째로 오작동한다.
     */
    #[DataProvider('mysqlErrorCodeCases')]
    public function testMysqlErrorCodeIsExtractedFromExceptionOrMessage(
        PDOException $exception,
        int $expected
    ): void {
        $this->assertSame($expected, $this->callPrivate('mysqlErrorCode', $exception));
    }

    public static function mysqlErrorCodeCases(): array
    {
        return [
            'errorInfo 우선' => [
                self::pdoException('무언가 실패', [null, 1049, 'Unknown database']),
                1049,
            ],
            'errorInfo 없으면 메시지에서 읽는다' => [
                self::pdoException("SQLSTATE[HY000] [1049] Unknown database 'nope'"),
                1049,
            ],
            // SQLSTATE(42000)는 5자리라 4자리 패턴에 걸리지 않아야 한다.
            // 여기서 42000을 집으면 권한 오류가 "DB 없음"으로 오판된다.
            'SQLSTATE를 에러번호로 오인하지 않는다' => [
                self::pdoException("SQLSTATE[42000] [1044] Access denied for user 'u' to database 'd'"),
                1044,
            ],
            '판별 불가' => [
                self::pdoException('알 수 없는 실패'),
                0,
            ],
        ];
    }

    /**
     * 실패 원인이 서로 다른 문장으로 갈려야 한다. 이전에는 전부
     * 'DB 연결 실패: <드라이버 원문>' 하나로 합쳐져 원인을 구분할 수 없었다.
     */
    #[DataProvider('connectionFailureCases')]
    public function testConnectionFailureMessageIsActionable(
        PDOException $exception,
        string $expectedFragment
    ): void {
        $config = ['host' => 'localhost', 'port' => 3306, 'database' => 'mydb'];

        $message = $this->callPrivate('connectionFailureMessage', $exception, $config);

        $this->assertStringContainsString($expectedFragment, $message);
    }

    public static function connectionFailureCases(): array
    {
        return [
            '비밀번호 오류' => [
                self::pdoException('denied', [null, 1045, '']),
                '사용자명 또는 비밀번호',
            ],
            // 제한된 계정에는 "없는 DB"도 1044 로 온다 — 두 해법이 모두 담겨야 한다.
            'DB 권한 없음 / 없는 DB' => [
                self::pdoException('denied', [null, 1044, '']),
                '호스팅 관리 페이지에서 데이터베이스를 먼저 만든 뒤',
            ],
            '서버 접속 불가' => [
                self::pdoException('refused', [null, 2002, '']),
                'localhost:3306',
            ],
            '분류되지 않은 실패는 원문을 보존한다' => [
                self::pdoException('디스크 가득 참', [null, 1021, '']),
                '디스크 가득 참',
            ],
        ];
    }

    private static function pdoException(string $message, ?array $errorInfo = null): PDOException
    {
        $exception = new PDOException($message);
        $exception->errorInfo = $errorInfo;

        return $exception;
    }

    private function callPrivate(string $method, mixed ...$args): mixed
    {
        $reflection = new \ReflectionMethod(DatabaseConfigWriter::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($this->writer, ...$args);
    }
}
