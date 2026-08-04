<?php
/**
 * tests/Unit/Infrastructure/QueryBuilderSecurityTest.php
 *
 * QueryBuilder / JoinClause 식별자·연산자 검증 보안 테스트
 */

namespace Tests\Unit\Infrastructure;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Mublo\Infrastructure\Database\QueryBuilder;
use Mublo\Infrastructure\Database\JoinClause;
use Mublo\Infrastructure\Database\DatabaseException;
use Mublo\Infrastructure\Database\Database;

class QueryBuilderSecurityTest extends TestCase
{
    private QueryBuilder $qb;

    protected function setUp(): void
    {
        parent::setUp();

        $db = $this->createMock(Database::class);

        $this->qb = new QueryBuilder($db, 'users');
    }

    // ─── 정상 식별자 ───

    public function testWhereAcceptsValidColumn(): void
    {
        $this->qb->where('email', '=', 'test@example.com');
        $sql = $this->qb->toSql();
        $this->assertStringContainsString('email', $sql);
    }

    public function testWhereAcceptsDottedColumn(): void
    {
        $this->qb->where('u.email', '=', 'test@example.com');
        $sql = $this->qb->toSql();
        $this->assertStringContainsString('u.email', $sql);
    }

    public function testWhereAcceptsUnderscoreColumn(): void
    {
        $this->qb->where('created_at', '>=', '2026-01-01');
        $sql = $this->qb->toSql();
        $this->assertStringContainsString('created_at', $sql);
    }

    public function testOrderByAcceptsValidColumn(): void
    {
        $this->qb->orderBy('created_at', 'DESC');
        $sql = $this->qb->toSql();
        $this->assertStringContainsString('ORDER BY created_at DESC', $sql);
    }

    public function testSelectAcceptsSafeAggregateAlias(): void
    {
        $this->qb->select(['reaction_type', 'COUNT(*) as count']);
        $sql = $this->qb->toSql();
        $this->assertStringContainsString('COUNT(*) as count', $sql);
    }

    public function testSelectRejectsUnsafeExpression(): void
    {
        $this->expectException(DatabaseException::class);
        $this->qb->select('id, sleep(1)');
    }

    public function testTableRejectsUnsafeIdentifier(): void
    {
        $db = $this->createMock(Database::class);

        $this->expectException(DatabaseException::class);
        new QueryBuilder($db, 'users; DROP TABLE users');
    }

    public function testInsertAcceptsValidKeys(): void
    {
        $db = $this->createMock(Database::class);
        $db->method('insert')->willReturn(1);

        $qb = new QueryBuilder($db, 'users');
        $result = $qb->insert(['name' => 'John', 'email' => 'john@test.com']);
        $this->assertEquals(1, $result);
    }

    public function testUpdateAcceptsValidKeys(): void
    {
        $db = $this->createMock(Database::class);
        $db->method('execute')->willReturn(1);

        $qb = new QueryBuilder($db, 'users');
        $result = $qb->where('id', '=', 1)->update(['name' => 'Jane']);
        $this->assertEquals(1, $result);
    }

    // ─── where() 악성 입력 차단 ───

    public function testWhereRejectsSqlInjectionInColumn(): void
    {
        $this->expectException(DatabaseException::class);
        $this->qb->where('id; DROP TABLE users', '=', 1);
    }

    public function testWhereRejectsSpaceInColumn(): void
    {
        $this->expectException(DatabaseException::class);
        $this->qb->where('id DESC', '=', 1);
    }

    public function testWhereRejectsParenthesisInColumn(): void
    {
        $this->expectException(DatabaseException::class);
        $this->qb->where('sleep(1)', '=', 1);
    }

    public function testWhereRejectsCommentInColumn(): void
    {
        $this->expectException(DatabaseException::class);
        $this->qb->where('id--', '=', 1);
    }

    public function testWhereRejectsInvalidOperator(): void
    {
        $this->expectException(DatabaseException::class);
        $this->qb->where('id', 'OR 1=1 --', 1);
    }

    public function testWhereRejectsSubqueryOperator(): void
    {
        $this->expectException(DatabaseException::class);
        $this->qb->where('id', 'IN (SELECT', 1);
    }

    public function testWhereColumnAcceptsValidColumns(): void
    {
        $this->qb->whereColumn('updated_at', '>', 'created_at');

        $this->assertStringContainsString('updated_at > created_at', $this->qb->toSql());
        $this->assertSame([], $this->qb->getBindings());
    }

    public function testOrWhereColumnUsesOrBoolean(): void
    {
        $this->qb->where('status', 'active')
            ->orWhereColumn('updated_at', '>', 'created_at');

        $this->assertStringContainsString('OR updated_at > created_at', $this->qb->toSql());
        $this->assertSame(['active'], $this->qb->getBindings());
    }

    public function testWhereColumnRejectsInvalidFirstColumn(): void
    {
        $this->expectException(DatabaseException::class);
        $this->qb->whereColumn('updated_at; DROP', '>', 'created_at');
    }

    public function testWhereColumnRejectsInvalidSecondColumn(): void
    {
        $this->expectException(DatabaseException::class);
        $this->qb->whereColumn('updated_at', '>', 'created_at OR 1=1');
    }

    public function testWhereColumnRejectsNonComparisonOperator(): void
    {
        $this->expectException(DatabaseException::class);
        $this->qb->whereColumn('updated_at', 'LIKE', 'created_at');
    }

    public function testWhereLikeBindsContainsPattern(): void
    {
        $this->qb->whereLike('title', 'abc');

        $this->assertStringContainsString("title LIKE ? ESCAPE '\\\\'", $this->qb->toSql());
        $this->assertSame(['%abc%'], $this->qb->getBindings());
    }

    public function testOrWhereLikeUsesOrBoolean(): void
    {
        $this->qb->where('status', 'active')
            ->orWhereLike('title', 'abc');

        $this->assertStringContainsString('OR title LIKE ?', $this->qb->toSql());
        $this->assertSame(['active', '%abc%'], $this->qb->getBindings());
    }

    public function testWhereLikeEscapesWildcardCharacters(): void
    {
        $this->qb->whereLike('title', 'a%b_c\\d');

        $this->assertSame(['%a\\%b\\_c\\\\d%'], $this->qb->getBindings());
    }

    public function testWhereLikeRejectsInvalidColumn(): void
    {
        $this->expectException(DatabaseException::class);
        $this->qb->whereLike('title; DROP', 'abc');
    }

    // ─── whereIn / whereNull / whereBetween 차단 ───

    public function testWhereInRejectsInvalidColumn(): void
    {
        $this->expectException(DatabaseException::class);
        $this->qb->whereIn('id; DROP TABLE', [1, 2, 3]);
    }

    public function testWhereInWithEmptyValuesCompilesToFalseCondition(): void
    {
        $this->qb->whereIn('id', []);

        $this->assertStringContainsString('WHERE 0 = 1', $this->qb->toSql());
        $this->assertSame([], $this->qb->getBindings());
    }

    public function testWhereNotInRejectsInvalidColumn(): void
    {
        $this->expectException(DatabaseException::class);
        $this->qb->whereNotIn('id OR 1=1', [1]);
    }

    public function testWhereNotInWithEmptyValuesCompilesToTrueCondition(): void
    {
        $this->qb->whereNotIn('id', []);

        $this->assertStringContainsString('WHERE 1 = 1', $this->qb->toSql());
        $this->assertSame([], $this->qb->getBindings());
    }

    public function testWhereNullRejectsInvalidColumn(): void
    {
        $this->expectException(DatabaseException::class);
        $this->qb->whereNull('deleted_at IS NOT NULL --');
    }

    public function testWhereNotNullRejectsInvalidColumn(): void
    {
        $this->expectException(DatabaseException::class);
        $this->qb->whereNotNull('col; DROP');
    }

    public function testWhereBetweenRejectsInvalidColumn(): void
    {
        $this->expectException(DatabaseException::class);
        $this->qb->whereBetween('price AND 1=1', 0, 100);
    }

    // ─── orderBy 차단 ───

    public function testOrderByRejectsSqlInjection(): void
    {
        $this->expectException(DatabaseException::class);
        $this->qb->orderBy('id desc, sleep(1)');
    }

    public function testOrderByRejectsSemicolon(): void
    {
        $this->expectException(DatabaseException::class);
        $this->qb->orderBy('id; --');
    }

    // ─── groupBy 차단 ───

    public function testGroupByRejectsInvalidColumn(): void
    {
        $this->expectException(DatabaseException::class);
        $this->qb->groupBy('status; DROP TABLE');
    }

    // ─── having 차단 ───

    public function testHavingRejectsInvalidColumn(): void
    {
        $this->expectException(DatabaseException::class);
        $this->qb->having('COUNT(*)', '>', 5);
    }

    public function testHavingRejectsInvalidOperator(): void
    {
        $this->expectException(DatabaseException::class);
        $this->qb->having('cnt', 'OR 1=1', 5);
    }

    public function testOrHavingRejectsInvalidColumn(): void
    {
        $this->expectException(DatabaseException::class);
        $this->qb->orHaving('COUNT(*)', '>', 5);
    }

    public function testOrHavingRejectsInvalidOperator(): void
    {
        $this->expectException(DatabaseException::class);
        $this->qb->orHaving('cnt', 'OR 1=1', 5);
    }

    // ─── insert/update 키 검증 ───

    public function testInsertRejectsInvalidColumnKey(): void
    {
        $this->expectException(DatabaseException::class);
        $this->qb->insert(['name = NOW()' => 'x']);
    }

    public function testInsertRejectsSqlInKey(): void
    {
        $this->expectException(DatabaseException::class);
        $this->qb->insert(['id; DROP TABLE users' => 1]);
    }

    public function testUpdateRejectsInvalidColumnKey(): void
    {
        $this->expectException(DatabaseException::class);
        $this->qb->where('id', '=', 1)->update(['name = NOW()' => 'x']);
    }

    public function testInsertOrUpdateRejectsInvalidInsertKey(): void
    {
        $this->expectException(DatabaseException::class);
        $this->qb->insertOrUpdate(['col; DROP' => 'val']);
    }

    public function testInsertOrUpdateRejectsInvalidUpdateKey(): void
    {
        $this->expectException(DatabaseException::class);
        $this->qb->insertOrUpdate(['name' => 'val'], ['col; DROP' => 'val']);
    }

    // ─── JoinClause 검증 ───

    public function testJoinOnAcceptsValidColumns(): void
    {
        $join = new JoinClause('INNER', 'orders');
        $join->on('users.id', '=', 'orders.user_id');
        $sql = $join->toSql();
        $this->assertStringContainsString('users.id = orders.user_id', $sql);
    }

    public function testJoinOnRejectsInvalidFirstColumn(): void
    {
        $this->expectException(DatabaseException::class);
        $join = new JoinClause('INNER', 'orders');
        $join->on('a.id; DROP', '=', 'b.id');
    }

    public function testJoinOnRejectsInvalidSecondColumn(): void
    {
        $this->expectException(DatabaseException::class);
        $join = new JoinClause('INNER', 'orders');
        $join->on('a.id', '=', 'b.id OR 1=1');
    }

    public function testJoinOnRejectsInvalidOperator(): void
    {
        $this->expectException(DatabaseException::class);
        $join = new JoinClause('INNER', 'orders');
        $join->on('a.id', 'LIKE', 'b.id');
    }

    public function testJoinOrOnRejectsInvalidColumn(): void
    {
        $this->expectException(DatabaseException::class);
        $join = new JoinClause('INNER', 'orders');
        $join->on('a.id', '=', 'b.id');
        $join->orOn('a.name; --', '=', 'b.name');
    }

    public function testJoinRejectsInvalidTable(): void
    {
        $this->expectException(DatabaseException::class);
        $this->qb->join('orders; DROP TABLE orders', 'users.id', '=', 'orders.user_id');
    }

    public function testJoinRejectsInvalidType(): void
    {
        $this->expectException(DatabaseException::class);
        new JoinClause('INNER; DROP', 'orders');
    }

    public function testJoinAcceptsTableAlias(): void
    {
        $join = new JoinClause('LEFT', 'board_groups AS g');
        $join->on('b.group_id', '=', 'g.group_id');
        $this->assertStringContainsString('board_groups AS g', $join->toSql());
    }

    public function testJoinAcceptsImplicitTableAlias(): void
    {
        $join = new JoinClause('LEFT', 'members m');
        $this->assertStringContainsString('members m', $join->toSql());
    }

    public function testJoinRejectsAliasWithInjection(): void
    {
        $this->expectException(DatabaseException::class);
        new JoinClause('LEFT', 'board_groups AS g; DROP TABLE x');
    }

    // ─── 정상 연산자 허용 확인 ───

    #[DataProvider('validOperatorProvider')]
    public function testWhereAcceptsValidOperators(string $operator): void
    {
        $this->qb->where('id', $operator, 1);
        $sql = $this->qb->toSql();
        $this->assertStringContainsString($operator, $sql);
    }

    public static function validOperatorProvider(): array
    {
        return [
            ['='],
            ['!='],
            ['<>'],
            ['>'],
            ['>='],
            ['<'],
            ['<='],
            ['LIKE'],
            ['NOT LIKE'],
        ];
    }

    // ─── 네스티드 where는 클로저 내부에서도 검증 ───

    public function testNestedWhereRejectsInvalidColumn(): void
    {
        $this->expectException(DatabaseException::class);
        $this->qb->where(function ($query) {
            $query->where('valid_col', '=', 1);
            $query->where('invalid col', '=', 2);
        });
    }

    public function testWhereRawRejectsBindingCountMismatch(): void
    {
        $this->expectException(DatabaseException::class);
        $this->qb->whereRaw('id = ? AND email = ?', [1]);
    }

    public function testWhereRawIgnoresQuestionMarkInsideStringLiteral(): void
    {
        $this->qb->whereRaw("message = '?' AND id = ?", [1]);

        $this->assertSame([1], $this->qb->getBindings());
        $this->assertStringContainsString("message = '?' AND id = ?", $this->qb->toSql());
    }

    public function testOrderByRawRejectsBindingCountMismatch(): void
    {
        $this->expectException(DatabaseException::class);
        $this->qb->orderByRaw('FIELD(id, ?, ?)', [1]);
    }

    public function testUpdateWithoutWhereIsRejectedByDefault(): void
    {
        $this->expectException(DatabaseException::class);
        $this->qb->update(['name' => 'Jane']);
    }

    public function testDeleteWithoutWhereIsRejectedByDefault(): void
    {
        $this->expectException(DatabaseException::class);
        $this->qb->delete();
    }

    public function testExplicitFullTableUpdateIsAllowed(): void
    {
        $db = $this->createMock(Database::class);
        $db->expects($this->once())
            ->method('execute')
            ->with('UPDATE users SET active = ?', [0])
            ->willReturn(3);

        $qb = new QueryBuilder($db, 'users');

        $this->assertSame(3, $qb->allowFullTableOperation()->update(['active' => 0]));
    }

    public function testExplicitFullTableDeleteIsAllowed(): void
    {
        $db = $this->createMock(Database::class);
        $db->expects($this->once())
            ->method('execute')
            ->with('DELETE FROM users', [])
            ->willReturn(3);

        $qb = new QueryBuilder($db, 'users');

        $this->assertSame(3, $qb->allowFullTableOperation()->delete());
    }

    public function testResetClearsFullTableOperationFlag(): void
    {
        $this->expectException(DatabaseException::class);

        $this->qb->allowFullTableOperation()->reset()->delete();
    }

    public function testInvalidBooleanIsRejected(): void
    {
        $this->expectException(DatabaseException::class);
        $this->qb->where('id', '=', 1, 'XOR');
    }

    public function testNegativeLimitIsRejected(): void
    {
        $this->expectException(DatabaseException::class);
        $this->qb->limit(-1);
    }

    public function testNegativeOffsetIsRejected(): void
    {
        $this->expectException(DatabaseException::class);
        $this->qb->offset(-1);
    }

    public function testForPageRejectsInvalidPage(): void
    {
        $this->expectException(DatabaseException::class);
        $this->qb->forPage(0, 10);
    }

    public function testForPageRejectsInvalidPerPage(): void
    {
        $this->expectException(DatabaseException::class);
        $this->qb->forPage(1, 0);
    }

    public function testFirstRestoresPreviousLimit(): void
    {
        $db = $this->createMock(Database::class);
        $db->expects($this->once())
            ->method('selectOne')
            ->with($this->stringContains('LIMIT 1'), [])
            ->willReturn(['id' => 1]);

        $qb = new QueryBuilder($db, 'users');
        $qb->limit(5);

        $this->assertSame(['id' => 1], $qb->first());
        $this->assertStringContainsString('LIMIT 5', $qb->toSql());
    }

    public function testAggregateIgnoresOrderLimitAndOffsetButRestoresState(): void
    {
        $db = $this->createMock(Database::class);
        $db->expects($this->once())
            ->method('selectOne')
            ->with(
                $this->callback(function (string $sql): bool {
                    return str_contains($sql, 'COUNT(*) as aggregate')
                        && !str_contains($sql, 'ORDER BY')
                        && !str_contains($sql, 'LIMIT')
                        && !str_contains($sql, 'OFFSET');
                }),
                [1]
            )
            ->willReturn(['aggregate' => 3]);

        $qb = new QueryBuilder($db, 'users');
        $qb->where('active', '=', 1)
            ->orderBy('created_at', 'DESC')
            ->limit(10)
            ->offset(20);

        $this->assertSame(3, $qb->count());
        $sql = $qb->toSql();
        $this->assertStringContainsString('ORDER BY created_at DESC', $sql);
        $this->assertStringContainsString('LIMIT 10', $sql);
        $this->assertStringContainsString('OFFSET 20', $sql);
    }

    public function testExistsUsesShortCircuitQueryAndPreservesOriginalState(): void
    {
        $db = $this->createMock(Database::class);
        $db->expects($this->once())
            ->method('selectOne')
            ->with(
                $this->callback(function (string $sql): bool {
                    return str_contains($sql, 'SELECT EXISTS(SELECT * FROM users WHERE active = ?) AS exists_flag')
                        && !str_contains($sql, 'COUNT(')
                        && !str_contains($sql, 'ORDER BY')
                        && !str_contains($sql, 'LIMIT')
                        && !str_contains($sql, 'OFFSET');
                }),
                [1]
            )
            ->willReturn(['exists_flag' => 1]);

        $query = (new QueryBuilder($db, 'users'))
            ->where('active', 1)
            ->orderByDesc('created_at')
            ->limit(10)
            ->offset(20);

        $this->assertTrue($query->exists());
        $this->assertStringContainsString('ORDER BY created_at DESC', $query->toSql());
        $this->assertStringContainsString('LIMIT 10', $query->toSql());
        $this->assertStringContainsString('OFFSET 20', $query->toSql());
    }

    public function testExistsReturnsFalseWhenDatabaseReportsNoMatch(): void
    {
        $db = $this->createMock(Database::class);
        $db->method('selectOne')->willReturn(['exists_flag' => 0]);

        $this->assertFalse((new QueryBuilder($db, 'users'))->where('active', 1)->exists());
    }

    public function testCountForPaginationIgnoresOrderLimitAndOffsetWithoutChangingOriginalQuery(): void
    {
        $db = $this->createMock(Database::class);
        $db->expects($this->once())
            ->method('selectOne')
            ->with(
                $this->callback(function (string $sql): bool {
                    return str_contains($sql, 'COUNT(*) as aggregate')
                        && str_contains($sql, 'WHERE active = ?')
                        && !str_contains($sql, 'ORDER BY')
                        && !str_contains($sql, 'LIMIT')
                        && !str_contains($sql, 'OFFSET');
                }),
                [1]
            )
            ->willReturn(['aggregate' => 7]);

        $qb = new QueryBuilder($db, 'users');
        $qb->where('active', '=', 1)
            ->orderBy('created_at', 'DESC')
            ->limit(10)
            ->offset(20);

        $this->assertSame(7, $qb->countForPagination());

        $sql = $qb->toSql();
        $this->assertStringContainsString('ORDER BY created_at DESC', $sql);
        $this->assertStringContainsString('LIMIT 10', $sql);
        $this->assertStringContainsString('OFFSET 20', $sql);
    }

    public function testGenericWhereRoutesInArrayToSafeWhereIn(): void
    {
        $this->qb->where('id', 'IN', [3, 7]);

        $this->assertStringContainsString('WHERE id IN (?, ?)', $this->qb->toSql());
        $this->assertSame([3, 7], $this->qb->getBindings());
    }

    public function testGenericWhereRoutesBetweenArrayToSafeRange(): void
    {
        $this->qb->where('price', 'NOT BETWEEN', [100, 500]);

        $this->assertStringContainsString('WHERE price NOT BETWEEN ? AND ?', $this->qb->toSql());
        $this->assertSame([100, 500], $this->qb->getBindings());
    }

    public function testGenericWhereRoutesExplicitNullToNullCondition(): void
    {
        $this->qb->where('deleted_at', '=', null)
            ->orWhere('published_at', 'IS NOT', null);

        $this->assertStringContainsString(
            'WHERE deleted_at IS NULL OR published_at IS NOT NULL',
            $this->qb->toSql()
        );
        $this->assertSame([], $this->qb->getBindings());
    }

    public function testGenericWhereRejectsInvalidInValueClearly(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('IN expects an array value');

        $this->qb->where('id', 'IN', 1);
    }

    public function testBindingsFollowSqlClauseOrderRegardlessOfCallOrder(): void
    {
        $query = $this->qb
            ->orderByRaw('FIELD(id, ?, ?)', [2, 1])
            ->having('cnt', '>', 5)
            ->where('status', 'active');

        $this->assertStringContainsString(
            'WHERE status = ? HAVING cnt > ? ORDER BY FIELD(id, ?, ?)',
            $query->toSql()
        );
        $this->assertSame(['active', 5, 2, 1], $query->getBindings());
    }

    public function testIncrementBuildsAtomicGuardedUpdate(): void
    {
        $db = $this->createMock(Database::class);
        $db->expects($this->once())
            ->method('execute')
            ->with('UPDATE counters SET value = value + ? WHERE id = ?', [2, 10])
            ->willReturn(1);

        $affected = (new QueryBuilder($db, 'counters'))
            ->where('id', 10)
            ->increment('value', 2);

        $this->assertSame(1, $affected);
    }

    public function testDecrementWithoutWhereIsRejected(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('without WHERE is not allowed');

        $this->qb->decrement('stock_quantity');
    }

    public function testPaginateReturnsItemsAndNormalizedMetadata(): void
    {
        $db = $this->createMock(Database::class);
        $db->expects($this->once())
            ->method('selectOne')
            ->with($this->stringContains('COUNT(*) as aggregate'), ['active'])
            ->willReturn(['aggregate' => 23]);
        $db->expects($this->once())
            ->method('select')
            ->with(
                $this->callback(static fn(string $sql): bool =>
                    str_contains($sql, 'ORDER BY created_at DESC')
                    && str_contains($sql, 'LIMIT 10 OFFSET 10')),
                ['active']
            )
            ->willReturn([['id' => 11], ['id' => 12]]);

        $result = (new QueryBuilder($db, 'users'))
            ->where('status', 'active')
            ->orderByDesc('created_at')
            ->paginate(2, 10);

        $this->assertSame([['id' => 11], ['id' => 12]], $result['data']);
        $this->assertSame(23, $result['total']);
        $this->assertSame(2, $result['page']);
        $this->assertSame(10, $result['per_page']);
        $this->assertSame(3, $result['total_pages']);
    }

    public function testGroupedPaginationCountsResultRowsWithSubquery(): void
    {
        $db = $this->createMock(Database::class);
        $db->expects($this->once())
            ->method('selectOne')
            ->with(
                $this->callback(static fn(string $sql): bool =>
                    str_contains($sql, 'SELECT COUNT(*) AS aggregate FROM (')
                    && str_contains($sql, 'GROUP BY status')
                    && !str_contains($sql, 'ORDER BY')),
                [7]
            )
            ->willReturn(['aggregate' => 4]);

        $count = (new QueryBuilder($db, 'orders'))
            ->select(['status', 'COUNT(*) AS cnt'])
            ->groupBy('status')
            ->orderByDesc('status')
            ->where('domain_id', 7)
            ->countForPagination();

        $this->assertSame(4, $count);
    }
}
