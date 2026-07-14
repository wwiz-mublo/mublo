<?php

namespace Tests\Integration;

use Mublo\Infrastructure\Database\Database;
use Mublo\Repository\BaseRepository;

/**
 * BaseRepository 를 실 DB 로 검증한다.
 *
 * 모든 리포지토리가 이것을 상속하는데, 지금까지 테스트가 전부 createMock(Database::class) 라서
 * 여기서 만들어지는 SQL 이 한 번도 실행된 적이 없다. 잘못된 컬럼명·타임스탬프 처리·페이지네이션
 * 오프셋 같은 문제는 실 DB 를 태워야 드러난다.
 */
class BaseRepositoryTest extends DatabaseTestCase
{
    private BaseRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTable('it_widgets', '
            id INT AUTO_INCREMENT PRIMARY KEY,
            domain_id INT NOT NULL,
            name VARCHAR(50) NOT NULL,
            is_deleted TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NULL,
            updated_at DATETIME NULL
        ');

        $this->repository = $this->makeRepository($this->db);
    }

    private function makeRepository(Database $db): BaseRepository
    {
        return new class ($db) extends BaseRepository {
            protected string $table = 'it_widgets';
            protected string $entityClass = \stdClass::class;
            protected string $primaryKey = 'id';

            public function __construct(Database $db)
            {
                parent::__construct($db);
            }
        };
    }

    // ========================================
    // create / find
    // ========================================

    public function testCreateReturnsIdAndRowIsReadable(): void
    {
        $id = $this->repository->create(['domain_id' => 1, 'name' => 'alpha']);

        $this->assertGreaterThan(0, $id);

        $found = $this->repository->find($id);
        $this->assertNotNull($found);
        $this->assertSame('alpha', $found->name);
    }

    public function testFindReturnsNullForMissingId(): void
    {
        $this->assertNull($this->repository->find(99999));
    }

    /**
     * created_at / updated_at 는 컬럼이 있을 때만 자동으로 채워야 한다.
     * 없는 컬럼을 넣으려 하면 INSERT 가 터진다.
     */
    public function testTimestampsAreWrittenWhenColumnsExist(): void
    {
        $id = $this->repository->create(['domain_id' => 1, 'name' => 'stamped']);

        $row = $this->fetchAll('SELECT created_at, updated_at FROM it_widgets WHERE id = ?', [$id])[0];

        $this->assertNotNull($row['created_at'], 'created_at 이 채워져야 한다');
    }

    // ========================================
    // findBy / findOneBy / existsBy / countBy
    // ========================================

    public function testFindByFiltersOnMultipleColumns(): void
    {
        $this->seedWidgets();

        $rows = $this->repository->findBy(['domain_id' => 1, 'is_deleted' => 0]);

        $this->assertCount(2, $rows);
    }

    public function testFindOneByReturnsSingleEntity(): void
    {
        $this->seedWidgets();

        $row = $this->repository->findOneBy(['name' => 'beta']);

        $this->assertNotNull($row);
        $this->assertSame('beta', $row->name);
    }

    public function testFindOneByReturnsNullWhenNoMatch(): void
    {
        $this->seedWidgets();

        $this->assertNull($this->repository->findOneBy(['name' => 'nope']));
    }

    /**
     * BlockPageRepository::findByCode() 가 쓰는 패턴이다.
     * is_deleted = 0 조건이 실제로 소프트 삭제 행을 걸러야 한다.
     */
    public function testFindOneByRespectsSoftDeleteFlag(): void
    {
        $this->seedWidgets();

        $this->assertNull($this->repository->findOneBy(['name' => 'gamma', 'is_deleted' => 0]));
        $this->assertNotNull($this->repository->findOneBy(['name' => 'gamma']));
    }

    public function testExistsByAndCountBy(): void
    {
        $this->seedWidgets();

        $this->assertTrue($this->repository->existsBy(['domain_id' => 1]));
        $this->assertFalse($this->repository->existsBy(['domain_id' => 99]));
        $this->assertSame(3, $this->repository->countBy(['domain_id' => 1]));
        $this->assertSame(2, $this->repository->countBy(['domain_id' => 1, 'is_deleted' => 0]));
    }

    // ========================================
    // update / delete
    // ========================================

    public function testUpdateReturnsAffectedRowsAndPersists(): void
    {
        $id = $this->repository->create(['domain_id' => 1, 'name' => 'before']);

        $affected = $this->repository->update($id, ['name' => 'after']);

        $this->assertSame(1, $affected);
        $this->assertSame('after', $this->repository->find($id)->name);
    }

    public function testDeleteRemovesRow(): void
    {
        $id = $this->repository->create(['domain_id' => 1, 'name' => 'doomed']);

        $this->assertSame(1, $this->repository->delete($id));
        $this->assertNull($this->repository->find($id));
    }

    // ========================================
    // paginate
    // ========================================

    public function testPaginateReturnsCorrectSliceAndTotal(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->repository->create(['domain_id' => 1, 'name' => "w{$i}"]);
        }

        $page2 = $this->repository->paginate(2, 2);

        $this->assertSame(5, $page2['total']);
        $this->assertCount(2, $page2['data']);
        $this->assertSame('w3', $page2['data'][0]->name, '2페이지는 3번째 행부터여야 한다');
    }

    public function testPaginateBeyondLastPageReturnsEmptyData(): void
    {
        $this->repository->create(['domain_id' => 1, 'name' => 'only']);

        $result = $this->repository->paginate(5, 10);

        $this->assertSame(1, $result['total']);
        $this->assertSame([], $result['data']);
    }

    private function seedWidgets(): void
    {
        $this->seed('it_widgets', [
            ['domain_id' => 1, 'name' => 'alpha', 'is_deleted' => 0],
            ['domain_id' => 1, 'name' => 'beta',  'is_deleted' => 0],
            ['domain_id' => 1, 'name' => 'gamma', 'is_deleted' => 1],
        ]);
    }
}
