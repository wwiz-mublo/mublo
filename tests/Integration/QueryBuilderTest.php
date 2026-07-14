<?php

namespace Tests\Integration;

/**
 * QueryBuilder 가 만든 SQL 이 실제 DB 에서 도는지 검증한다.
 *
 * 단위 테스트는 Database 를 mock 하므로 toSql() 문자열만 본다. 문법 오류, 잘못된 컬럼명,
 * 잘못된 JOIN, 드라이버가 거부하는 표현식은 실 DB 를 태워야만 드러난다.
 */
class QueryBuilderTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createTable('it_products', '
            id INT AUTO_INCREMENT PRIMARY KEY,
            category_code VARCHAR(50) NOT NULL,
            status VARCHAR(20) NOT NULL,
            price INT NOT NULL DEFAULT 0,
            menu_code VARCHAR(50) NULL
        ');

        $this->seed('it_products', [
            ['category_code' => 'shoes', 'status' => 'active',   'price' => 1000, 'menu_code' => null],
            ['category_code' => 'shoes', 'status' => 'active',   'price' => 2000, 'menu_code' => ''],
            ['category_code' => 'shoes', 'status' => 'inactive', 'price' => 3000, 'menu_code' => 'shop'],
            ['category_code' => 'bags',  'status' => 'active',   'price' => 5000, 'menu_code' => null],
        ]);
    }

    // ========================================
    // 집계 — selectRaw 버그가 숨어 있던 자리
    // ========================================

    public function testCountWithWhereRunsOnRealDatabase(): void
    {
        $count = $this->db->table('it_products')
            ->where('category_code', '=', 'shoes')
            ->count();

        $this->assertSame(3, $count);
    }

    public function testCountOnEmptyResultReturnsZero(): void
    {
        $this->assertSame(0, $this->db->table('it_products')->where('category_code', '=', 'none')->count());
    }

    public function testGroupByAggregateExpressionRunsOnRealDatabase(): void
    {
        $rows = $this->db->table('it_products')
            ->select(['status', 'COUNT(*) as count'])
            ->groupBy('status')
            ->get();

        $counts = array_column($rows, 'count', 'status');

        $this->assertSame(3, (int) $counts['active']);
        $this->assertSame(1, (int) $counts['inactive']);
    }

    public function testSumAvgMaxMinRunOnRealDatabase(): void
    {
        $builder = fn() => $this->db->table('it_products')->where('category_code', '=', 'shoes');

        $this->assertSame(6000.0, $builder()->sum('price'));
        $this->assertSame(2000.0, $builder()->avg('price'));
        $this->assertSame(3000, (int) $builder()->max('price'));
        $this->assertSame(1000, (int) $builder()->min('price'));
    }

    // ========================================
    // 중첩 WHERE — new static() 을 self 로 바꾼 자리
    // ========================================

    public function testNestedWhereProducesCorrectGrouping(): void
    {
        // WHERE category_code = 'shoes' AND (menu_code IS NULL OR menu_code = '')
        $rows = $this->db->table('it_products')
            ->where('category_code', '=', 'shoes')
            ->where(function ($query) {
                $query->whereNull('menu_code')->orWhere('menu_code', '=', '');
            })
            ->get();

        $this->assertCount(2, $rows, 'NULL 과 빈 문자열 행만 잡혀야 한다');

        $prices = array_map(fn($r) => (int) $r['price'], $rows);
        sort($prices);
        $this->assertSame([1000, 2000], $prices);
    }

    /**
     * 괄호가 없으면 AND/OR 우선순위 때문에 다른 카테고리 행까지 딸려 온다.
     * 이 테스트는 그 회귀를 잡는다.
     */
    public function testNestedWhereDoesNotLeakOtherCategories(): void
    {
        $rows = $this->db->table('it_products')
            ->where('category_code', '=', 'shoes')
            ->where(function ($query) {
                $query->whereNull('menu_code')->orWhere('menu_code', '=', 'shop');
            })
            ->get();

        foreach ($rows as $row) {
            $this->assertSame('shoes', $row['category_code']);
        }
        $this->assertCount(2, $rows);
    }

    // ========================================
    // 기본 CRUD
    // ========================================

    public function testInsertUpdateDeleteRoundTrip(): void
    {
        $id = $this->db->table('it_products')
            ->insert(['category_code' => 'hats', 'status' => 'active', 'price' => 700]);

        $this->assertGreaterThan(0, $id);

        $this->db->table('it_products')->where('id', '=', $id)->update(['price' => 800]);
        $row = $this->db->table('it_products')->where('id', '=', $id)->first();
        $this->assertSame(800, (int) $row['price']);

        $affected = $this->db->table('it_products')->where('id', '=', $id)->delete();
        $this->assertSame(1, $affected);
        $this->assertNull($this->db->table('it_products')->where('id', '=', $id)->first());
    }

    public function testWhereInAndOrderByAndLimit(): void
    {
        $rows = $this->db->table('it_products')
            ->whereIn('category_code', ['shoes', 'bags'])
            ->orderByDesc('price')
            ->limit(2)
            ->get();

        $this->assertCount(2, $rows);
        $this->assertSame(5000, (int) $rows[0]['price']);
        $this->assertSame(3000, (int) $rows[1]['price']);
    }

    public function testBindingsArePreparedNotInterpolated(): void
    {
        // 값이 SQL 로 해석되면 여기서 터지거나 전체 행이 반환된다
        $rows = $this->db->table('it_products')
            ->where('category_code', '=', "shoes' OR '1'='1")
            ->get();

        $this->assertSame([], $rows);
    }

    public function testGenericWhereHandlesInAndNullSafely(): void
    {
        $rows = $this->db->table('it_products')
            ->where('category_code', 'IN', ['shoes'])
            ->where('menu_code', '=', null)
            ->get();

        $this->assertCount(1, $rows);
        $this->assertSame(1000, (int) $rows[0]['price']);
    }

    public function testBindingOrderDoesNotDependOnMethodCallOrder(): void
    {
        $rows = $this->db->table('it_products')
            ->orderByRaw('FIELD(id, ?, ?) DESC', [1, 2])
            ->where('status', 'active')
            ->limit(2)
            ->get();

        $this->assertSame([2, 1], array_map(static fn(array $row): int => (int) $row['id'], $rows));
    }

    public function testIncrementDecrementAndPaginateRoundTrip(): void
    {
        $this->assertSame(
            1,
            $this->db->table('it_products')->where('id', 1)->increment('price', 250)
        );
        $this->assertSame(
            1,
            $this->db->table('it_products')->where('id', 1)->decrement('price', 50)
        );

        $row = $this->db->table('it_products')->where('id', 1)->first();
        $this->assertSame(1200, (int) $row['price']);

        $page = $this->db->table('it_products')
            ->where('status', 'active')
            ->orderBy('id')
            ->paginate(2, 2);

        $this->assertSame(3, $page['total']);
        $this->assertSame(2, $page['page']);
        $this->assertSame(2, $page['total_pages']);
        $this->assertCount(1, $page['data']);
    }
}
