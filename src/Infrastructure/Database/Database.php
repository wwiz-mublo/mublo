<?php
namespace Mublo\Infrastructure\Database;

use PDO;
use PDOStatement;
use Mublo\Infrastructure\Log\Logger;

/**
 * Database Class
 *
 * PDO 래퍼 클래스
 * - 안전한 쿼리 실행
 * - 트랜잭션 지원
 * - 에러 처리
 * - 슬로우 쿼리 로깅
 */
class Database
{
    protected PDO $pdo;
    protected ?Logger $logger = null;

    /**
     * 슬로우 쿼리 임계값 (초)
     * 기본: 1.0초
     */
    protected float $slowQueryThreshold = 1.0;

    /**
     * 쿼리 로깅 활성화 여부
     */
    protected bool $enableQueryLog = false;

    /**
     * 쿼리 로그 (디버깅용)
     */
    protected array $queryLog = [];

    /**
     * Constructor
     *
     * @param PDO $pdo PDO 인스턴스
     */
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Logger 설정
     */
    public function setLogger(Logger $logger): self
    {
        $this->logger = $logger;
        return $this;
    }

    /**
     * 슬로우 쿼리 임계값 설정 (초)
     */
    public function setSlowQueryThreshold(float $seconds): self
    {
        $this->slowQueryThreshold = $seconds;
        return $this;
    }

    /**
     * 쿼리 로깅 활성화/비활성화
     */
    public function enableQueryLog(bool $enable = true): self
    {
        $this->enableQueryLog = $enable;
        return $this;
    }

    /**
     * 쿼리 로그 반환 (디버깅용)
     */
    public function getQueryLog(): array
    {
        return $this->queryLog;
    }

    /**
     * 쿼리 로그 초기화
     */
    public function clearQueryLog(): void
    {
        $this->queryLog = [];
    }

    /**
     * PDO 인스턴스 반환
     */
    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * 테이블 프리픽스 반환
     *
     * @deprecated 프리픽스 시스템 제거됨. 항상 빈 문자열 반환.
     */
    public function getTablePrefix(): string
    {
        return '';
    }

    /**
     * QueryBuilder 생성
     *
     * @param string $table 테이블 이름
     * @return QueryBuilder
     */
    public function table(string $table): QueryBuilder
    {
        return new QueryBuilder($this, $table);
    }

    /**
     * 테이블 이름 반환 (프리픽스 없음)
     *
     * @deprecated 프리픽스 시스템 제거됨. 입력값을 그대로 반환.
     * @param string $table 테이블 이름
     * @return string 테이블 이름
     */
    public function prefixTable(string $table): string
    {
        return $table;
    }

    /**
     * 현재 데이터베이스에 테이블이 존재하는지 확인한다.
     *
     * 메타데이터 조회 실패는 호출자에게 전파한다. 연결·권한·서버 장애를 테이블 부재로
     * 오인하면 마이그레이션과 데이터 초기화가 성공한 것처럼 끝날 수 있기 때문이다.
     */
    public function tableExists(string $table): bool
    {
        $row = $this->selectOne(
            'SELECT 1
               FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = ?
              LIMIT 1',
            [$table]
        );

        return $row !== null;
    }

    /**
     * SELECT 쿼리 실행 (여러 행)
     *
     * @param string $query SQL 쿼리
     * @param array $params 바인딩 파라미터
     * @return array
     * @throws DatabaseException
     */
    public function select(string $query, array $params = []): array
    {
        $start = microtime(true);

        try {
            $stmt = $this->prepare($query);
            $stmt->execute($params);
            $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $this->logQuery($query, $params, microtime(true) - $start);

            return $result;
        } catch (\PDOException $e) {
            $this->logQuery($query, $params, microtime(true) - $start, $e->getMessage());
            throw DatabaseException::queryFailed($query, $e);
        }
    }

    /**
     * SELECT 쿼리 실행 (단일 행)
     *
     * @param string $query SQL 쿼리
     * @param array $params 바인딩 파라미터
     * @return array|null
     * @throws DatabaseException
     */
    public function selectOne(string $query, array $params = []): ?array
    {
        $start = microtime(true);

        try {
            $stmt = $this->prepare($query);
            $stmt->execute($params);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            $this->logQuery($query, $params, microtime(true) - $start);

            return $result ?: null;
        } catch (\PDOException $e) {
            $this->logQuery($query, $params, microtime(true) - $start, $e->getMessage());
            throw DatabaseException::queryFailed($query, $e);
        }
    }

    /**
     * INSERT 쿼리 실행
     *
     * @param string $query SQL 쿼리
     * @param array $params 바인딩 파라미터
     * @return int 마지막 삽입 ID
     * @throws DatabaseException
     */
    public function insert(string $query, array $params = []): int
    {
        $start = microtime(true);

        try {
            $stmt = $this->prepare($query);
            $stmt->execute($params);
            $lastId = (int) $this->pdo->lastInsertId();

            $this->logQuery($query, $params, microtime(true) - $start);

            return $lastId;
        } catch (\PDOException $e) {
            $this->logQuery($query, $params, microtime(true) - $start, $e->getMessage());
            throw DatabaseException::queryFailed($query, $e);
        }
    }

    /**
     * UPDATE/DELETE 쿼리 실행
     *
     * @param string $query SQL 쿼리
     * @param array $params 바인딩 파라미터
     * @return int 영향받은 행 수
     * @throws DatabaseException
     */
    public function execute(string $query, array $params = []): int
    {
        $start = microtime(true);

        try {
            $stmt = $this->prepare($query);
            $stmt->execute($params);
            $rowCount = $stmt->rowCount();

            $this->logQuery($query, $params, microtime(true) - $start);

            return $rowCount;
        } catch (\PDOException $e) {
            $this->logQuery($query, $params, microtime(true) - $start, $e->getMessage());
            throw DatabaseException::queryFailed($query, $e);
        }
    }

    /**
     * 쿼리 로깅
     *
     * @param string $query SQL 쿼리
     * @param array $params 바인딩 파라미터
     * @param float $duration 실행 시간 (초)
     * @param string|null $error 에러 메시지 (실패 시)
     */
    protected function logQuery(string $query, array $params, float $duration, ?string $error = null): void
    {
        $durationMs = round($duration * 1000, 2);

        // 쿼리 로그 저장 (enableQueryLog이 true일 때)
        if ($this->enableQueryLog) {
            $this->queryLog[] = [
                'query' => $query,
                'params' => $this->sanitizeParams($params),
                'duration_ms' => $durationMs,
                'error' => $error,
                'time' => date('Y-m-d H:i:s'),
            ];
        }

        // Logger가 없으면 종료
        if (!$this->logger) {
            return;
        }

        $queryLogger = $this->logger->channel('query');

        // 에러 발생 시 에러 로그
        if ($error !== null) {
            $queryLogger->error('Query failed', [
                'sql' => $this->truncateQuery($query),
                'params' => $this->sanitizeParams($params),
                'duration_ms' => $durationMs,
                'error' => $error,
            ]);
            return;
        }

        // 슬로우 쿼리 체크
        if ($duration >= $this->slowQueryThreshold) {
            $queryLogger->warning('Slow query detected', [
                'sql' => $this->truncateQuery($query),
                'params' => $this->sanitizeParams($params),
                'duration_ms' => $durationMs,
                'threshold_ms' => $this->slowQueryThreshold * 1000,
            ]);
        }
    }

    /**
     * 쿼리 문자열 자르기 (로그용)
     */
    protected function truncateQuery(string $query, int $maxLength = 2000): string
    {
        $query = preg_replace('/\s+/', ' ', trim($query));

        if (strlen($query) > $maxLength) {
            return substr($query, 0, $maxLength) . '... [TRUNCATED]';
        }

        return $query;
    }

    /**
     * 파라미터 정리 (민감 정보 마스킹)
     */
    protected function sanitizeParams(array $params): array
    {
        $sensitiveKeys = ['password', 'passwd', 'pwd', 'secret', 'token', 'api_key'];
        $sanitized = [];
        $positional = array_is_list($params);

        foreach ($params as $key => $value) {
            // QueryBuilder는 위치 바인딩을 사용한다. 컬럼명을 알 수 없으므로 문자열은
            // 비밀번호·토큰일 가능성을 배제할 수 없어 값 자체를 로그에 남기지 않는다.
            if ($positional && is_string($value)) {
                $sanitized[$key] = '***MASKED***';
                continue;
            }

            // 키 이름에 민감 정보가 포함되어 있으면 마스킹
            $keyLower = strtolower((string) $key);
            $isSensitive = false;

            foreach ($sensitiveKeys as $sensitiveKey) {
                if (str_contains($keyLower, $sensitiveKey)) {
                    $isSensitive = true;
                    break;
                }
            }

            if ($isSensitive) {
                $sanitized[$key] = '***MASKED***';
            } elseif (is_string($value) && strlen($value) > 100) {
                $sanitized[$key] = substr($value, 0, 100) . '... [TRUNCATED]';
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Prepared Statement 생성
     *
     * @param string $query SQL 쿼리
     * @return PDOStatement
     */
    public function prepare(string $query): PDOStatement
    {
        return $this->pdo->prepare($query);
    }

    /**
     * 트랜잭션 시작
     *
     * @return bool
     */
    public function beginTransaction(): bool
    {
        return $this->pdo->beginTransaction();
    }

    /**
     * 트랜잭션 커밋
     *
     * @return bool
     */
    public function commit(): bool
    {
        return $this->pdo->commit();
    }

    /**
     * 트랜잭션 롤백
     *
     * @return bool
     */
    public function rollBack(): bool
    {
        return $this->pdo->rollBack();
    }

    /**
     * 트랜잭션 내에서 콜백 실행
     *
     * @param callable $callback 실행할 함수
     * @return mixed 콜백 결과
     * @throws DatabaseException
     */
    public function transaction(callable $callback): mixed
    {
        $this->beginTransaction();

        try {
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->rollBack();
            throw new DatabaseException('Transaction failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * 마지막 삽입 ID
     *
     * @return string
     */
    public function lastInsertId(): string
    {
        return $this->pdo->lastInsertId();
    }

    /**
     * 트랜잭션 진행 중 여부
     *
     * @return bool
     */
    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }
}
