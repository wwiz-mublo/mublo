<?php

namespace Tests\Integration;

use Mublo\Infrastructure\Database\DatabaseException;

/**
 * Database 계층을 실 DB 로 검증한다.
 *
 * 이 파일은 오랫동안 assertTrue(true) 스텁 3개였다. 통합 테스트 스위트가 있는데
 * 아무것도 검증하지 않아, 리포지토리 계층의 SQL 이 한 번도 실행된 적이 없었다.
 */
class DatabaseTest extends DatabaseTestCase
{
    // ========================================
    // 연결
    // ========================================

    public function testConnectionIsUsable(): void
    {
        $row = $this->db->selectOne('SELECT 1 AS ok');

        $this->assertSame(1, (int) $row['ok']);
    }

    public function testSelectOneReturnsNullWhenNoRow(): void
    {
        $this->assertNull($this->db->selectOne('SELECT 1 AS ok WHERE 1 = 0'));
    }

    public function testBindingsArePassedAsParameters(): void
    {
        $row = $this->db->selectOne('SELECT ? AS value', ["1' OR '1'='1"]);

        $this->assertSame("1' OR '1'='1", $row['value'], '바인딩 값이 SQL 로 해석되면 안 된다');
    }

    // ========================================
    // insert / execute / lastInsertId
    // ========================================

    public function testInsertReturnsLastInsertId(): void
    {
        $this->createTable('it_notes', 'id INT AUTO_INCREMENT PRIMARY KEY, body VARCHAR(50) NOT NULL');

        $first = $this->db->insert('INSERT INTO it_notes (body) VALUES (?)', ['a']);
        $second = $this->db->insert('INSERT INTO it_notes (body) VALUES (?)', ['b']);

        $this->assertSame($first + 1, $second);
    }

    public function testExecuteReturnsAffectedRowCount(): void
    {
        $this->createTable('it_notes', 'id INT AUTO_INCREMENT PRIMARY KEY, body VARCHAR(50) NOT NULL');
        $this->seed('it_notes', [['body' => 'a'], ['body' => 'b'], ['body' => 'c']]);

        $affected = $this->db->execute("UPDATE it_notes SET body = 'x' WHERE body IN (?, ?)", ['a', 'b']);

        $this->assertSame(2, $affected);
    }

    public function testInvalidSqlThrowsDatabaseException(): void
    {
        $this->expectException(DatabaseException::class);

        $this->db->select('SELECT * FROM table_that_does_not_exist_here');
    }

    // ========================================
    // 트랜잭션 — 블록 킷 적용·주문 처리가 의존하는 경로
    // ========================================

    public function testCommitPersistsChanges(): void
    {
        $this->createTable('it_notes', 'id INT AUTO_INCREMENT PRIMARY KEY, body VARCHAR(50) NOT NULL');

        $this->db->beginTransaction();
        $this->db->insert('INSERT INTO it_notes (body) VALUES (?)', ['kept']);
        $this->db->commit();

        $this->assertCount(1, $this->fetchAll('SELECT * FROM it_notes'));
    }

    public function testRollbackDiscardsChanges(): void
    {
        $this->createTable('it_notes', 'id INT AUTO_INCREMENT PRIMARY KEY, body VARCHAR(50) NOT NULL');

        $this->db->beginTransaction();
        $this->db->insert('INSERT INTO it_notes (body) VALUES (?)', ['dropped']);
        $this->db->rollBack();

        $this->assertSame([], $this->fetchAll('SELECT * FROM it_notes'));
    }

    public function testInTransactionReflectsState(): void
    {
        $this->assertFalse($this->db->inTransaction());

        $this->db->beginTransaction();
        $this->assertTrue($this->db->inTransaction());

        $this->db->rollBack();
        $this->assertFalse($this->db->inTransaction());
    }

    /**
     * transaction() 헬퍼는 콜백이 예외를 던지면 롤백해야 한다.
     * BlockKitApplier 와 주문 처리가 이 계약에 의존한다.
     *
     * 주의: 콜백의 예외는 그대로 전파되지 않고 DatabaseException 으로 감싸인다.
     * 원인 예외는 previous 에 보존되므로, 호출자가 자기 예외 타입으로 분기하려면
     * getPrevious() 를 봐야 한다. 이 동작을 여기서 고정한다.
     */
    public function testTransactionHelperRollsBackOnException(): void
    {
        $this->createTable('it_notes', 'id INT AUTO_INCREMENT PRIMARY KEY, body VARCHAR(50) NOT NULL');

        try {
            $this->db->transaction(function () {
                $this->db->insert('INSERT INTO it_notes (body) VALUES (?)', ['half']);
                throw new \RuntimeException('boom');
            });
            $this->fail('예외가 전파되어야 한다');
        } catch (DatabaseException $e) {
            $this->assertStringContainsString('boom', $e->getMessage());
            $this->assertInstanceOf(\RuntimeException::class, $e->getPrevious(), '원인 예외를 보존해야 한다');
        }

        $this->assertSame([], $this->fetchAll('SELECT * FROM it_notes'));
        $this->assertFalse($this->db->inTransaction(), '롤백 후 트랜잭션이 닫혀야 한다');
    }

    public function testTransactionHelperCommitsOnSuccess(): void
    {
        $this->createTable('it_notes', 'id INT AUTO_INCREMENT PRIMARY KEY, body VARCHAR(50) NOT NULL');

        $result = $this->db->transaction(function () {
            $this->db->insert('INSERT INTO it_notes (body) VALUES (?)', ['done']);

            return 'ok';
        });

        $this->assertSame('ok', $result);
        $this->assertCount(1, $this->fetchAll('SELECT * FROM it_notes'));
    }
}
