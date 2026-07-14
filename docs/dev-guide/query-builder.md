# QueryBuilder 개발자 가이드

`src/Infrastructure/Database/QueryBuilder.php`
`src/Infrastructure/Database/JoinClause.php`
`src/Infrastructure/Database/Database.php`

QueryBuilder는 SQL을 문자열로 직접 조립하지 않고, 메서드 체이닝으로 안전하게 쿼리를 구성하기 위한 도구입니다. 이 문서는 [데이터베이스](database.md) 문서의 QueryBuilder 절을 확장한 심화 레퍼런스입니다. 모든 public 메서드의 시그니처, 동작, 실제 Repository 사용 패턴, 그리고 하면 안 되는 안티패턴을 정리합니다.

---

## 1. 개요

### 무엇인가

QueryBuilder는 `Database`(PDO 래퍼) 위에 얹힌 쿼리 조립기입니다. `$db->table('테이블명')`으로 시작해 `where()`, `orderBy()`, `join()` 같은 메서드를 체이닝하고, 마지막에 `get()` / `first()` / `count()` 같은 실행 메서드를 호출하면 SQL이 생성·실행됩니다.

```php
$rows = $db->table('members')
    ->where('status', 'active')
    ->orderBy('created_at', 'DESC')
    ->limit(20)
    ->get();
```

내부적으로 위 코드는 다음과 같은 Prepared Statement로 컴파일됩니다.

```sql
SELECT * FROM members WHERE status = ? ORDER BY created_at DESC LIMIT 20
-- bindings: ['active']
```

### 왜 쓰는가

- **SQL Injection 방어가 기본값** — 값은 항상 `?` placeholder로 바인딩되고, 컬럼명·테이블명·연산자는 화이트리스트/정규식으로 검증됩니다. 문자열 이어붙이기로 쿼리를 만들 때 생기는 사고를 구조적으로 막습니다.
- **가독성** — 조건이 늘어나도 SQL 문자열을 손으로 잇는 것보다 읽고 수정하기 쉽습니다.
- **일관성** — 모든 Repository가 같은 방식으로 DB에 접근하므로, 코드 리뷰와 유지보수가 쉬워집니다.

### 무엇이 아닌가 — 경계

QueryBuilder는 "자주 쓰는 안전한 쿼리"를 위한 도구이지, 모든 SQL을 표현하는 ORM이 아닙니다. 서브쿼리, 복잡한 표현식, 윈도우 함수 등은 지원하지 않습니다. 이런 경우는 `Database`의 raw 실행 메서드(`select()`, `selectOne()`, `insert()`, `execute()`)를 직접 씁니다. 실제로 코드베이스에도 JOIN이 복잡하거나 동적 조건이 많은 조회는 raw SQL로 작성된 경우가 많습니다. ([8. 서브쿼리 / Raw 표현](#8-서브쿼리--raw-표현) 참조)

---

## 2. Database 인스턴스 획득 (DI)

QueryBuilder를 직접 `new` 하는 일은 거의 없습니다. 항상 `Database`를 통해 `table()`로 생성합니다.

```php
public function table(string $table): QueryBuilder
{
    return new QueryBuilder($this, $table);
}
```

### Repository에서 (권장 경로)

Mublo에서 DB 접근은 **Repository 계층의 책임**입니다. 모든 Repository는 `BaseRepository`를 상속하고, 생성자에서 `Database`를 주입받습니다.

```php
class MemberRepository extends BaseRepository
{
    protected string $table = 'members';
    protected string $entityClass = Member::class;
    protected string $primaryKey = 'member_id';

    public function __construct(?Database $db = null)
    {
        $db = $db ?? DatabaseManager::getInstance()->connect();
        parent::__construct($db);
    }

    public function findActive(int $domainId): array
    {
        // $this->db 또는 $this->getDb() 로 접근
        return $this->getDb()->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('status', '=', 'active')
            ->get();
    }
}
```

- `BaseRepository`는 `$this->db`(protected)를 보관하고, `getDb(): Database`로 외부/자식에 노출합니다.
- 자식 Repository는 `$this->db->table(...)` 또는 `$this->getDb()->table(...)` 어느 쪽이든 사용할 수 있습니다. 기존 코드에서는 두 형태가 혼재하지만 동작은 같습니다.

### Service 계층에서

Service는 원칙적으로 DB를 직접 만지지 않고 **Repository를 통해** 접근합니다. 다만 트랜잭션 경계를 잡을 때는 `$repository->getDb()`로 `Database`를 얻어 사용합니다. ([9. 트랜잭션](#9-트랜잭션) 참조)

```php
$this->levelRepository->getDb()->transaction(function () {
    // ... 여러 Repository 호출 ...
});
```

> **원칙:** Controller는 DB에 직접 접근하지 않습니다. Controller → Service → Repository → QueryBuilder/Database 순서로 내려갑니다.

---

## 3. SELECT 쿼리

### 3.1 table(), select(), distinct()

```php
// 전체 컬럼 (기본값 *)
$db->table('members');

// 컬럼 지정 — 가변 인자 또는 배열, 둘 다 가능
$db->table('members')->select('member_id', 'user_id', 'nickname');
$db->table('members')->select(['member_id', 'user_id', 'nickname']);

// 별칭 (AS)
$db->table('members')->select('member_id AS id', 'nickname AS name');

// 테이블 별칭 — table()에 별칭 포함 가능
$db->table('members as m')->select('m.member_id', 'm.nickname');

// 집계 표현식도 select에 직접 가능
$db->table('orders')->select('COUNT(*) AS cnt', 'member_id')->groupBy('member_id');

// DISTINCT
$db->table('members')->select('level_value')->distinct();
```

`select()`가 허용하는 표현식은 검증됩니다([10. 보안](#10-보안) 참조). 허용되는 형태는 다음과 같습니다.

| 형태 | 예시 |
|------|------|
| `*` | `*` |
| 컬럼 / 테이블.컬럼 | `nickname`, `m.nickname` |
| 테이블.\* | `m.*` |
| 컬럼 AS 별칭 | `nickname AS name` |
| 집계 함수 AS 별칭 | `COUNT(*) AS cnt`, `MAX(price) AS max_price` |

그 외 복잡한 SQL 표현식(`CASE WHEN ...`, 서브쿼리, 연산식 등)은 `select()`에 넣으면 `DatabaseException`이 발생합니다. 이 경우 raw SQL을 사용하세요.

### 3.2 where(), orWhere()

`where()`는 2-인자 축약형과 3-인자형을 모두 받습니다.

```php
// 2-인자: 연산자 생략 시 '=' 로 간주
->where('status', 'active')          // status = 'active'

// 3-인자: 연산자 명시
->where('age', '>', 18)              // age > 18
->where('score', '>=', 100)
->where('user_id', '!=', $excludeId)

// OR 연결
->where('status', 'active')
->orWhere('status', 'pending')       // ... OR status = 'pending'
```

허용 연산자: `=`, `!=`, `<>`, `>`, `>=`, `<`, `<=`, `LIKE`, `NOT LIKE`, `IN`, `NOT IN`, `IS`, `IS NOT`, `BETWEEN`, `NOT BETWEEN`. 그 외 연산자는 예외가 발생합니다.

`IN`/`NOT IN`에는 배열, `BETWEEN`/`NOT BETWEEN`에는 정확히 두 값의 배열을 넘기면 각각 전용 조건으로 안전하게 변환됩니다. `null`은 `=`/`IS`일 때 `IS NULL`, `!=`/`<>`/`IS NOT`일 때 `IS NOT NULL`로 변환됩니다.

```php
->where('id', 'IN', [1, 2, 3])
->where('price', 'BETWEEN', [1000, 5000])
->where('deleted_at', '=', null)       // IS NULL
```

**LIKE는 직접 패턴을 넘길 수 있습니다.** 이 경우 wildcard는 escape되지 않으므로, 사용자 입력을 넣을 때는 주의해야 합니다(안전한 부분 일치는 `whereLike()` 사용 권장).

```php
// 기존 코드의 흔한 패턴 — 패턴을 직접 조립
->where('user_id', 'LIKE', '%' . $keyword . '%')
```

**중첩 조건(그룹)** — `where()`에 클로저를 넘기면 괄호로 묶인 그룹이 됩니다.

```php
->where('domain_id', $domainId)
->where(function ($q) {
    $q->where('status', 'active')
      ->orWhere('status', 'pending');
});
// => WHERE domain_id = ? AND (status = ? OR status = ?)
```

> 클로저는 `\Closure` 인스턴스만 인정합니다. `is_callable()`이 아니라 `instanceof \Closure`로 판별하므로, `filter_id` 같은 PHP 내장 함수명 문자열이 컬럼명으로 오인되지 않습니다.

### 3.3 whereColumn() — 컬럼 간 비교

값이 아니라 **다른 컬럼과 비교**할 때 씁니다.

```php
->whereColumn('updated_at', '>', 'created_at')
->orWhereColumn('published_at', '<=', 'reserved_at')
```

허용 연산자는 `=`, `!=`, `<>`, `>`, `>=`, `<`, `<=`만 (LIKE/IN 등 불가). 두 인자 모두 식별자 검증을 거칩니다.

### 3.4 whereLike() — 안전한 부분 일치

사용자 입력의 `%`, `_`, `\`를 escape한 뒤 `%검색어%` 패턴으로 바인딩합니다. 사용자 검색어를 그대로 부분 일치시킬 때 가장 안전한 방법입니다.

```php
->whereLike('title', $keyword)       // title LIKE '%<escaped>%' ESCAPE '\'
->orWhereLike('body', $keyword)
```

`whereLike('title', '50%')`처럼 입력에 `%`가 들어 있어도, 리터럴 `%`로 취급되어 "50%를 포함하는" 검색이 됩니다.

### 3.5 whereIn(), whereNotIn()

```php
->whereIn('status', ['active', 'pending'])
->whereNotIn('level_value', [1, 2])
->orWhereIn('id', [10, 20, 30])
->orWhereNotIn('id', [5, 6])
```

**빈 배열 처리 (중요):**

- `whereIn($col, [])` → `0 = 1`로 컴파일 (항상 거짓 → 결과 없음)
- `whereNotIn($col, [])` → `1 = 1`로 컴파일 (항상 참 → 필터 없음)

빈 배열을 넘겨도 SQL 문법 오류가 나지 않고 논리적으로 올바른 결과를 냅니다.

### 3.6 whereNull(), whereNotNull()

```php
->whereNull('deleted_at')            // deleted_at IS NULL
->whereNotNull('email')              // email IS NOT NULL
->orWhereNull('reserved_at')
->orWhereNotNull('published_at')
```

일반 `where('deleted_at', null)`도 `IS NULL`로 변환되지만, 의도를 분명히 드러내려면 `whereNull()` 사용을 권장합니다.

### 3.7 whereBetween(), whereNotBetween()

```php
->whereBetween('created_at', '2025-01-01', '2025-12-31')
->orWhereBetween('price', 1000, 5000)
->whereNotBetween('score', 0, 59)
->orWhereNotBetween('age', 20, 29)
// => column BETWEEN ? AND ?
```

### 3.8 whereRaw() — 원시 SQL 조건

QueryBuilder가 표현하지 못하는 조건(OR로 묶인 복합 조건, DB 함수 등)을 위한 escape hatch입니다. **값은 반드시 `?` 바인딩으로 전달**해야 하며, 사용자 입력을 SQL 문자열에 직접 이어붙이면 안 됩니다.

```php
// OR로 묶인 복합 조건 (실제 MemberRepository 패턴)
->whereRaw('(user_id LIKE ? OR nickname LIKE ?)', [$like, $like])

// 계층 조회
->whereRaw('(domain_group = ? OR domain_group LIKE ?)', [$domainGroup, $domainGroup . '/%'])

// DB 함수
->whereRaw('YEAR(created_at) = ?', [2025])
```

`whereRaw()`는 SQL의 `?` 개수와 바인딩 배열 크기가 다르면 즉시 예외를 던집니다(문자열 리터럴 안의 `?`는 세지 않음). `orWhereRaw()`도 동일합니다. 이 검사는 바인딩 실수를 찾는 장치이지, 전달한 SQL 조각 자체를 정화하거나 안전하게 만드는 기능은 아닙니다. Raw SQL 문자열은 코드가 통제하고 외부 값만 바인딩으로 전달해야 합니다.

### 3.9 orderBy(), groupBy(), having()

```php
// 정렬
->orderBy('created_at', 'DESC')      // 방향 기본값 ASC
->orderByDesc('created_at')          // orderBy(..., 'DESC') 축약
->orderBy('user_id', 'ASC')

// Raw 정렬 (placeholder 개수 == 바인딩 개수)
->orderByRaw('FIELD(member_id, ?, ?, ?)', [3, 1, 2])

// 그룹핑 (가변 인자 또는 배열)
->groupBy('level_value')
->groupBy('domain_id', 'status')

// HAVING (집계 조건) — 항상 3-인자
->having('cnt', '>', 10)
->orHaving('total', '>=', 1000)
```

- `orderBy()`의 방향값이 `ASC`/`DESC`가 아니면 조용히 `ASC`로 보정됩니다(예외 아님).
- `orderBy()`, `groupBy()`, `having()`의 컬럼명은 식별자 검증을 거칩니다.

### 3.10 limit(), offset(), take(), forPage()

```php
->limit(20)                          // 음수면 예외
->offset(40)                         // 음수면 예외
->take(20, 40)                       // limit(20)->offset(40) 축약
->forPage(3, 20)                     // page=3, perPage=20 → offset=40
```

`forPage($page, $perPage)`는 페이지 번호(1부터)를 오프셋으로 환산합니다. `page < 1`이거나 `perPage < 1`이면 예외를 던집니다.

### 3.11 실행 메서드: get(), first()

QueryBuilder에서 **결과를 실제로 가져오는 메서드는 `get()`과 `first()` 두 개**입니다.

```php
// 여러 행 → array (연관 배열의 배열, 없으면 빈 배열 [])
$rows = $db->table('members')->where('status', 'active')->get();

// 단일 행 → array|null (내부적으로 LIMIT 1 적용, 없으면 null)
$row  = $db->table('members')->where('member_id', 1)->first();
```

- `get()`은 `Database::select()`를 호출합니다.
- `first()`는 일시적으로 `LIMIT 1`을 적용해 `Database::selectOne()`을 호출하고, 원래 limit 값을 복원합니다.

> **존재하지 않는 메서드:** QueryBuilder에는 `selectOne()`, `selectAll()`, `find()` 가 없습니다. 조회는 `get()`(다건) / `first()`(단건)입니다. `selectOne()`/`select()`는 `Database`(raw SQL 실행)에만 있습니다. 헷갈리지 마세요.

### 3.12 paginate()

현재 조건과 정렬을 유지하면서 총 개수, 현재 페이지 목록과 메타데이터를 함께 반환합니다. 그룹 쿼리와 `distinct()`도 최종 결과 행 수를 기준으로 계산합니다.

```php
$page = $db->table('members')
    ->where('status', 'active')
    ->orderByDesc('created_at')
    ->paginate(page: 2, perPage: 20);

// ['data', 'total', 'page', 'per_page', 'total_pages']
```

단순 Repository 전체 목록에는 기존 `BaseRepository::paginate()`를 그대로 사용할 수 있습니다.

```php
// BaseRepository::paginate 구현 요지
public function paginate(int $page = 1, int $perPage = 15): array
{
    $page = max(1, $page);
    $offset = ($page - 1) * $perPage;
    $total = $this->countBy();
    $items = $this->all($perPage, $offset);
    return [
        'data' => $items, 'total' => $total, 'page' => $page,
        'per_page' => $perPage, 'total_pages' => (int) ceil($total / $perPage),
    ];
}
```

### 3.13 count(), exists() 및 집계 함수

```php
$db->table('members')->count();                                   // int
$db->table('members')->where('status', 'active')->count();        // 조건부 count
$db->table('members')->where('status', 'active')->exists();       // bool (SELECT EXISTS 사용)
$db->table('members')->where(...)->countForPagination();          // clone 후 count (아래 설명)

$db->table('orders')->sum('total_amount');                        // float
$db->table('orders')->avg('total_amount');                        // float
$db->table('orders')->max('total_amount');                        // mixed
$db->table('orders')->min('total_amount');                        // mixed
```

- `count($column = '*')`은 기본적으로 `COUNT(*)`. 컬럼을 넘기면 `COUNT(column)`.
- 집계 메서드는 실행 중 컬럼/정렬/limit/offset을 임시로 치환했다가 `finally`로 원복하므로, **같은 빌더로 집계 후 다시 목록을 조회해도 상태가 오염되지 않습니다.**
- `countForPagination()`은 원본 빌더를 건드리지 않고 총 개수를 셉니다. `groupBy()`/`distinct()` 쿼리는 서브쿼리로 감싸 최종 결과 행 수를 계산합니다.

---

## 4. INSERT

### insert()

```php
$id = $db->table('members')->insert([
    'domain_id' => 1,
    'user_id'   => 'john',
    'nickname'  => 'John',
    'status'    => 'active',
]);
// 반환: int (lastInsertId)
```

- 반환값은 마지막 삽입 ID(`int`)입니다. **즉, `insert()` 자체가 "insert 후 ID 반환"입니다.** 별도의 `insertGetId()` 메서드는 존재하지 않습니다.
- 빈 배열을 넘기면 `DatabaseException('Insert data cannot be empty')`.
- 배열의 키(컬럼명)는 모두 식별자 검증을 거칩니다.

실제 `BaseRepository::create()`도 이 `insert()`를 감싸고, `created_at`을 자동으로 채웁니다.

```php
public function create(array $data): int|null
{
    // created_at 자동 추가 후
    return $this->db->table($this->table)->insert($data);
}
```

### insertOrUpdate() — UPSERT

`INSERT ... ON DUPLICATE KEY UPDATE`를 생성합니다(MySQL 계열). 유니크 키 충돌 시 UPDATE로 전환됩니다.

```php
// 삽입/업데이트 데이터가 같은 경우
$db->table('settings')->insertOrUpdate([
    'setting_key' => 'site_name',
    'value'       => 'Mublo',
]);

// 충돌 시 갱신할 컬럼만 따로 지정
$db->table('settings')->insertOrUpdate(
    ['setting_key' => 'site_name', 'value' => 'Mublo'],  // INSERT 데이터
    ['value' => 'Mublo']                                  // UPDATE 데이터 (충돌 시)
);
```

- `$updateData`를 생략하면 `$data` 전체를 UPDATE 대상으로 씁니다.
- 반환값은 `Database::insert()`의 결과(lastInsertId)입니다.

---

## 5. UPDATE

### update()

```php
$affected = $db->table('members')
    ->where('member_id', 1)
    ->update([
        'status'     => 'inactive',
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
// 반환: int (영향받은 행 수)
```

**WHERE 없는 UPDATE는 기본 차단됩니다.**

```php
// 예외: UPDATE without WHERE is not allowed.
$db->table('members')->update(['status' => 'inactive']);

// 전체 테이블 갱신이 정말 의도된 경우에만 명시적으로 허용
$db->table('site_config')
    ->allowFullTableOperation()
    ->update(['maintenance' => 1]);
```

- 빈 데이터 배열은 예외.
- 컬럼 키는 식별자 검증을 거칩니다.

### increment() / decrement()

숫자 컬럼을 조회 후 다시 저장하지 않고 DB에서 원자적으로 증감합니다. UPDATE/DELETE와 마찬가지로 WHERE 없는 실행은 기본 차단됩니다.

```php
$db->table('products')
    ->where('goods_id', $goodsId)
    ->decrement('stock_quantity', $quantity);

$db->table('articles')
    ->where('article_id', $articleId)
    ->increment('hit', 1);

// 정합성이 중요한 잔액: 잠금 후 계산 (실제 MemberRepository 패턴)
$sql = "SELECT point_balance FROM members WHERE member_id = ? FOR UPDATE";
$rows = $db->select($sql, [$memberId]);   // 트랜잭션 안에서 호출
```

---

## 6. DELETE

### delete()

```php
$deleted = $db->table('members')
    ->where('status', 'blocked')
    ->delete();
// 반환: int (영향받은 행 수)
```

`update()`와 마찬가지로 **WHERE 없는 DELETE는 기본 차단**됩니다. 전체 삭제가 의도된 경우에만 `allowFullTableOperation()`을 붙입니다.

```php
$db->table('temp_import')->allowFullTableOperation()->delete();
```

> Mublo의 회원 탈퇴는 실제 DELETE가 아니라 상태를 `withdrawn`으로 바꾸는 **소프트 삭제**(`MemberRepository::softDelete()`)로 처리합니다. 물리 삭제 전에 소프트 삭제로 충분한지 항상 검토하세요.

---

## 7. JOIN

### join(), leftJoin(), rightJoin(), crossJoin()

```php
// INNER JOIN — 4-인자형 (테이블, 첫 컬럼, 연산자, 둘째 컬럼)
$db->table('members as m')
    ->select('m.member_id', 'm.nickname', 'o.order_no')
    ->join('orders as o', 'm.member_id', '=', 'o.member_id')
    ->get();

// LEFT JOIN
->leftJoin('orders as o', 'm.member_id', '=', 'o.member_id')

// RIGHT JOIN
->rightJoin('logs as l', 'm.member_id', '=', 'l.member_id')

// CROSS JOIN (ON 조건 없음)
->crossJoin('calendar_days')
```

실제 `MemberRepository::getFieldValues()`의 JOIN 예:

```php
$db->table('member_field_values as v')
    ->select(['v.field_id', 'f.field_name', 'v.field_value', 'f.is_encrypted', 'f.field_type'])
    ->join('member_fields as f', 'v.field_id', '=', 'f.field_id')
    ->where('v.member_id', '=', $memberId)
    ->get();
```

### JoinClause — 복합 ON 조건 (on, orOn)

`join()`의 두 번째 인자로 클로저를 넘기면 `JoinClause`를 받아 `on()` / `orOn()`을 체이닝할 수 있습니다.

```php
->join('orders as o', function ($join) {
    $join->on('m.member_id', '=', 'o.member_id')
         ->orOn('m.backup_id', '=', 'o.member_id');
});
// => INNER JOIN orders AS o ON m.member_id = o.member_id OR m.backup_id = o.member_id
```

`JoinClause`의 제약:

- `on()`/`orOn()`도 연산자를 생략하면 `=`로 간주합니다: `on('a.id', 'b.id')`.
- JOIN ON 연산자는 **`=`, `!=`, `<>`만** 허용합니다(값 비교가 아니라 컬럼 대응이므로 범위 연산자 불가).
- JOIN 타입은 `INNER`, `LEFT`, `RIGHT`, `CROSS`만 허용.
- 양쪽 컬럼 모두 식별자 검증을 거칩니다.

> **주의:** JOIN의 ON 조건은 **컬럼 대 컬럼** 비교입니다. `ON x.col = ?` 처럼 값을 바인딩하는 형태는 지원하지 않습니다. 값 조건은 `where()`로 거세요.

---

## 8. 서브쿼리 / Raw 표현

QueryBuilder는 서브쿼리와 임의 SQL 표현식을 표현하지 못합니다. 이 경계를 넘는 순간 두 가지 도구를 씁니다.

### 8.1 부분 raw — whereRaw() / orderByRaw()

빌더 체인 안에서 조건이나 정렬만 raw로 처리합니다. 값은 반드시 바인딩합니다.

```php
$db->table('members')
    ->whereRaw("member_id IN (SELECT member_id FROM banned WHERE domain_id = ?)", [$domainId])
    ->orderByRaw('FIELD(status, ?, ?, ?)', ['active', 'pending', 'blocked'])
    ->get();
```

> `selectRaw()`는 **존재하지 않습니다.** SELECT 절에 임의 표현식이 필요하면 아래 8.2의 raw 실행을 쓰세요. (`select()`는 컬럼/별칭/집계 함수까지만 허용합니다.)

### 8.2 전체 raw — Database 직접 실행

JOIN·서브쿼리·동적 조건이 얽혀 빌더로 표현하기 어려우면 `Database`의 raw 메서드를 직접 씁니다. 코드베이스의 복잡한 조회(암호화 필드 검색, 계층 검색 등)는 대부분 이 방식입니다.

```php
// 다건 조회
$rows = $db->select(
    'SELECT * FROM members WHERE status = ? ORDER BY created_at DESC',
    ['active']
);

// 단건 조회
$row = $db->selectOne('SELECT * FROM members WHERE member_id = ?', [1]);

// INSERT (lastInsertId 반환)
$id = $db->insert('INSERT INTO members (user_id, nickname) VALUES (?, ?)', ['john', 'John']);

// UPDATE/DELETE (영향 행 수 반환)
$affected = $db->execute('UPDATE members SET status = ? WHERE member_id = ?', ['inactive', 1]);
```

실제 `MemberRepository::searchByDomainWithField()`의 raw JOIN 패턴(발췌):

```php
$sql = "SELECT m.* FROM {$membersTable} m
        INNER JOIN {$valuesTable} v ON m.member_id = v.member_id
        WHERE {$scopeSql} AND v.field_id = ? AND v.field_value LIKE ?{$fsql}
        ORDER BY m.{$sortCol} {$sortDir}
        LIMIT ? OFFSET ?";
$params = array_merge($scopeParams, [$fieldId, '%' . $keyword . '%'], $fparams, [$limit, $offset]);
$rows = $db->select($sql, $params);
```

여기서 핵심은, **테이블/컬럼처럼 바인딩할 수 없는 부분(`$membersTable`, `$sortCol`, `$sortDir`)은 반드시 코드가 통제하는 안전한 값**이어야 한다는 점입니다. 위 코드의 `$sortCol`/`$sortDir`은 `resolveSort()`가 allowlist로 검증한 값이고, `$membersTable`은 상수 문자열에서 나온 값입니다. 사용자 입력을 테이블/컬럼 자리에 직접 넣지 마세요.

> **동적 IN 목록:** 빌더의 `whereIn()`을 쓸 수 없는 raw 상황에서는 placeholder를 개수만큼 생성해 바인딩합니다.
> ```php
> $placeholders = implode(',', array_fill(0, count($ids), '?'));
> $rows = $db->table('members')
>     ->whereRaw("member_id IN ({$placeholders})", array_map('intval', $ids))
>     ->get();
> ```

---

## 9. 트랜잭션

트랜잭션은 QueryBuilder가 아니라 **`Database`**의 책임입니다. QueryBuilder에는 `beginTransaction()` 등이 없습니다.

### 9.1 콜백 트랜잭션 (권장)

`transaction()`은 콜백을 실행하고, 예외가 없으면 커밋, 예외가 나면 자동 롤백합니다. 대부분 이 방식을 씁니다.

```php
$result = $this->levelRepository->getDb()->transaction(function () use ($levelIds, $updateData) {
    $updatedCount = 0;
    foreach ($levelIds as $levelId) {
        $level = $this->levelRepository->findById((int) $levelId);
        if (!$level || $level->isSuper()) {
            continue;   // 슈퍼관리자 등급 보호
        }
        $this->levelRepository->update((int) $levelId, $updateData);
        $updatedCount++;
    }
    return $updatedCount;   // 반환값이 그대로 $result 에 담김
});
```

- 콜백은 `Database` 인스턴스를 인자로 받습니다(`function (Database $db) { ... }`). 위처럼 클로저 `use`로 Repository를 캡처해 써도 됩니다.
- 콜백 안에서 예외가 던져지면 `rollBack()` 후 `DatabaseException('Transaction failed: ...')`로 감싸 다시 던집니다.

### 9.2 수동 트랜잭션

세밀한 제어가 필요하면 직접 경계를 잡습니다.

```php
$db->beginTransaction();
try {
    $db->execute('UPDATE members SET point_balance = point_balance - ? WHERE member_id = ?', [100, $memberId]);
    $db->insert('INSERT INTO balance_logs (member_id, amount) VALUES (?, ?)', [$memberId, -100]);
    $db->commit();
} catch (\Throwable $e) {
    $db->rollBack();
    throw $e;
}
```

`beginTransaction()`, `commit()`, `rollBack()`은 각각 PDO 동작을 그대로 위임합니다. `inTransaction()`으로 진행 여부를 확인할 수 있습니다.

---

## 10. 보안

QueryBuilder의 안전성은 **"값은 바인딩, 식별자·연산자는 검증"** 두 축으로 보장됩니다.

### 10.1 파라미터 바인딩 (필수)

`where()`, `whereIn()`, `whereBetween()`, `having()`, `insert()`, `update()`, `increment()` 등 값을 받는 모든 메서드는 값을 `?` placeholder로 바인딩합니다. 값이 SQL 문자열에 직접 삽입되는 경로는 없습니다. 바인딩은 메서드 호출 순서와 무관하게 실제 SQL 절 순서인 WHERE → HAVING → ORDER BY로 정렬됩니다.

```php
->where('user_id', '=', $userInput)   // WHERE user_id = ?  (바인딩: [$userInput])
```

`whereRaw()` / `orderByRaw()`처럼 raw 문자열을 받는 메서드도 값은 `?`로 넘기게 설계되어 있고, placeholder 개수와 바인딩 개수가 일치하지 않으면 예외를 던집니다(`assertPlaceholderCount`). 문자열 리터럴 안의 `?`는 세지 않습니다(`countPlaceholders`).

### 10.2 assertIdentifier() — 컬럼/테이블 식별자 검증

컬럼명은 정규식 `^[a-zA-Z_][a-zA-Z0-9_.]*$`를 통과해야 합니다. 공백·괄호·따옴표·연산자가 섞인 문자열은 컬럼명이 될 수 없습니다.

```php
->where('status', 'active')            // OK
->where('status = 1 OR 1=1', 'x')      // DatabaseException: Invalid column identifier
```

테이블명은 `assertTableIdentifier()`가 `table`, `schema.table`, `table AS alias`, `table alias` 형태까지만 허용합니다. `select()` 표현식은 `assertSelectExpression()`이 컬럼/별칭/집계 함수만 통과시킵니다.

### 10.3 assertOperator() — 연산자 검증

`where()`의 연산자는 화이트리스트(`=`, `!=`, `<>`, `>`, `>=`, `<`, `<=`, `LIKE`, `NOT LIKE`, `IN`, `NOT IN`, `IS`, `IS NOT`, `BETWEEN`, `NOT BETWEEN`)에 있어야 합니다. 특수 연산자는 전용 조건으로 변환되고, `having()`은 단일 값 비교 연산자만 허용합니다. `whereColumn()`은 컬럼 비교 연산자만, `JoinClause`의 ON은 `=`, `!=`, `<>`만 허용합니다.

```php
->where('age', 'INVALID_OP', 18)       // DatabaseException: Invalid operator
```

### 10.4 SQL Injection 방어 — 요약

| 위치 | 방어 방식 |
|------|-----------|
| WHERE/HAVING 값, INSERT/UPDATE 값 | `?` 바인딩 |
| 컬럼명 | `assertIdentifier()` 정규식 |
| 테이블명 | `assertTableIdentifier()` 정규식 |
| SELECT 표현식 | `assertSelectExpression()` 화이트리스트 패턴 |
| 연산자 | `assertOperator()` 등 화이트리스트 |
| GROUP BY / ORDER BY 컬럼 | 식별자 정규식 |
| LIKE 검색어(`whereLike`) | wildcard escape + 바인딩 |
| raw SQL placeholder | 개수 검증(`assertPlaceholderCount`) |

**한 줄 결론:** 사용자 입력은 **오직 값(`?` 바인딩)으로만** 흘려보내세요. 컬럼명·테이블명·정렬 방향처럼 바인딩할 수 없는 자리에 사용자 입력을 넣어야 한다면, 반드시 코드가 통제하는 allowlist로 검증한 뒤 넣습니다(`MemberRepository::resolveSort()`가 대표 예).

---

## 11. Repository에서의 사용 패턴

실제 코드에서 반복적으로 나타나는 패턴입니다.

### 11.1 조건 조립을 헬퍼로 분리

`MemberRepository`는 목록/검색/카운트가 같은 필터를 공유하므로, 조건 적용을 private 헬퍼로 빼서 재사용합니다.

```php
private function applyDomainScope($query, int $domainId, bool $includeOrigin): void
{
    if ($includeOrigin) {
        $query->whereRaw('(domain_id = ? OR origin_domain_id = ?)', [$domainId, $domainId]);
    } else {
        $query->where('domain_id', '=', $domainId);
    }
}

private function applyListFilters(object $query, array $filters): void
{
    if (isset($filters['level_value']) && $filters['level_value'] !== '' && $filters['level_value'] !== null) {
        $query->where('level_value', '=', (int) $filters['level_value']);
    }
    if (!empty($filters['status'])) {
        $query->where('status', '=', $filters['status']);
    }
}
```

빌더는 참조로 전달되어 헬퍼 안에서 조건이 누적됩니다. 목록 조회는 이 헬퍼들을 조합합니다.

```php
public function findByDomain(int $domainId, int $limit = 100, int $offset = 0, ...): array
{
    [$sortCol, $sortDir] = $this->resolveSort($sort, $order);

    $query = $this->getDb()->table($this->table);
    $this->applyDomainScope($query, $domainId, $includeOrigin);
    $this->applyListFilters($query, $filters);

    $rows = $query->orderBy($sortCol, $sortDir)->limit($limit)->offset($offset)->get();
    return array_map(fn($row) => $this->toEntity($row), $rows);
}
```

### 11.2 정렬 컬럼 allowlist 검증

사용자가 정렬 컬럼/방향을 고르는 화면에서는, 그 값을 그대로 `orderBy()`에 넣기 전에 allowlist로 좁힙니다.

```php
public const SORTABLE_FIELDS = ['member_id', 'user_id', 'nickname', 'level_value', 'status', 'point_balance', 'created_at', 'last_login_at'];

public function resolveSort(?string $sort, ?string $order): array
{
    $col = in_array($sort, self::SORTABLE_FIELDS, true) ? $sort : 'member_id';
    $dir = strtoupper((string) $order) === 'ASC' ? 'ASC' : 'DESC';
    return [$col, $dir];
}
```

### 11.3 존재/개수 확인

```php
public function existsByUserIdExcept(int $domainId, string $userId, ?int $excludeMemberId = null): bool
{
    $query = $this->getDb()->table($this->table)
        ->where('domain_id', '=', $domainId)
        ->where('user_id', '=', $userId);

    if ($excludeMemberId !== null) {
        $query->where('member_id', '!=', $excludeMemberId);
    }
    return $query->exists();
}
```

조건을 변수에 담아두고 `if`로 선택적으로 덧붙이는 패턴에 주목하세요. 체인을 끊고 `$query`에 계속 조건을 추가해도 동작은 같습니다.

### 11.4 단일 값만 뽑기

```php
public function getBalance(int $memberId, ?int $domainId = null): ?int
{
    $qb = $this->getDb()->table($this->table)
        ->select(['point_balance'])
        ->where('member_id', '=', $memberId);

    if ($domainId !== null) {
        $qb->where('domain_id', '=', $domainId);
    }

    $row = $qb->first();
    return $row !== null ? (int) $row['point_balance'] : null;
}
```

### 11.5 조회 결과를 Entity로 변환

Repository는 배열을 그대로 반환하지 않고 Entity로 매핑합니다.

```php
$rows = $query->get();
return array_map(fn($row) => $this->toEntity($row), $rows);   // 다건
// 또는
$row = $query->first();
return $row ? $this->toEntity($row) : null;                   // 단건
```

`BaseRepository::toEntity()`는 `entityClass::fromArray()`를 호출합니다. 자식에서 오버라이드해 조인 데이터를 덧붙이기도 합니다(`MemberRepository::toEntity()`가 등급 정보를 병합하는 예).

---

## 12. 주의사항 / 안티패턴

### QueryBuilder는 mutable 객체다

빌더는 조건을 내부 상태에 누적합니다. 같은 빌더로 총 개수와 목록을 나눠 조회하려면 `clone`을 쓰세요.

```php
// ✅ 권장 — clone으로 원본 보존
$base  = $db->table('members')->where('domain_id', $domainId)->where('status', 'active');
$total = (clone $base)->count();
$items = $base->orderBy('created_at', 'DESC')->limit(20)->get();
```

`count()`/`sum()` 등 집계 메서드는 내부적으로 상태를 임시 치환 후 원복하지만, `limit`/`where`를 직접 덧붙이는 것은 원본을 바꿉니다. 헷갈리면 `clone` 또는 `countForPagination()`을 쓰세요.

### NULL은 자동 처리되며 whereNull()로 의도를 드러낼 수 있다

```php
->where('deleted_at', null)
// => deleted_at IS NULL

// 더 명시적인 표현
->whereNull('deleted_at')
```

### 사용자 입력을 컬럼/테이블/정렬 자리에 직접 넣지 말 것

```php
// ❌ 정렬 컬럼을 그대로 신뢰 — 검증 없이 넣으면 위험
->orderBy($_GET['sort'], $_GET['order'])

// ✅ allowlist로 좁힌 값만
[$col, $dir] = $this->resolveSort($_GET['sort'] ?? null, $_GET['order'] ?? null);
->orderBy($col, $dir)
```

바인딩(`?`)은 값 자리에만 통합니다. 컬럼명·테이블명·`ASC/DESC`는 바인딩할 수 없으므로 코드가 통제해야 합니다.

### whereRaw에 사용자 입력을 이어붙이지 말 것

```php
// ❌ 절대 금지 — SQL Injection
->whereRaw("user_id = '{$userInput}'")

// ✅ 값은 바인딩
->whereRaw('user_id = ?', [$userInput])
```

### 빌더로 표현이 안 되면 억지로 우회하지 말 것

서브쿼리·복잡한 표현식을 `whereRaw`로 힘겹게 조립하기보다, 처음부터 `$db->select()` raw SQL로 명확하게 쓰는 편이 안전하고 읽기 쉬울 때가 많습니다. 코드베이스의 복잡한 조회들이 그렇게 되어 있습니다.

### WHERE 없는 UPDATE/DELETE/증감은 실수 방지 장치가 막는다

`allowFullTableOperation()`은 "전체 테이블을 정말로 건드리겠다"는 의사 표시입니다. 조건을 빠뜨려 예외가 났다면, allowFullTableOperation을 붙이기 전에 **WHERE를 빠뜨린 건 아닌지** 먼저 확인하세요.

### 존재한다고 착각하기 쉬운 메서드 (실제로는 없음)

| 착각하는 이름 | 실제 대체 |
|---------------|-----------|
| `selectOne()` / `selectAll()` (빌더) | `first()` / `get()` |
| `insertGetId()` | `insert()` (이미 ID 반환) |
| `selectRaw()` | raw `$db->select(...)` |
| `beginTransaction()` (빌더) | `Database::transaction()` |

---

## 부록: public 메서드 요약

**SELECT 조립:** `select`, `distinct`, `where`, `orWhere`, `whereColumn`, `orWhereColumn`, `whereLike`, `orWhereLike`, `whereIn`, `orWhereIn`, `whereNotIn`, `orWhereNotIn`, `whereNull`, `orWhereNull`, `whereNotNull`, `orWhereNotNull`, `whereBetween`, `orWhereBetween`, `whereNotBetween`, `orWhereNotBetween`, `whereRaw`, `orWhereRaw`, `join`, `leftJoin`, `rightJoin`, `crossJoin`, `groupBy`, `having`, `orHaving`, `orderBy`, `orderByDesc`, `orderByRaw`, `limit`, `offset`, `take`, `forPage`, `paginate`, `allowFullTableOperation`

**변경:** `insert`, `insertOrUpdate`, `update`, `increment`, `decrement`, `delete`

**실행:** `get`, `first`, `paginate`, `count`, `countForPagination`, `exists`, `sum`, `avg`, `max`, `min`, `insert`, `insertOrUpdate`, `update`, `increment`, `decrement`, `delete`

**보조:** `toSql`, `getBindings`, `debug`, `reset`

**JoinClause:** `on`, `orOn`, `getType`, `getTable`, `getConditions`, `toSql`

**Database (raw/트랜잭션):** `table`, `select`, `selectOne`, `insert`, `execute`, `prepare`, `beginTransaction`, `commit`, `rollBack`, `transaction`, `inTransaction`, `lastInsertId`, `enableQueryLog`, `getQueryLog`, `setSlowQueryThreshold`

---

[< 데이터베이스](database.md) | [문서 홈으로](README.md)
