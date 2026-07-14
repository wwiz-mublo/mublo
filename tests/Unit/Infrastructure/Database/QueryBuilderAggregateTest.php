<?php

namespace Tests\Unit\Infrastructure\Database;

use Mublo\Infrastructure\Database\Database;
use Mublo\Infrastructure\Database\DatabaseException;
use Mublo\Infrastructure\Database\QueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * 집계 쿼리 계약
 *
 * 저장소 곳곳이 존재하지 않는 selectRaw() 를 부르고 있었다(호출 즉시 fatal).
 * 실제로 필요한 것은 이미 있던 count() 와, 집계 표현식을 허용하는 select() 였다.
 * 이 테스트는 그 두 경로가 무엇을 만들어 내는지 고정한다.
 */
class QueryBuilderAggregateTest extends TestCase
{
    /** @var array{sql: string, bindings: array}|null */
    private ?array $captured = null;

    private function makeBuilder(string $table, mixed $selectOneResult = ['aggregate' => 7]): QueryBuilder
    {
        $test = $this;

        $db = new class ($test, $selectOneResult) extends Database {
            public function __construct(private object $test, private mixed $result)
            {
            }

            public function selectOne(string $sql, array $bindings = []): ?array
            {
                $this->test->capture($sql, $bindings);

                return $this->result;
            }
        };

        return new QueryBuilder($db, $table);
    }

    public function capture(string $sql, array $bindings): void
    {
        $this->captured = ['sql' => $sql, 'bindings' => $bindings];
    }

    // ========================================
    // count() — selectRaw('COUNT(*) as cnt') 의 정식 대체
    // ========================================

    public function testCountWrapsQueryAndKeepsWhereClause(): void
    {
        $qb = $this->makeBuilder('shop_products');

        $result = $qb->where('category_code', '=', 'shoes')->count();

        $this->assertSame(7, $result);
        $this->assertStringContainsString('COUNT(*) as aggregate', $this->captured['sql']);
        $this->assertStringContainsString('WHERE category_code = ?', $this->captured['sql']);
        $this->assertSame(['shoes'], $this->captured['bindings']);
    }

    public function testCountReturnsZeroWhenNoRow(): void
    {
        $qb = $this->makeBuilder('shop_products', null);

        $this->assertSame(0, $qb->where('category_code', '=', 'none')->count());
    }

    public function testCountRejectsInvalidColumnIdentifier(): void
    {
        $this->expectException(DatabaseException::class);

        $this->makeBuilder('t')->count('COUNT(*)');
    }

    // ========================================
    // select() — GROUP BY 집계에 쓰는 경로
    // ========================================

    public function testSelectAllowsAggregateExpressionWithAlias(): void
    {
        $qb = $this->makeBuilder('domain_configs');

        $sql = $qb->select(['status', 'COUNT(*) as count'])->groupBy('status')->toSql();

        $this->assertSame('SELECT status, COUNT(*) as count FROM domain_configs GROUP BY status', $sql);
    }

    /**
     * 검증기는 표현식 하나만 본다. selectRaw 처럼 쉼표로 이어 붙이면 거부된다 —
     * 그래서 배열로 나눠 넘겨야 한다.
     */
    public function testSelectRejectsCommaJoinedExpression(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('Invalid SELECT expression');

        $this->makeBuilder('domain_configs')->select('status, COUNT(*) as count');
    }

    public function testSelectRejectsArbitraryRawSql(): void
    {
        $this->expectException(DatabaseException::class);

        // SELECT 절은 화이트리스트다. 임의 표현식을 넣는 통로를 열지 않는다.
        $this->makeBuilder('t')->select(['(SELECT secret FROM users) as leaked']);
    }

    public function testSelectAllowsSupportedAggregateFunctions(): void
    {
        foreach (['COUNT(*)', 'SUM(price)', 'AVG(price)', 'MIN(price)', 'MAX(price)'] as $expression) {
            $sql = $this->makeBuilder('t')->select([$expression . ' as v'])->toSql();

            $this->assertStringContainsString($expression . ' as v', $sql);
        }
    }
}
