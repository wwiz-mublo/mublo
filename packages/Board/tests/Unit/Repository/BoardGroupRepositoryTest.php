<?php
/**
 * packages/Board/tests/Unit/Repository/BoardGroupRepositoryTest.php
 *
 * BoardGroupRepository 테스트
 */

namespace Tests\Board\Unit\Repository;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Mublo\Packages\Board\Repository\BoardGroupRepository;
use Mublo\Packages\Board\Entity\BoardGroup;
use Mublo\Infrastructure\Database\Database;
use Mublo\Infrastructure\Database\QueryBuilder;

class BoardGroupRepositoryTest extends TestCase
{
    private MockObject $dbMock;
    private MockObject $queryBuilderMock;
    private BoardGroupRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dbMock = $this->createMock(Database::class);
        $this->queryBuilderMock = $this->createMock(QueryBuilder::class);

        // Database->table() 호출 시 QueryBuilder 반환
        $this->dbMock->method('table')
            ->willReturn($this->queryBuilderMock);

        $this->repository = new BoardGroupRepository($this->dbMock);
    }

    /**
     * 기본 데이터 배열 반환
     */
    private function getSampleData(): array
    {
        return [
            'group_id' => 1,
            'domain_id' => 1,
            'group_slug' => 'community',
            'group_name' => '커뮤니티',
            'group_description' => '커뮤니티 게시판 그룹',
            'group_admin_ids' => '[1, 2]',
            'list_level' => 0,
            'read_level' => 0,
            'write_level' => 1,
            'comment_level' => 1,
            'download_level' => 0,
            'sort_order' => 1,
            'is_active' => 1,
            'created_at' => '2025-01-01 00:00:00',
            'updated_at' => '2025-01-01 00:00:00',
        ];
    }

    /**
     * find 메서드 테스트 - 존재하는 ID
     */
    public function testFindReturnsEntityWhenFound(): void
    {
        $data = $this->getSampleData();

        $this->queryBuilderMock->method('where')
            ->willReturnSelf();
        $this->queryBuilderMock->method('first')
            ->willReturn($data);

        $result = $this->repository->find(1);

        $this->assertInstanceOf(BoardGroup::class, $result);
        $this->assertSame(1, $result->getGroupId());
        $this->assertSame('community', $result->getGroupSlug());
    }

    /**
     * find 메서드 테스트 - 존재하지 않는 ID
     */
    public function testFindReturnsNullWhenNotFound(): void
    {
        $this->queryBuilderMock->method('where')
            ->willReturnSelf();
        $this->queryBuilderMock->method('first')
            ->willReturn(null);

        $result = $this->repository->find(999);

        $this->assertNull($result);
    }

    /**
     * findByDomain 메서드 테스트
     */
    public function testFindByDomainReturnsEntityArray(): void
    {
        $data1 = $this->getSampleData();
        $data2 = $this->getSampleData();
        $data2['group_id'] = 2;
        $data2['group_slug'] = 'notice';

        $this->queryBuilderMock->method('where')
            ->willReturnSelf();
        $this->queryBuilderMock->method('orderBy')
            ->willReturnSelf();
        $this->queryBuilderMock->method('limit')
            ->willReturnSelf();
        $this->queryBuilderMock->method('offset')
            ->willReturnSelf();
        $this->queryBuilderMock->method('get')
            ->willReturn([$data1, $data2]);

        $result = $this->repository->findByDomain(1);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertInstanceOf(BoardGroup::class, $result[0]);
        $this->assertInstanceOf(BoardGroup::class, $result[1]);
    }

    /**
     * findBySlug 메서드 테스트
     */
    public function testFindBySlugReturnsEntity(): void
    {
        $data = $this->getSampleData();

        $this->queryBuilderMock->method('where')
            ->willReturnSelf();
        $this->queryBuilderMock->method('first')
            ->willReturn($data);

        $result = $this->repository->findBySlug(1, 'community');

        $this->assertInstanceOf(BoardGroup::class, $result);
        $this->assertSame('community', $result->getGroupSlug());
    }

    public function testFindByIdForDomainRequiresBothGroupAndDomain(): void
    {
        $whereCalls = [];
        $query = $this->queryBuilderMock;
        $this->queryBuilderMock->expects($this->exactly(2))
            ->method('where')
            ->willReturnCallback(function (string $field, string $operator, mixed $value) use (&$whereCalls, $query): QueryBuilder {
                $whereCalls[] = [$field, $operator, $value];
                return $query;
            });
        $this->queryBuilderMock->method('first')->willReturn($this->getSampleData());

        $result = $this->repository->findByIdForDomain(1, 1);

        $this->assertContains(['group_id', '=', 1], $whereCalls);
        $this->assertContains(['domain_id', '=', 1], $whereCalls);
        $this->assertInstanceOf(BoardGroup::class, $result);
        $this->assertSame(1, $result->getDomainId());
    }

    /**
     * create 메서드 테스트
     */
    public function testCreateReturnsInsertedId(): void
    {
        $data = [
            'domain_id' => 1,
            'group_slug' => 'new-group',
            'group_name' => '새 그룹',
        ];

        $this->queryBuilderMock->method('insert')
            ->willReturn(5);

        $result = $this->repository->create($data);

        $this->assertSame(5, $result);
    }

    /**
     * update 메서드 테스트
     */
    public function testUpdateReturnsAffectedRows(): void
    {
        $data = [
            'group_name' => '수정된 그룹명',
        ];

        $this->queryBuilderMock->method('where')
            ->willReturnSelf();
        $this->queryBuilderMock->method('update')
            ->willReturn(1);

        $result = $this->repository->update(1, $data);

        $this->assertSame(1, $result);
    }

    /**
     * delete 메서드 테스트
     */
    public function testDeleteReturnsAffectedRows(): void
    {
        $this->queryBuilderMock->method('where')
            ->willReturnSelf();
        $this->queryBuilderMock->method('delete')
            ->willReturn(1);

        $result = $this->repository->delete(1);

        $this->assertSame(1, $result);
    }

    /**
     * existsBySlug 메서드 테스트 - 존재하는 경우
     */
    public function testExistsBySlugReturnsTrueWhenExists(): void
    {
        $this->queryBuilderMock->method('where')
            ->willReturnSelf();
        $this->queryBuilderMock->method('exists')
            ->willReturn(true);

        $result = $this->repository->existsBySlug(1, 'community');

        $this->assertTrue($result);
    }

    /**
     * existsBySlug 메서드 테스트 - 존재하지 않는 경우
     */
    public function testExistsBySlugReturnsFalseWhenNotExists(): void
    {
        $this->queryBuilderMock->method('where')
            ->willReturnSelf();
        $this->queryBuilderMock->method('exists')
            ->willReturn(false);

        $result = $this->repository->existsBySlug(1, 'nonexistent');

        $this->assertFalse($result);
    }

    /**
     * countByDomain 메서드 테스트
     */
    public function testCountByDomainReturnsCount(): void
    {
        $this->queryBuilderMock->method('where')
            ->willReturnSelf();
        $this->queryBuilderMock->method('count')
            ->willReturn(5);

        $result = $this->repository->countByDomain(1);

        $this->assertSame(5, $result);
    }

    /**
     * findActiveByDomain 메서드 테스트
     */
    public function testFindActiveByDomainReturnsOnlyActiveGroups(): void
    {
        $data = $this->getSampleData();

        $this->queryBuilderMock->method('where')
            ->willReturnSelf();
        $this->queryBuilderMock->method('orderBy')
            ->willReturnSelf();
        $this->queryBuilderMock->method('get')
            ->willReturn([$data]);

        $result = $this->repository->findActiveByDomain(1);

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertTrue($result[0]->isActive());
    }

    /**
     * updateOrder 메서드 테스트
     */
    public function testUpdateOrderUpdatesMultipleRecords(): void
    {
        // updateOrder는 여러 레코드의 sort_order를 업데이트
        $this->queryBuilderMock->method('where')
            ->willReturnSelf();
        $this->queryBuilderMock->method('update')
            ->willReturn(1);

        // 3개 그룹의 순서 변경
        $groupIds = [3, 1, 2];
        $result = $this->repository->updateOrder($groupIds);

        $this->assertTrue($result);
    }
}
