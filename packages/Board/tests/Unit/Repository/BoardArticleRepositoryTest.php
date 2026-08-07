<?php

namespace Tests\Board\Unit\Repository;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Mublo\Packages\Board\Repository\BoardArticleRepository;
use Mublo\Packages\Board\Entity\BoardArticle;
use Mublo\Infrastructure\Database\Database;
use Mublo\Infrastructure\Database\QueryBuilder;

/**
 * BoardArticleRepositoryTest
 *
 * 게시글 Repository 테스트
 */
class BoardArticleRepositoryTest extends TestCase
{
    private MockObject $dbMock;
    private MockObject $qbMock;
    private BoardArticleRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dbMock = $this->createMock(Database::class);
        $this->qbMock = $this->createMock(QueryBuilder::class);

        $this->dbMock->method('table')->willReturn($this->qbMock);

        $this->repository = new BoardArticleRepository($this->dbMock);
    }

    private function sampleRow(array $overrides = []): array
    {
        return array_merge([
            'article_id'    => 1,
            'domain_id'     => 10,
            'board_id'      => 2,
            'category_id'   => null,
            'member_id'     => 100,
            'author_name'   => '홍길동',
            'author_password' => null,
            'title'         => '테스트 글',
            'slug'          => 'test-slug',
            'content'       => '내용',
            'thumbnail'     => null,
            'is_notice'     => 0,
            'is_secret'     => 0,
            'status'        => 'published',
            'read_level'    => null,
            'download_level' => null,
            'view_count'    => 0,
            'comment_count' => 0,
            'reaction_count' => 0,
            'location_lat'  => null,
            'location_lng'  => null,
            'ip_address'    => '127.0.0.1',
            'created_at'    => '2026-01-01 10:00:00',
            'updated_at'    => '2026-01-01 10:00:00',
            'published_at'  => null,
        ], $overrides);
    }

    // ─── escapeLike (LIKE 와일드카드 이스케이프) ───

    public function testEscapeLikeEscapesBackslashFirst(): void
    {
        $method = new \ReflectionMethod($this->repository, 'escapeLike');
        $method->setAccessible(true);

        // 백슬래시를 먼저 이스케이프해야 %/_ 가 리터럴로 남는다.
        // 순서가 틀리면 '50%' → '50\%'(백슬래시 + 살아있는 와일드카드)로 새어나간다(LIKE 와일드카드 인젝션).
        $this->assertSame('50\\%', $method->invoke($this->repository, '50%'));
        $this->assertSame('a\\_b', $method->invoke($this->repository, 'a_b'));
        $this->assertSame('c\\\\d', $method->invoke($this->repository, 'c\\d'));
    }

    // ─── find ───

    public function testFindReturnsEntityWhenRowExists(): void
    {
        $this->qbMock->method('where')->willReturnSelf();
        $this->qbMock->method('first')->willReturn($this->sampleRow());

        $result = $this->repository->find(1);

        $this->assertInstanceOf(BoardArticle::class, $result);
        $this->assertSame(1, $result->getArticleId());
        $this->assertSame('홍길동', $result->getAuthorName());
    }

    public function testFindReturnsNullWhenNotFound(): void
    {
        $this->qbMock->method('where')->willReturnSelf();
        $this->qbMock->method('first')->willReturn(null);

        $result = $this->repository->find(999);

        $this->assertNull($result);
    }

    // ─── create ───

    public function testCreateReturnsInsertedId(): void
    {
        // BaseRepository.create()는 $this->db->table()->insert() 경로를 사용
        $this->qbMock->expects($this->once())
            ->method('insert')
            ->willReturn(42);

        $id = $this->repository->create([
            'domain_id' => 10,
            'board_id'  => 2,
            'title'     => '새 글',
            'content'   => '내용',
            'status'    => 'published',
        ]);

        $this->assertSame(42, $id);
    }

    // ─── update ───

    public function testUpdateReturnsAffectedRows(): void
    {
        $this->qbMock->method('where')->willReturnSelf();
        $this->qbMock->method('update')->willReturn(1);

        $affected = $this->repository->update(1, ['title' => '수정된 제목']);

        $this->assertSame(1, $affected);
    }

    // ─── countByBoard ───

    public function testCountByBoardReturnsCount(): void
    {
        $this->qbMock->method('where')->willReturnSelf();
        $this->qbMock->method('count')->willReturn(15);

        $count = $this->repository->countByBoard(2);

        $this->assertSame(15, $count);
    }

    public function testCountByBoardWithNullStatusSkipsStatusFilter(): void
    {
        $this->qbMock->expects($this->once())
            ->method('where')
            ->with('board_id', '=', 2)
            ->willReturnSelf();
        $this->qbMock->method('count')->willReturn(20);

        $count = $this->repository->countByBoard(2, null);

        $this->assertSame(20, $count);
    }

    // ─── existsBySlug ───

    public function testExistsBySlugReturnsTrueWhenExists(): void
    {
        // existsBy() → QueryBuilder->exists()
        $this->qbMock->method('where')->willReturnSelf();
        $this->qbMock->method('exists')->willReturn(true);

        $result = $this->repository->existsBySlug(2, 'test-slug');

        $this->assertTrue($result);
    }

    public function testExistsBySlugReturnsFalseWhenNotExists(): void
    {
        $this->qbMock->method('where')->willReturnSelf();
        $this->qbMock->method('exists')->willReturn(false);

        $result = $this->repository->existsBySlug(2, 'not-existing-slug');

        $this->assertFalse($result);
    }

    // ─── updateStatus ───

    public function testUpdateStatusReturnsTrueWhenRowAffected(): void
    {
        $this->qbMock->method('where')->willReturnSelf();
        $this->qbMock->method('update')->willReturn(1);

        $result = $this->repository->updateStatus(1, 'deleted');

        $this->assertTrue($result);
    }

    public function testUpdateStatusReturnsFalseWhenNoRowAffected(): void
    {
        $this->qbMock->method('where')->willReturnSelf();
        $this->qbMock->method('update')->willReturn(0);

        $result = $this->repository->updateStatus(999, 'deleted');

        $this->assertFalse($result);
    }

    // ─── countByDomainAndIds ───

    public function testCountByDomainAndIdsReturnsZeroForEmptyArray(): void
    {
        // DB 호출 없이 바로 0 반환
        $count = $this->repository->countByDomainAndIds(10, []);

        $this->assertSame(0, $count);
    }

    public function testCountByDomainAndIdsWithIds(): void
    {
        $this->qbMock->method('where')->willReturnSelf();
        $this->qbMock->method('whereIn')->willReturnSelf();
        $this->qbMock->method('count')->willReturn(3);

        $count = $this->repository->countByDomainAndIds(10, [1, 2, 3]);

        $this->assertSame(3, $count);
    }

    // ─── getNotices ───

    public function testGetNoticesReturnsEntityArray(): void
    {
        $this->qbMock->method('where')->willReturnSelf();
        $this->qbMock->method('orderBy')->willReturnSelf();
        $this->qbMock->method('limit')->willReturnSelf();
        $this->qbMock->method('get')->willReturn([$this->sampleRow(['is_notice' => 1])]);

        $notices = $this->repository->getNotices(10, 2);

        $this->assertCount(1, $notices);
        $this->assertInstanceOf(BoardArticle::class, $notices[0]);
    }

    public function testGetNoticesReturnsEmptyArrayWhenNone(): void
    {
        $this->qbMock->method('where')->willReturnSelf();
        $this->qbMock->method('orderBy')->willReturnSelf();
        $this->qbMock->method('limit')->willReturnSelf();
        $this->qbMock->method('get')->willReturn([]);

        $notices = $this->repository->getNotices(10, 2);

        $this->assertSame([], $notices);
    }

    public function testGetRecentListSkipsPaginationCount(): void
    {
        $this->qbMock->method('where')->willReturnSelf();
        $this->qbMock->method('orderBy')->willReturnSelf();
        $this->qbMock->method('limit')->willReturnSelf();
        $this->qbMock->expects($this->never())->method('count');
        $this->qbMock->expects($this->once())->method('get')->willReturn([]);

        $result = $this->repository->getRecentList(10, 2, 5);

        $this->assertSame([], $result['items']);
        $this->assertSame([
            'attachments' => [],
            'links' => [],
            'categories' => [],
        ], $result['relations']);
        $this->assertArrayNotHasKey('pagination', $result);
    }

    public function testPaginatedListSkipsRedundantNoticeOrderingWhenNoticeIsFiltered(): void
    {
        $orderByCalls = [];

        $this->qbMock->method('where')->willReturnSelf();
        $this->qbMock->method('count')->willReturn(0);
        $this->qbMock->method('limit')->willReturnSelf();
        $this->qbMock->method('offset')->willReturnSelf();
        $this->qbMock->method('get')->willReturn([]);
        $this->qbMock->expects($this->once())
            ->method('orderBy')
            ->willReturnCallback(
                function (string $column, string $direction) use (&$orderByCalls): QueryBuilder {
                    $orderByCalls[] = [$column, $direction];
                    return $this->qbMock;
                }
            );

        $this->repository->getPaginatedList(10, 2, 120, 20, ['is_notice' => 0]);

        $this->assertSame([['a.created_at', 'DESC']], $orderByCalls);
    }

    public function testPaginatedListKeepsNoticeFirstOrderingWithoutNoticeFilter(): void
    {
        $orderByCalls = [];

        $this->qbMock->method('where')->willReturnSelf();
        $this->qbMock->method('count')->willReturn(0);
        $this->qbMock->method('limit')->willReturnSelf();
        $this->qbMock->method('offset')->willReturnSelf();
        $this->qbMock->method('get')->willReturn([]);
        $this->qbMock->expects($this->exactly(2))
            ->method('orderBy')
            ->willReturnCallback(
                function (string $column, string $direction) use (&$orderByCalls): QueryBuilder {
                    $orderByCalls[] = [$column, $direction];
                    return $this->qbMock;
                }
            );

        $this->repository->getPaginatedList(10, 2);

        $this->assertSame([
            ['a.is_notice', 'DESC'],
            ['a.created_at', 'DESC'],
        ], $orderByCalls);
    }
}
