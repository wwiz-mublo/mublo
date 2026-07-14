<?php

namespace Tests\Unit\Core\Install;

use PHPUnit\Framework\TestCase;
use Mublo\Core\Install\DatabaseConfigWriter;

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
}
