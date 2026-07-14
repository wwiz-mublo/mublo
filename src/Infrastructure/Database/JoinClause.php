<?php
namespace Mublo\Infrastructure\Database;

/**
 * JoinClause
 *
 * JOIN 절을 위한 서브 빌더 클래스
 *
 * 용도:
 * - 복잡한 JOIN 조건 구성
 * - ON / OR ON 조건 체인
 * - 중첩 조건 지원
 */
class JoinClause
{
    protected string $type;
    protected string $table;
    protected array $conditions = [];

    /**
     * Constructor
     *
     * @param string $type JOIN 타입 (INNER, LEFT, RIGHT, CROSS)
     * @param string $table 테이블 이름
     */
    public function __construct(string $type, string $table)
    {
        $this->type = strtoupper($type);
        $this->table = $table;

        $this->assertJoinType($this->type);
        $this->assertTableIdentifier($this->table);
    }

    /**
     * JOIN 컬럼 식별자 유효성 검증
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
     * JOIN 대상 테이블 식별자 유효성 검증.
     *
     * 허용:
     * - table, schema.table
     * - table AS alias / table alias (JOIN 별칭)
     */
    private function assertTableIdentifier(string $table): void
    {
        $identifier = '[a-zA-Z_][a-zA-Z0-9_]*(?:\.[a-zA-Z_][a-zA-Z0-9_]*)?';
        $alias = '[a-zA-Z_][a-zA-Z0-9_]*';

        if (!preg_match("/^{$identifier}(?:\s+(?:AS\s+)?{$alias})?$/i", $table)) {
            throw new DatabaseException("Invalid JOIN table identifier: {$table}");
        }
    }

    /**
     * JOIN 타입 유효성 검증.
     */
    private function assertJoinType(string $type): void
    {
        if (!in_array($type, ['INNER', 'LEFT', 'RIGHT', 'CROSS'], true)) {
            throw new DatabaseException("Invalid JOIN type: {$type}");
        }
    }

    /**
     * JOIN ON 연산자 유효성 검증 (= != <> 만 허용)
     *
     * @param string $operator 검증할 연산자
     * @throws DatabaseException 허용되지 않은 연산자
     */
    private function assertJoinOperator(string $operator): void
    {
        $allowed = ['=', '!=', '<>'];
        if (!in_array($operator, $allowed, true)) {
            throw new DatabaseException("Invalid JOIN operator: {$operator}");
        }
    }

    /**
     * ON 조건 추가
     *
     * @param string $first 첫 번째 컬럼
     * @param string $operator 연산자
     * @param string|null $second 두 번째 컬럼
     * @return self
     */
    public function on(string $first, string $operator, ?string $second = null): self
    {
        if ($second === null) {
            $second = $operator;
            $operator = '=';
        }

        $this->assertIdentifier($first);
        $this->assertIdentifier($second);
        $this->assertJoinOperator($operator);

        $this->conditions[] = [
            'type' => 'AND',
            'first' => $first,
            'operator' => $operator,
            'second' => $second,
        ];

        return $this;
    }

    /**
     * OR ON 조건 추가
     *
     * @param string $first 첫 번째 컬럼
     * @param string $operator 연산자
     * @param string|null $second 두 번째 컬럼
     * @return self
     */
    public function orOn(string $first, string $operator, ?string $second = null): self
    {
        if ($second === null) {
            $second = $operator;
            $operator = '=';
        }

        $this->assertIdentifier($first);
        $this->assertIdentifier($second);
        $this->assertJoinOperator($operator);

        $this->conditions[] = [
            'type' => 'OR',
            'first' => $first,
            'operator' => $operator,
            'second' => $second,
        ];

        return $this;
    }

    /**
     * JOIN 타입 반환
     *
     * @return string
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * 테이블 이름 반환
     *
     * @return string
     */
    public function getTable(): string
    {
        return $this->table;
    }

    /**
     * 조건 배열 반환
     *
     * @return array
     */
    public function getConditions(): array
    {
        return $this->conditions;
    }

    /**
     * JOIN 절 SQL 생성
     *
     * @return string
     */
    public function toSql(): string
    {
        if (empty($this->conditions)) {
            return "{$this->type} JOIN {$this->table}";
        }

        $sql = "{$this->type} JOIN {$this->table} ON ";
        $parts = [];

        foreach ($this->conditions as $condition) {
            $part = "{$condition['first']} {$condition['operator']} {$condition['second']}";

            if (!empty($parts)) {
                $part = "{$condition['type']} {$part}";
            }

            $parts[] = $part;
        }

        $sql .= implode(' ', $parts);

        return $sql;
    }
}
