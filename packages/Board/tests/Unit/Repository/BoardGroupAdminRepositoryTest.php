<?php
/**
 * packages/Board/tests/Unit/Repository/BoardGroupAdminRepositoryTest.php
 *
 * BoardGroupAdminRepository 테스트
 *
 * 그룹 관리자 매핑 테이블(board_group_admins) 관리
 */

namespace Tests\Board\Unit\Repository;

use PHPUnit\Framework\TestCase;
use Mublo\Packages\Board\Repository\BoardGroupAdminRepository;
use Mublo\Infrastructure\Database\Database;
use Mublo\Infrastructure\Database\QueryBuilder;

class BoardGroupAdminRepositoryTest extends TestCase
{
    private BoardGroupAdminRepository $repository;
    private Database $db;
    private QueryBuilder $queryBuilder;

    protected function setUp(): void
    {
        $this->queryBuilder = $this->createMock(QueryBuilder::class);

        $this->db = $this->createMock(Database::class);
        $this->db->method('table')
            ->willReturn($this->queryBuilder);

        $this->repository = new BoardGroupAdminRepository($this->db);
    }

    /**
     * isAdmin: 특정 회원이 그룹 관리자인지 확인
     */
    public function testIsAdminReturnsTrueWhenAdmin(): void
    {
        $this->queryBuilder->method('where')->willReturn($this->queryBuilder);
        $this->queryBuilder->method('first')->willReturn(['group_id' => 1, 'member_id' => 10]);

        $result = $this->repository->isAdmin(1, 10);

        $this->assertTrue($result);
    }

    /**
     * isAdmin: 관리자가 아닐 때 false 반환
     */
    public function testIsAdminReturnsFalseWhenNotAdmin(): void
    {
        $this->queryBuilder->method('where')->willReturn($this->queryBuilder);
        $this->queryBuilder->method('first')->willReturn(null);

        $result = $this->repository->isAdmin(1, 99);

        $this->assertFalse($result);
    }

    /**
     * getAdminsByGroup: 특정 그룹의 관리자 목록 조회
     */
    public function testGetAdminsByGroupReturnsAdminIds(): void
    {
        $this->queryBuilder->method('where')->willReturn($this->queryBuilder);
        $this->queryBuilder->method('get')->willReturn([
            ['group_id' => 1, 'member_id' => 10],
            ['group_id' => 1, 'member_id' => 20],
        ]);

        $result = $this->repository->getAdminsByGroup(1);

        $this->assertSame([10, 20], $result);
    }

    /**
     * getGroupsByAdmin: 특정 회원이 관리하는 그룹 목록 조회
     */
    public function testGetGroupsByAdminReturnsGroupIds(): void
    {
        $this->queryBuilder->method('where')->willReturn($this->queryBuilder);
        $this->queryBuilder->method('get')->willReturn([
            ['group_id' => 1, 'member_id' => 10],
            ['group_id' => 2, 'member_id' => 10],
            ['group_id' => 5, 'member_id' => 10],
        ]);

        $result = $this->repository->getGroupsByAdmin(10);

        $this->assertSame([1, 2, 5], $result);
    }

    /**
     * addAdmin: 그룹 관리자 추가
     */
    public function testAddAdminInsertsRecord(): void
    {
        $this->queryBuilder->method('where')->willReturn($this->queryBuilder);
        $this->queryBuilder->method('first')->willReturn(null); // 중복 체크 - 없음
        $this->queryBuilder->expects($this->once())
            ->method('insert')
            ->willReturn(1); // insert returns affected rows

        $result = $this->repository->addAdmin(1, 10);

        $this->assertTrue($result);
    }

    /**
     * addAdmin: 이미 존재하면 false 반환
     */
    public function testAddAdminReturnsFalseIfAlreadyExists(): void
    {
        $this->queryBuilder->method('where')->willReturn($this->queryBuilder);
        $this->queryBuilder->method('first')->willReturn(['group_id' => 1, 'member_id' => 10]);

        $result = $this->repository->addAdmin(1, 10);

        $this->assertFalse($result);
    }

    /**
     * removeAdmin: 그룹 관리자 제거
     */
    public function testRemoveAdminDeletesRecord(): void
    {
        $this->queryBuilder->method('where')->willReturn($this->queryBuilder);
        $this->queryBuilder->expects($this->once())
            ->method('delete')
            ->willReturn(1);

        $result = $this->repository->removeAdmin(1, 10);

        $this->assertTrue($result);
    }

    /**
     * removeAllAdminsByGroup: 그룹의 모든 관리자 제거
     */
    public function testRemoveAllAdminsByGroupDeletesAll(): void
    {
        $this->queryBuilder->method('where')->willReturn($this->queryBuilder);
        $this->queryBuilder->expects($this->once())
            ->method('delete')
            ->willReturn(3);

        $result = $this->repository->removeAllAdminsByGroup(1);

        $this->assertSame(3, $result);
    }

    /**
     * syncAdmins: 그룹 관리자 동기화 (기존 삭제 후 새로 설정)
     */
    public function testSyncAdminsReplacesAllAdmins(): void
    {
        // 기존 관리자 삭제
        $this->queryBuilder->method('where')->willReturn($this->queryBuilder);
        $this->queryBuilder->method('delete')->willReturn(2);
        $this->queryBuilder->method('insert')->willReturn(1); // insert returns affected rows

        $result = $this->repository->syncAdmins(1, [10, 20, 30]);

        $this->assertTrue($result);
    }
}
