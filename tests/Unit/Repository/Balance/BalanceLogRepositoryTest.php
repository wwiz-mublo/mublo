<?php
/**
 * tests/Unit/Repository/Balance/BalanceLogRepositoryTest.php
 *
 * BalanceLogRepository 테스트
 *
 * 포인트 변경 원장(balance_logs) 관리
 * Note: INSERT ONLY - 이 테이블은 UPDATE/DELETE 금지 (감사 추적용)
 */

namespace Tests\Unit\Repository\Balance;

use PHPUnit\Framework\TestCase;
use Mublo\Repository\Balance\BalanceLogRepository;
use Mublo\Entity\Balance\BalanceLog;
use Mublo\Infrastructure\Database\Database;
use Mublo\Infrastructure\Database\QueryBuilder;

class BalanceLogRepositoryTest extends TestCase
{
    private BalanceLogRepository $repository;
    private Database $db;
    private QueryBuilder $queryBuilder;

    protected function setUp(): void
    {
        $this->queryBuilder = $this->createMock(QueryBuilder::class);

        $this->db = $this->createMock(Database::class);
        $this->db->method('table')
            ->willReturn($this->queryBuilder);

        $this->repository = new BalanceLogRepository($this->db);
    }

    /**
     * 샘플 로그 데이터 생성
     */
    private function getSampleLogData(): array
    {
        return [
            'log_id' => 1,
            'domain_id' => 1,
            'member_id' => 100,
            'amount' => 500,
            'balance_before' => 1000,
            'balance_after' => 1500,
            'source_type' => 'plugin',
            'source_name' => 'MemberPoint',
            'action' => 'article_write',
            'message' => '게시글 작성 보상',
            'reference_type' => 'article',
            'reference_id' => '123',
            'ip_address' => '192.168.1.1',
            'admin_id' => null,
            'memo' => null,
            'idempotency_key' => 'article_write_123',
            'created_at' => '2025-01-29 10:00:00',
        ];
    }

    // ========================================
    // create (INSERT)
    // ========================================

    /**
     * create: 로그 생성 성공
     */
    public function testCreateInsertsLogAndReturnsId(): void
    {
        $this->queryBuilder->expects($this->once())
            ->method('insert')
            ->willReturn(1); // affected rows

        $this->db->method('lastInsertId')
            ->willReturn('100'); // string 반환 (PDO 스펙)

        $data = [
            'domain_id' => 1,
            'member_id' => 100,
            'amount' => 500,
            'balance_before' => 1000,
            'balance_after' => 1500,
            'source_type' => 'plugin',
            'source_name' => 'MemberPoint',
            'action' => 'article_write',
            'message' => '게시글 작성 보상',
        ];

        $result = $this->repository->create($data);

        $this->assertSame(100, $result);
    }

    // ========================================
    // findByIdempotencyKey (멱등성 키로 조회)
    // ========================================

    /**
     * findByIdempotencyKey: 키로 로그 조회 성공
     */
    public function testFindByIdempotencyKeyReturnsLog(): void
    {
        $this->queryBuilder->method('where')->willReturn($this->queryBuilder);
        $this->queryBuilder->method('first')->willReturn($this->getSampleLogData());

        $result = $this->repository->findByIdempotencyKey('article_write_123');

        $this->assertInstanceOf(BalanceLog::class, $result);
        $this->assertSame('article_write_123', $result->getIdempotencyKey());
    }

    /**
     * findByIdempotencyKey: 존재하지 않는 키
     */
    public function testFindByIdempotencyKeyReturnsNullWhenNotFound(): void
    {
        $this->queryBuilder->method('where')->willReturn($this->queryBuilder);
        $this->queryBuilder->method('first')->willReturn(null);

        $result = $this->repository->findByIdempotencyKey('non_existent_key');

        $this->assertNull($result);
    }

    // ========================================
    // getByMember (회원별 로그 목록)
    // ========================================

    /**
     * getByMember: 회원별 로그 목록 조회
     */
    public function testGetByMemberReturnsLogList(): void
    {
        $this->queryBuilder->method('where')->willReturn($this->queryBuilder);
        $this->queryBuilder->method('orderBy')->willReturn($this->queryBuilder);
        $this->queryBuilder->method('limit')->willReturn($this->queryBuilder);
        $this->queryBuilder->method('offset')->willReturn($this->queryBuilder);
        $this->queryBuilder->method('get')->willReturn([
            $this->getSampleLogData(),
            array_merge($this->getSampleLogData(), ['log_id' => 2]),
        ]);

        $result = $this->repository->getByMember(100, 1, 20);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertInstanceOf(BalanceLog::class, $result[0]);
    }

    /**
     * getByMember: 빈 결과
     */
    public function testGetByMemberReturnsEmptyArrayWhenNoLogs(): void
    {
        $this->queryBuilder->method('where')->willReturn($this->queryBuilder);
        $this->queryBuilder->method('orderBy')->willReturn($this->queryBuilder);
        $this->queryBuilder->method('limit')->willReturn($this->queryBuilder);
        $this->queryBuilder->method('offset')->willReturn($this->queryBuilder);
        $this->queryBuilder->method('get')->willReturn([]);

        $result = $this->repository->getByMember(100);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // ========================================
    // getSumByMember (원장 합계 - 무결성 검증용)
    // ========================================

    /**
     * getSumByMember: 회원 원장 합계 조회
     */
    public function testGetSumByMemberReturnsCorrectSum(): void
    {
        $this->queryBuilder->method('where')->willReturn($this->queryBuilder);
        $this->queryBuilder->method('sum')->willReturn(1500.0); // float 반환

        $result = $this->repository->getSumByMember(100);

        $this->assertSame(1500, $result);
    }

    /**
     * getSumByMember: 로그 없는 회원은 0 반환
     */
    public function testGetSumByMemberReturnsZeroWhenNoLogs(): void
    {
        $this->queryBuilder->method('where')->willReturn($this->queryBuilder);
        $this->queryBuilder->method('sum')->willReturn(0.0); // float 반환

        $result = $this->repository->getSumByMember(999);

        $this->assertSame(0, $result);
    }

    // ========================================
    // countByMember (회원별 로그 수)
    // ========================================

    /**
     * countByMember: 회원별 로그 수 조회
     */
    public function testCountByMemberReturnsCount(): void
    {
        $this->queryBuilder->method('where')->willReturn($this->queryBuilder);
        $this->queryBuilder->method('count')->willReturn(15);

        $result = $this->repository->countByMember(100);

        $this->assertSame(15, $result);
    }

}
