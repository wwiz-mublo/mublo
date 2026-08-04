<?php
declare(strict_types=1);
namespace Mublo\Infrastructure\Database;

use PDO;

/**
 * QueryBuilder
 *
 * 유연한 SQL 쿼리 빌더
 *
 * 특징:
 * - 메서드 체이닝
 * - Prepared Statement 자동 바인딩
 * - JOIN 지원 (JoinClause)
 * - 복잡한 WHERE 조건
 * - 집계 함수
 * - 페이지네이션
 */
class QueryBuilder
{
    protected Database $db;
    protected string $table;
    protected array $columns = ['*'];
    protected array $joins = [];
    protected array $wheres = [];
    /** @var array<int, mixed> SQL 절 순서와 무관하게 수집되는 WHERE 바인딩 */
    protected array $whereBindings = [];
    /** @var array<int, mixed> HAVING 바인딩 */
    protected array $havingBindings = [];
    /** @var array<int, mixed> ORDER BY raw 바인딩 */
    protected array $orderBindings = [];
    protected array $groupBy = [];
    protected array $having = [];
    protected array $orderBy = [];
    protected ?int $limitValue = null;
    protected ?int $offsetValue = null;
    protected bool $distinct = false;
    protected bool $allowFullTableOperation = false;

    /**
     * Constructor
     *
     * @param Database $db Database 인스턴스
     * @param string $table 테이블 이름 (프리픽스 자동 적용)
     */
    public function __construct(Database $db, string $table)
    {
        $this->db = $db;
        $this->table = $table;

        if ($this->table !== '') {
            $this->assertTableIdentifier($this->table);
        }
    }

    /**
     * SELECT 컬럼 지정
     *
     * @param string|array $columns 컬럼 목록
     * @return self
     */
    public function select($columns = ['*']): self
    {
        $this->columns = is_array($columns) ? $columns : func_get_args();
        foreach ($this->columns as $column) {
            $this->assertSelectExpression((string) $column);
        }
        return $this;
    }

    /**
     * DISTINCT 설정
     *
     * @return self
     */
    public function distinct(): self
    {
        $this->distinct = true;
        return $this;
    }

    /**
     * 컬럼 식별자 유효성 검증 (SQL Injection 방지)
     *
     * @param string $column 검증할 컬럼명
     * @throws DatabaseException 유효하지 않은 식별자
     */
    private function assertIdentifier(string $column): void
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_.]*$/', $column)) {
            throw new DatabaseException("Invalid column identifier: {$column}");
        }
    }

    /**
     * 테이블 식별자 유효성 검증.
     *
     * 허용:
     * - table, schema.table
     * - table AS alias / table alias (JOIN·집계 쿼리용 별칭)
     */
    private function assertTableIdentifier(string $table): void
    {
        $identifier = '[a-zA-Z_][a-zA-Z0-9_]*(?:\.[a-zA-Z_][a-zA-Z0-9_]*)?';
        $alias = '[a-zA-Z_][a-zA-Z0-9_]*';

        if (!preg_match("/^{$identifier}(?:\s+(?:AS\s+)?{$alias})?$/i", $table)) {
            throw new DatabaseException("Invalid table identifier: {$table}");
        }
    }

    /**
     * SELECT 표현식 유효성 검증.
     *
     * 허용:
     * - *
     * - column, table.column, table.*
     * - column AS alias
     * - COUNT(*), COUNT(column), MAX(column) ... AS alias
     *
     * 그 외 복잡한 SQL은 raw select API를 추가하기 전까지 직접 SQL을 사용한다.
     */
    private function assertSelectExpression(string $expression): void
    {
        $expression = trim($expression);

        if ($expression === '*') {
            return;
        }

        $identifier = '[a-zA-Z_][a-zA-Z0-9_]*(?:\.[a-zA-Z_][a-zA-Z0-9_]*)?';
        $alias = '[a-zA-Z_][a-zA-Z0-9_]*';

        $patterns = [
            "/^{$identifier}$/",
            "/^[a-zA-Z_][a-zA-Z0-9_]*\.\*$/",
            "/^{$identifier}\s+(?:AS\s+)?{$alias}$/i",
            "/^(?:COUNT|SUM|AVG|MIN|MAX)\((?:\*|{$identifier})\)\s+(?:AS\s+)?{$alias}$/i",
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $expression)) {
                return;
            }
        }

        throw new DatabaseException("Invalid SELECT expression: {$expression}");
    }

    /**
     * WHERE/HAVING 연산자 유효성 검증
     *
     * @param string $operator 검증할 연산자
     * @throws DatabaseException 허용되지 않은 연산자
     */
    private function assertOperator(string $operator): void
    {
        $allowed = ['=', '!=', '<>', '>', '>=', '<', '<=', 'LIKE', 'NOT LIKE'];
        if (!in_array(strtoupper($operator), $allowed, true)) {
            throw new DatabaseException("Invalid operator: {$operator}");
        }
    }

    /**
     * 컬럼 간 비교 연산자 유효성 검증.
     */
    private function assertColumnComparisonOperator(string $operator): void
    {
        $allowed = ['=', '!=', '<>', '>', '>=', '<', '<='];
        if (!in_array(strtoupper($operator), $allowed, true)) {
            throw new DatabaseException("Invalid column comparison operator: {$operator}");
        }
    }

    /**
     * INSERT/UPDATE 컬럼 키 배열 유효성 검증
     *
     * @param array $data 검증할 키-값 배열
     * @throws DatabaseException 유효하지 않은 키
     */
    private function assertColumnKeys(array $data): void
    {
        foreach (array_keys($data) as $key) {
            $this->assertIdentifier((string)$key);
        }
    }

    /**
     * 논리 연결자 유효성 검증.
     */
    private function assertBoolean(string $boolean): void
    {
        if (!in_array(strtoupper($boolean), ['AND', 'OR'], true)) {
            throw new DatabaseException("Invalid boolean operator: {$boolean}");
        }
    }

    /**
     * Raw SQL의 ? placeholder 개수와 바인딩 개수를 검증한다.
     */
    private function assertPlaceholderCount(string $sql, array $bindings): void
    {
        $placeholderCount = $this->countPlaceholders($sql);
        if ($placeholderCount !== count($bindings)) {
            throw new DatabaseException(
                "Raw SQL placeholder count mismatch: expected {$placeholderCount}, got " . count($bindings)
            );
        }
    }

    /**
     * 문자열 리터럴 내부의 ?는 제외하고 placeholder를 센다.
     */
    private function countPlaceholders(string $sql): int
    {
        $count = 0;
        $length = strlen($sql);
        $quote = null;

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];

            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                    continue;
                }

                if ($char === $quote) {
                    if ($i + 1 < $length && $sql[$i + 1] === $quote) {
                        $i++;
                        continue;
                    }

                    $quote = null;
                }
                continue;
            }

            if ($char === '\'' || $char === '"') {
                $quote = $char;
                continue;
            }

            if ($char === '?') {
                $count++;
            }
        }

        return $count;
    }

    /**
     * WHERE 없는 UPDATE/DELETE를 명시적으로 허용한다.
     *
     * 설정 단일행 테이블 초기화처럼 전체 테이블 작업이 의도된 경우에만 사용한다.
     */
    public function allowFullTableOperation(bool $allow = true): self
    {
        $this->allowFullTableOperation = $allow;
        return $this;
    }

    /**
     * WHERE 조건 추가
     *
     * @param string|callable $column 컬럼명 또는 클로저
     * @param mixed $operator 연산자 또는 값
     * @param mixed $value 값
     * @param string $boolean AND/OR
     * @return self
     */
    public function where($column, $operator = null, $value = null, string $boolean = 'AND'): self
    {
        $this->assertBoolean($boolean);

        // 클로저 지원 (중첩 조건)
        // is_callable 대신 Closure 체크 — PHP 내장 함수명(filter_id 등)과 충돌 방지
        if ($column instanceof \Closure) {
            return $this->whereNested($column, $boolean);
        }

        // 2개 인자: where('column', 'value') -> where('column', '=', 'value')
        // 값이 명시적으로 null인 3개 인자 호출과 구분해야 한다.
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        $this->assertIdentifier($column);
        $normalizedOperator = strtoupper((string) $operator);

        // 일반 where()에 자주 전달하는 특수 연산자를 안전한 전용 조건으로 연결한다.
        if ($value === null) {
            return match ($normalizedOperator) {
                '=', 'IS' => $this->whereNull($column, $boolean),
                '!=', '<>', 'IS NOT' => $this->whereNotNull($column, $boolean),
                default => throw new DatabaseException(
                    "NULL can only be compared with =, !=, <>, IS, or IS NOT: {$normalizedOperator}"
                ),
            };
        }

        if ($normalizedOperator === 'IN' || $normalizedOperator === 'NOT IN') {
            if (!is_array($value)) {
                throw new DatabaseException("{$normalizedOperator} expects an array value");
            }

            return $normalizedOperator === 'IN'
                ? $this->whereIn($column, $value, $boolean)
                : $this->whereNotIn($column, $value, $boolean);
        }

        if ($normalizedOperator === 'BETWEEN' || $normalizedOperator === 'NOT BETWEEN') {
            if (!is_array($value) || count($value) !== 2) {
                throw new DatabaseException("{$normalizedOperator} expects exactly two values");
            }

            $range = array_values($value);
            return $normalizedOperator === 'BETWEEN'
                ? $this->whereBetween($column, $range[0], $range[1], $boolean)
                : $this->whereNotBetween($column, $range[0], $range[1], $boolean);
        }

        if ($normalizedOperator === 'IS' || $normalizedOperator === 'IS NOT') {
            throw new DatabaseException("{$normalizedOperator} is only supported with NULL");
        }

        $this->assertOperator($normalizedOperator);

        $this->wheres[] = [
            'type' => 'basic',
            'column' => $column,
            'operator' => $normalizedOperator,
            'value' => $value,
            'boolean' => $boolean,
        ];

        $this->addBinding($value);

        return $this;
    }

    /**
     * OR WHERE 조건 추가
     *
     * @param string|callable $column 컬럼명 또는 클로저
     * @param mixed $operator 연산자 또는 값
     * @param mixed $value 값
     * @return self
     */
    public function orWhere($column, $operator = null, $value = null): self
    {
        if (func_num_args() === 2) {
            return $this->where($column, '=', $operator, 'OR');
        }

        return $this->where($column, $operator, $value, 'OR');
    }

    /**
     * 컬럼 간 비교 WHERE 조건 추가.
     */
    public function whereColumn(string $first, string $operator, string $second, string $boolean = 'AND'): self
    {
        $this->assertBoolean($boolean);
        $this->assertIdentifier($first);
        $this->assertIdentifier($second);
        $this->assertColumnComparisonOperator($operator);

        $this->wheres[] = [
            'type' => 'column',
            'first' => $first,
            'operator' => $operator,
            'second' => $second,
            'boolean' => $boolean,
        ];

        return $this;
    }

    /**
     * OR 컬럼 간 비교 WHERE 조건 추가.
     */
    public function orWhereColumn(string $first, string $operator, string $second): self
    {
        return $this->whereColumn($first, $operator, $second, 'OR');
    }

    /**
     * 부분 일치 LIKE 조건 추가.
     */
    public function whereLike(string $column, string $value, string $boolean = 'AND'): self
    {
        $this->assertBoolean($boolean);
        $this->assertIdentifier($column);

        $this->wheres[] = [
            'type' => 'like',
            'column' => $column,
            'boolean' => $boolean,
        ];

        $this->addBinding('%' . $this->escapeLikeValue($value) . '%');

        return $this;
    }

    /**
     * OR 부분 일치 LIKE 조건 추가.
     */
    public function orWhereLike(string $column, string $value): self
    {
        return $this->whereLike($column, $value, 'OR');
    }

    /**
     * LIKE 패턴에서 사용자 입력의 wildcard 문자를 리터럴로 처리한다.
     */
    private function escapeLikeValue(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * WHERE IN 조건
     *
     * @param string $column 컬럼명
     * @param array $values 값 배열
     * @param string $boolean AND/OR
     * @return self
     */
    public function whereIn(string $column, array $values, string $boolean = 'AND'): self
    {
        $this->assertBoolean($boolean);
        $this->assertIdentifier($column);

        if (empty($values)) {
            return $this->whereRaw('0 = 1', [], $boolean);
        }

        $this->wheres[] = [
            'type' => 'in',
            'column' => $column,
            'values' => $values,
            'boolean' => $boolean,
        ];

        foreach ($values as $value) {
            $this->addBinding($value);
        }

        return $this;
    }

    /**
     * OR WHERE IN 조건
     *
     * @param string $column 컬럼명
     * @param array $values 값 배열
     * @return self
     */
    public function orWhereIn(string $column, array $values): self
    {
        return $this->whereIn($column, $values, 'OR');
    }

    /**
     * WHERE NOT IN 조건
     *
     * @param string $column 컬럼명
     * @param array $values 값 배열
     * @param string $boolean AND/OR
     * @return self
     */
    public function whereNotIn(string $column, array $values, string $boolean = 'AND'): self
    {
        $this->assertBoolean($boolean);
        $this->assertIdentifier($column);

        if (empty($values)) {
            return $this->whereRaw('1 = 1', [], $boolean);
        }

        $this->wheres[] = [
            'type' => 'not_in',
            'column' => $column,
            'values' => $values,
            'boolean' => $boolean,
        ];

        foreach ($values as $value) {
            $this->addBinding($value);
        }

        return $this;
    }

    /**
     * OR WHERE NOT IN 조건
     *
     * @param string $column 컬럼명
     * @param array $values 값 배열
     * @return self
     */
    public function orWhereNotIn(string $column, array $values): self
    {
        return $this->whereNotIn($column, $values, 'OR');
    }

    /**
     * WHERE NULL 조건
     *
     * @param string $column 컬럼명
     * @param string $boolean AND/OR
     * @return self
     */
    public function whereNull(string $column, string $boolean = 'AND'): self
    {
        $this->assertBoolean($boolean);
        $this->assertIdentifier($column);

        $this->wheres[] = [
            'type' => 'null',
            'column' => $column,
            'boolean' => $boolean,
        ];

        return $this;
    }

    /**
     * OR WHERE NULL 조건
     *
     * @param string $column 컬럼명
     * @return self
     */
    public function orWhereNull(string $column): self
    {
        return $this->whereNull($column, 'OR');
    }

    /**
     * WHERE NOT NULL 조건
     *
     * @param string $column 컬럼명
     * @param string $boolean AND/OR
     * @return self
     */
    public function whereNotNull(string $column, string $boolean = 'AND'): self
    {
        $this->assertBoolean($boolean);
        $this->assertIdentifier($column);

        $this->wheres[] = [
            'type' => 'not_null',
            'column' => $column,
            'boolean' => $boolean,
        ];

        return $this;
    }

    /**
     * OR WHERE NOT NULL 조건
     *
     * @param string $column 컬럼명
     * @return self
     */
    public function orWhereNotNull(string $column): self
    {
        return $this->whereNotNull($column, 'OR');
    }

    /**
     * WHERE BETWEEN 조건
     *
     * @param string $column 컬럼명
     * @param mixed $min 최소값
     * @param mixed $max 최대값
     * @param string $boolean AND/OR
     * @return self
     */
    public function whereBetween(string $column, $min, $max, string $boolean = 'AND'): self
    {
        $this->assertBoolean($boolean);
        $this->assertIdentifier($column);

        $this->wheres[] = [
            'type' => 'between',
            'column' => $column,
            'min' => $min,
            'max' => $max,
            'boolean' => $boolean,
        ];

        $this->addBinding($min);
        $this->addBinding($max);

        return $this;
    }

    /**
     * OR WHERE BETWEEN 조건
     *
     * @param string $column 컬럼명
     * @param mixed $min 최소값
     * @param mixed $max 최대값
     * @return self
     */
    public function orWhereBetween(string $column, $min, $max): self
    {
        return $this->whereBetween($column, $min, $max, 'OR');
    }

    /**
     * WHERE NOT BETWEEN 조건
     */
    public function whereNotBetween(string $column, $min, $max, string $boolean = 'AND'): self
    {
        $this->assertBoolean($boolean);
        $this->assertIdentifier($column);

        $this->wheres[] = [
            'type' => 'not_between',
            'column' => $column,
            'min' => $min,
            'max' => $max,
            'boolean' => $boolean,
        ];

        $this->addBinding($min);
        $this->addBinding($max);

        return $this;
    }

    /**
     * OR WHERE NOT BETWEEN 조건
     */
    public function orWhereNotBetween(string $column, $min, $max): self
    {
        return $this->whereNotBetween($column, $min, $max, 'OR');
    }

    /**
     * WHERE RAW (원시 SQL 조건)
     *
     * @param string $sql SQL 조건
     * @param array $bindings 바인딩 값
     * @param string $boolean AND/OR
     * @return self
     */
    public function whereRaw(string $sql, array $bindings = [], string $boolean = 'AND'): self
    {
        $this->assertBoolean($boolean);
        $this->assertPlaceholderCount($sql, $bindings);

        $this->wheres[] = [
            'type' => 'raw',
            'sql' => $sql,
            'boolean' => $boolean,
        ];

        foreach ($bindings as $binding) {
            $this->addBinding($binding);
        }

        return $this;
    }

    /**
     * OR WHERE RAW
     *
     * @param string $sql SQL 조건
     * @param array $bindings 바인딩 값
     * @return self
     */
    public function orWhereRaw(string $sql, array $bindings = []): self
    {
        return $this->whereRaw($sql, $bindings, 'OR');
    }

    /**
     * 중첩 WHERE 조건 (클로저)
     *
     * @param callable $callback 클로저
     * @param string $boolean AND/OR
     * @return self
     */
    protected function whereNested(callable $callback, string $boolean = 'AND'): self
    {
        $this->assertBoolean($boolean);

        // new static() 은 서브클래스가 생성자 시그니처를 바꾸면 깨진다.
        // 중첩 조건은 항상 기본 빌더면 충분하므로 self 로 고정한다.
        $query = new self($this->db, '');
        $query->table = $this->table;

        call_user_func($callback, $query);

        if (!empty($query->wheres)) {
            $this->wheres[] = [
                'type' => 'nested',
                'query' => $query,
                'boolean' => $boolean,
            ];

            foreach ($query->whereBindings as $binding) {
                $this->addBinding($binding);
            }
        }

        return $this;
    }

    /**
     * JOIN 추가
     *
     * @param string $table 테이블명
     * @param string|callable $first 첫 번째 컬럼 또는 클로저
     * @param string|null $operator 연산자
     * @param string|null $second 두 번째 컬럼
     * @param string $type JOIN 타입
     * @return self
     */
    public function join(string $table, $first, ?string $operator = null, ?string $second = null, string $type = 'INNER'): self
    {
        $join = new JoinClause($type, $table);

        if (is_callable($first)) {
            call_user_func($first, $join);
        } else {
            $join->on($first, $operator, $second);
        }

        $this->joins[] = $join;

        return $this;
    }

    /**
     * LEFT JOIN
     *
     * @param string $table 테이블명
     * @param string|callable $first 첫 번째 컬럼 또는 클로저
     * @param string|null $operator 연산자
     * @param string|null $second 두 번째 컬럼
     * @return self
     */
    public function leftJoin(string $table, $first, ?string $operator = null, ?string $second = null): self
    {
        return $this->join($table, $first, $operator, $second, 'LEFT');
    }

    /**
     * RIGHT JOIN
     *
     * @param string $table 테이블명
     * @param string|callable $first 첫 번째 컬럼 또는 클로저
     * @param string|null $operator 연산자
     * @param string|null $second 두 번째 컬럼
     * @return self
     */
    public function rightJoin(string $table, $first, ?string $operator = null, ?string $second = null): self
    {
        return $this->join($table, $first, $operator, $second, 'RIGHT');
    }

    /**
     * CROSS JOIN
     *
     * @param string $table 테이블명
     * @return self
     */
    public function crossJoin(string $table): self
    {
        $join = new JoinClause('CROSS', $table);
        $this->joins[] = $join;

        return $this;
    }

    /**
     * GROUP BY 추가
     *
     * @param string|array $columns 컬럼 목록
     * @return self
     */
    public function groupBy($columns): self
    {
        $columns = is_array($columns) ? $columns : func_get_args();

        // 컬럼명 형식 검증 (SQL Injection 방지)
        foreach ($columns as $col) {
            $this->assertIdentifier((string) $col);
        }

        $this->groupBy = array_merge($this->groupBy, $columns);

        return $this;
    }

    /**
     * HAVING 조건 추가
     *
     * @param string $column 컬럼명
     * @param string $operator 연산자
     * @param mixed $value 값
     * @return self
     */
    public function having(string $column, string $operator, $value): self
    {
        $this->assertIdentifier($column);
        $this->assertOperator($operator);

        $this->having[] = [
            'column' => $column,
            'operator' => $operator,
            'value' => $value,
            'boolean' => 'AND',
        ];

        $this->addBinding($value, 'having');

        return $this;
    }

    /**
     * OR HAVING 조건 추가
     *
     * @param string $column 컬럼명
     * @param string $operator 연산자
     * @param mixed $value 값
     * @return self
     */
    public function orHaving(string $column, string $operator, $value): self
    {
        $this->assertIdentifier($column);
        $this->assertOperator($operator);

        $this->having[] = [
            'column' => $column,
            'operator' => $operator,
            'value' => $value,
            'boolean' => 'OR',
        ];

        $this->addBinding($value, 'having');

        return $this;
    }

    /**
     * ORDER BY 추가
     *
     * @param string $column 컬럼명 또는 RAW SQL
     * @param string $direction 정렬 방향 (ASC/DESC)
     * @return self
     */
    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        // 컬럼명 형식 검증 (SQL Injection 방지)
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_.]*$/', $column)) {
            throw new DatabaseException("Invalid ORDER BY column: {$column}");
        }

        $direction = strtoupper($direction);
        if (!in_array($direction, ['ASC', 'DESC'], true)) {
            $direction = 'ASC';
        }

        $this->orderBy[] = [
            'column' => $column,
            'direction' => $direction,
        ];

        return $this;
    }

    /**
     * ORDER BY DESC
     *
     * @param string $column 컬럼명
     * @return self
     */
    public function orderByDesc(string $column): self
    {
        return $this->orderBy($column, 'DESC');
    }

    /**
     * ORDER BY (Raw SQL)
     *
     * @param string $sql SQL 표현식 (예: "FIELD(id, ?, ?, ?)")
     * @param array $bindings 바인딩 값
     * @return self
     */
    public function orderByRaw(string $sql, array $bindings = []): self
    {
        $this->assertPlaceholderCount($sql, $bindings);

        $this->orderBy[] = [
            'raw' => $sql,
        ];

        if (!empty($bindings)) {
            $this->orderBindings = array_merge($this->orderBindings, $bindings);
        }

        return $this;
    }

    /**
     * LIMIT 설정
     *
     * @param int $limit 제한 수
     * @return self
     */
    public function limit(int $limit): self
    {
        if ($limit < 0) {
            throw new DatabaseException("LIMIT cannot be negative: {$limit}");
        }

        $this->limitValue = $limit;
        return $this;
    }

    /**
     * OFFSET 설정
     *
     * @param int $offset 오프셋
     * @return self
     */
    public function offset(int $offset): self
    {
        if ($offset < 0) {
            throw new DatabaseException("OFFSET cannot be negative: {$offset}");
        }

        $this->offsetValue = $offset;
        return $this;
    }

    /**
     * LIMIT과 OFFSET을 동시에 설정 (페이징)
     *
     * @param int $limit 제한 수
     * @param int $offset 오프셋
     * @return self
     */
    public function take(int $limit, int $offset = 0): self
    {
        $this->limit($limit);
        $this->offset($offset);
        return $this;
    }

    /**
     * 페이지 기반 페이징
     *
     * @param int $page 페이지 번호 (1부터 시작)
     * @param int $perPage 페이지당 항목 수
     * @return self
     */
    public function forPage(int $page, int $perPage = 15): self
    {
        if ($page < 1) {
            throw new DatabaseException("Page must be greater than or equal to 1: {$page}");
        }

        if ($perPage < 1) {
            throw new DatabaseException("Per-page value must be greater than or equal to 1: {$perPage}");
        }

        $offset = ($page - 1) * $perPage;
        return $this->take($perPage, $offset);
    }

    /**
     * SELECT 쿼리 실행 (여러 행)
     *
     * @return array
     * @throws DatabaseException
     */
    public function get(): array
    {
        $sql = $this->toSql();
        
        // [측정 시작]
        //$qStart = microtime(true);
        $result = $this->db->select($sql, $this->compileBindings());
        //$qEnd = microtime(true);
        
        // 전역 변수 누적
        //$GLOBALS['__queryTime'] = ($GLOBALS['__queryTime'] ?? 0) + ($qEnd - $qStart) * 1000;
        //$GLOBALS['__queryCount'] = ($GLOBALS['__queryCount'] ?? 0) + 1;
        
        return $result;
    }
    
    /**
     * SELECT 쿼리 실행 (단일 행)
     *
     * @return array|null
     * @throws DatabaseException
     */
    public function first(): ?array
    {
        $originalLimit = $this->limitValue;

        try {
            $sql = $this->limit(1)->toSql();
            return $this->db->selectOne($sql, $this->compileBindings());
        } finally {
            $this->limitValue = $originalLimit;
        }
    }

    /**
     * COUNT 집계
     *
     * @param string $column 컬럼명
     * @return int
     * @throws DatabaseException
     */
    public function count(string $column = '*'): int
    {
        return (int) $this->aggregate('COUNT', $column);
    }

    /**
     * 페이지네이션 총 개수 조회.
     */
    public function countForPagination(string $column = '*'): int
    {
        $query = clone $this;

        // 그룹/중복 제거 쿼리는 첫 그룹의 COUNT가 아니라 결과 행 수를 세어야 한다.
        if ($query->groupBy !== [] || $query->distinct) {
            $query->orderBy = [];
            $query->orderBindings = [];
            $query->limitValue = null;
            $query->offsetValue = null;

            $row = $this->db->selectOne(
                'SELECT COUNT(*) AS aggregate FROM (' . $query->toSql() . ') AS mublo_count',
                $query->compileBindings()
            );

            return (int) ($row['aggregate'] ?? 0);
        }

        return $query->count($column);
    }

    /**
     * 현재 조건을 유지한 채 목록과 페이지 정보를 함께 반환한다.
     *
     * @return array{data: array, total: int, page: int, per_page: int, total_pages: int}
     */
    public function paginate(int $page = 1, int $perPage = 15, string $countColumn = '*'): array
    {
        if ($page < 1) {
            throw new DatabaseException("Page must be greater than or equal to 1: {$page}");
        }
        if ($perPage < 1) {
            throw new DatabaseException("Per-page value must be greater than or equal to 1: {$perPage}");
        }

        $total = $this->countForPagination($countColumn);
        $itemsQuery = clone $this;
        $items = $itemsQuery->forPage($page, $perPage)->get();

        return [
            'data' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => (int) ceil($total / $perPage),
        ];
    }

    /**
     * 레코드 존재 여부 확인
     *
     * @return bool
     * @throws DatabaseException
     */
    public function exists(): bool
    {
        $query = clone $this;

        // 존재 여부에는 정렬·페이지 범위가 필요 없다. 원본 빌더는 그대로 둔다.
        $query->orderBy = [];
        $query->orderBindings = [];
        $query->limitValue = null;
        $query->offsetValue = null;

        $row = $this->db->selectOne(
            'SELECT EXISTS(' . $query->toSql() . ') AS exists_flag',
            $query->compileBindings()
        );

        return (int) ($row['exists_flag'] ?? 0) === 1;
    }

    /**
     * SUM 집계
     *
     * @param string $column 컬럼명
     * @return float
     * @throws DatabaseException
     */
    public function sum(string $column): float
    {
        return (float) $this->aggregate('SUM', $column);
    }

    /**
     * AVG 집계
     *
     * @param string $column 컬럼명
     * @return float
     * @throws DatabaseException
     */
    public function avg(string $column): float
    {
        return (float) $this->aggregate('AVG', $column);
    }

    /**
     * MAX 집계
     *
     * @param string $column 컬럼명
     * @return mixed
     * @throws DatabaseException
     */
    public function max(string $column)
    {
        return $this->aggregate('MAX', $column);
    }

    /**
     * MIN 집계
     *
     * @param string $column 컬럼명
     * @return mixed
     * @throws DatabaseException
     */
    public function min(string $column)
    {
        return $this->aggregate('MIN', $column);
    }

    /**
     * 집계 함수 실행
     *
     * @param string $function 집계 함수명
     * @param string $column 컬럼명
     * @return mixed
     * @throws DatabaseException
     */
    protected function aggregate(string $function, string $column)
    {
        $function = strtoupper($function);
        if (!in_array($function, ['COUNT', 'SUM', 'AVG', 'MAX', 'MIN'], true)) {
            throw new DatabaseException("Invalid aggregate function: {$function}");
        }

        if ($column !== '*') {
            $this->assertIdentifier($column);
        }

        $originalColumns = $this->columns;
        $originalOrderBy = $this->orderBy;
        $originalLimit = $this->limitValue;
        $originalOffset = $this->offsetValue;

        try {
            $this->columns = ["{$function}({$column}) as aggregate"];
            $this->orderBy = [];
            $this->limitValue = null;
            $this->offsetValue = null;

            $sql = $this->toSql();
            $result = $this->db->selectOne($sql, $this->compileBindings());

            return $result['aggregate'] ?? 0;
        } finally {
            $this->columns = $originalColumns;
            $this->orderBy = $originalOrderBy;
            $this->limitValue = $originalLimit;
            $this->offsetValue = $originalOffset;
        }
    }

    /**
     * INSERT 쿼리 실행
     *
     * @param array $data 삽입 데이터
     * @return int 마지막 삽입 ID
     * @throws DatabaseException
     */
    public function insert(array $data): int
    {
        if (empty($data)) {
            throw new DatabaseException('Insert data cannot be empty');
        }

        $this->assertColumnKeys($data);

        $columns = array_keys($data);
        $placeholders = array_fill(0, count($columns), '?');

        $sql = "INSERT INTO {$this->table} (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";

        return $this->db->insert($sql, array_values($data));
    }

    /**
     * INSERT ... ON DUPLICATE KEY UPDATE
     *
     * @param array $data 삽입 데이터
     * @param array $updateData 업데이트 데이터 (생략 시 $data 사용)
     * @return int
     * @throws DatabaseException
     */
    public function insertOrUpdate(array $data, array $updateData = []): int
    {
        if (empty($data)) {
            throw new DatabaseException('Insert data cannot be empty');
        }

        if (empty($updateData)) {
            $updateData = $data;
        }

        $this->assertColumnKeys($data);
        $this->assertColumnKeys($updateData);

        $columns = array_keys($data);
        $placeholders = array_fill(0, count($columns), '?');

        $sql = "INSERT INTO {$this->table} (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";

        $updateParts = [];
        foreach (array_keys($updateData) as $column) {
            $updateParts[] = "{$column} = ?";
        }

        $sql .= " ON DUPLICATE KEY UPDATE " . implode(', ', $updateParts);

        $bindings = array_merge(array_values($data), array_values($updateData));

        return $this->db->insert($sql, $bindings);
    }

    /**
     * UPDATE 쿼리 실행
     *
     * @param array $data 업데이트 데이터
     * @return int 영향받은 행 수
     * @throws DatabaseException
     */
    public function update(array $data): int
    {
        if (empty($data)) {
            throw new DatabaseException('Update data cannot be empty');
        }

        if (empty($this->wheres) && !$this->allowFullTableOperation) {
            throw new DatabaseException('UPDATE without WHERE is not allowed. Call allowFullTableOperation() explicitly if intended.');
        }

        $this->assertColumnKeys($data);

        $setParts = [];
        $bindings = [];

        foreach ($data as $column => $value) {
            $setParts[] = "{$column} = ?";
            $bindings[] = $value;
        }

        $sql = "UPDATE {$this->table} SET " . implode(', ', $setParts);

        if (!empty($this->wheres)) {
            $sql .= ' ' . $this->buildWhere();
            $bindings = array_merge($bindings, $this->whereBindings);
        }

        return $this->db->execute($sql, $bindings);
    }

    /** 조건에 맞는 숫자 컬럼을 원자적으로 증가시킨다. */
    public function increment(string $column, int|float $amount = 1): int
    {
        return $this->adjustColumn($column, $amount, '+');
    }

    /** 조건에 맞는 숫자 컬럼을 원자적으로 감소시킨다. */
    public function decrement(string $column, int|float $amount = 1): int
    {
        return $this->adjustColumn($column, $amount, '-');
    }

    private function adjustColumn(string $column, int|float $amount, string $operator): int
    {
        $this->assertIdentifier($column);
        if ($amount < 0) {
            throw new DatabaseException('Increment/decrement amount cannot be negative');
        }
        if (empty($this->wheres) && !$this->allowFullTableOperation) {
            throw new DatabaseException(
                'Increment/decrement without WHERE is not allowed. Call allowFullTableOperation() explicitly if intended.'
            );
        }

        $sql = "UPDATE {$this->table} SET {$column} = {$column} {$operator} ?";
        if (!empty($this->wheres)) {
            $sql .= ' ' . $this->buildWhere();
        }

        return $this->db->execute($sql, [$amount, ...$this->whereBindings]);
    }

    /**
     * DELETE 쿼리 실행
     *
     * @return int 영향받은 행 수
     * @throws DatabaseException
     */
    public function delete(): int
    {
        if (empty($this->wheres) && !$this->allowFullTableOperation) {
            throw new DatabaseException('DELETE without WHERE is not allowed. Call allowFullTableOperation() explicitly if intended.');
        }

        $sql = "DELETE FROM {$this->table}";

        if (!empty($this->wheres)) {
            $sql .= ' ' . $this->buildWhere();
        }

        return $this->db->execute($sql, $this->whereBindings);
    }

    /**
     * SELECT SQL 생성
     *
     * @return string
     */
    public function toSql(): string
    {
        $sql = 'SELECT ';

        if ($this->distinct) {
            $sql .= 'DISTINCT ';
        }

        $sql .= implode(', ', $this->columns);
        $sql .= " FROM {$this->table}";

        // JOIN
        if (!empty($this->joins)) {
            foreach ($this->joins as $join) {
                $sql .= ' ' . $join->toSql();
            }
        }

        // WHERE
        if (!empty($this->wheres)) {
            $sql .= ' ' . $this->buildWhere();
        }

        // GROUP BY
        if (!empty($this->groupBy)) {
            $sql .= ' GROUP BY ' . implode(', ', $this->groupBy);
        }

        // HAVING
        if (!empty($this->having)) {
            $sql .= ' ' . $this->buildHaving();
        }

        // ORDER BY
        if (!empty($this->orderBy)) {
            $parts = [];
            foreach ($this->orderBy as $order) {
                if (isset($order['raw'])) {
                    $parts[] = $order['raw'];
                } else {
                    $parts[] = "{$order['column']} {$order['direction']}";
                }
            }
            $sql .= ' ORDER BY ' . implode(', ', $parts);
        }

        // LIMIT
        if ($this->limitValue !== null) {
            $sql .= " LIMIT {$this->limitValue}";
        }

        // OFFSET
        if ($this->offsetValue !== null) {
            $sql .= " OFFSET {$this->offsetValue}";
        }

        return $sql;
    }

    /**
     * WHERE 절 SQL 생성
     *
     * @return string
     */
    protected function buildWhere(): string
    {
        if (empty($this->wheres)) {
            return '';
        }

        $sql = 'WHERE ';
        $parts = [];

        foreach ($this->wheres as $index => $where) {
            $part = '';

            if ($index > 0) {
                $part .= "{$where['boolean']} ";
            }

            switch ($where['type']) {
                case 'basic':
                    $part .= "{$where['column']} {$where['operator']} ?";
                    break;

                case 'column':
                    $part .= "{$where['first']} {$where['operator']} {$where['second']}";
                    break;

                case 'like':
                    $part .= "{$where['column']} LIKE ? ESCAPE '\\\\'";
                    break;

                case 'in':
                    $placeholders = implode(', ', array_fill(0, count($where['values']), '?'));
                    $part .= "{$where['column']} IN ({$placeholders})";
                    break;

                case 'not_in':
                    $placeholders = implode(', ', array_fill(0, count($where['values']), '?'));
                    $part .= "{$where['column']} NOT IN ({$placeholders})";
                    break;

                case 'null':
                    $part .= "{$where['column']} IS NULL";
                    break;

                case 'not_null':
                    $part .= "{$where['column']} IS NOT NULL";
                    break;

                case 'between':
                    $part .= "{$where['column']} BETWEEN ? AND ?";
                    break;

                case 'not_between':
                    $part .= "{$where['column']} NOT BETWEEN ? AND ?";
                    break;

                case 'raw':
                    $part .= $where['sql'];
                    break;

                case 'nested':
                    $nestedSql = $where['query']->buildWhere();
                    $part .= '(' . substr($nestedSql, 6) . ')'; // "WHERE " 제거
                    break;
            }

            $parts[] = $part;
        }

        return $sql . implode(' ', $parts);
    }

    /**
     * HAVING 절 SQL 생성
     *
     * @return string
     */
    protected function buildHaving(): string
    {
        if (empty($this->having)) {
            return '';
        }

        $sql = 'HAVING ';
        $parts = [];

        foreach ($this->having as $index => $having) {
            $part = '';

            if ($index > 0) {
                $part .= "{$having['boolean']} ";
            }

            $part .= "{$having['column']} {$having['operator']} ?";
            $parts[] = $part;
        }

        return $sql . implode(' ', $parts);
    }

    /**
     * 바인딩 추가
     *
     * @param mixed $value 바인딩 값
     */
    protected function addBinding($value, string $type = 'where'): void
    {
        match ($type) {
            'where' => $this->whereBindings[] = $value,
            'having' => $this->havingBindings[] = $value,
            'order' => $this->orderBindings[] = $value,
            default => throw new DatabaseException("Invalid binding type: {$type}"),
        };
    }

    /** SQL 생성 순서(WHERE → HAVING → ORDER BY)에 맞춰 바인딩을 결합한다. */
    private function compileBindings(): array
    {
        $bindings = $this->whereBindings;
        if ($this->having !== []) {
            $bindings = array_merge($bindings, $this->havingBindings);
        }
        if ($this->orderBy !== []) {
            $bindings = array_merge($bindings, $this->orderBindings);
        }

        return $bindings;
    }

    /**
     * 쿼리 초기화 (재사용)
     *
     * @return self
     */
    public function reset(): self
    {
        $this->columns = ['*'];
        $this->joins = [];
        $this->wheres = [];
        $this->whereBindings = [];
        $this->havingBindings = [];
        $this->orderBindings = [];
        $this->groupBy = [];
        $this->having = [];
        $this->orderBy = [];
        $this->limitValue = null;
        $this->offsetValue = null;
        $this->distinct = false;
        $this->allowFullTableOperation = false;

        return $this;
    }

    /**
     * 현재 바인딩 값 반환 (디버깅용)
     *
     * @return array
     */
    public function getBindings(): array
    {
        return $this->compileBindings();
    }

    /**
     * 디버그: SQL과 바인딩 출력
     *
     * @return array
     */
    public function debug(): array
    {
        return [
            'sql' => $this->toSql(),
            'bindings' => $this->compileBindings(),
        ];
    }
}
