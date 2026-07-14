<?php
namespace Mublo\Repository\Balance;

use Mublo\Infrastructure\Database\Database;
use Mublo\Infrastructure\Database\DatabaseManager;
use Mublo\Entity\Balance\BalanceLog;

/**
 * Class BalanceLogRepository
 *
 * 포인트 변경 원장(balance_logs) 관리
 *
 * 책임:
 * - 로그 생성 (INSERT ONLY)
 * - 로그 조회 (단일, 목록, 합계)
 * - 멱등성 키로 조회
 *
 * 금지:
 * - UPDATE/DELETE (불변 원장)
 * - 비즈니스 로직 (Service 담당)
 *
 * Note: 이 테이블은 INSERT ONLY - 감사 추적용 불변 원장
 */
class BalanceLogRepository
{
    protected string $table = 'balance_logs';
    protected Database $db;

    public function __construct(?Database $db = null)
    {
        // Database 에는 getInstance() 가 없다. 연결은 DatabaseManager 가 소유한다.
        $this->db = $db ?? DatabaseManager::getInstance()->connect();
    }

    // ========================================
    // CREATE (INSERT ONLY)
    // ========================================

    /**
     * 로그 생성
     *
     * @param array $data 로그 데이터
     * @return int 생성된 로그 ID
     */
    public function create(array $data): int
    {
        // created_at 자동 설정
        if (!isset($data['created_at'])) {
            $data['created_at'] = date('Y-m-d H:i:s');
        }

        $this->db->table($this->table)->insert($data);

        return (int) $this->db->lastInsertId();
    }

    // ========================================
    // READ (단일 조회)
    // ========================================

    /**
     * 멱등성 키로 조회
     *
     * @param string $idempotencyKey 멱등성 키
     * @param int|null $domainId 도메인 ID (멀티테넌트 격리)
     */
    public function findByIdempotencyKey(string $idempotencyKey, ?int $domainId = null): ?BalanceLog
    {
        $qb = $this->db->table($this->table)
            ->where('idempotency_key', $idempotencyKey);

        if ($domainId !== null) {
            $qb->where('domain_id', $domainId);
        }

        $row = $qb->first();

        return $row ? BalanceLog::fromArray($row) : null;
    }

    // ========================================
    // READ (목록 조회)
    // ========================================

    /**
     * 전체 로그 페이지네이션 목록 조회 (관리자용)
     *
     * @param int $domainId 도메인 ID
     * @param int $page 페이지 번호 (1-based)
     * @param int $perPage 페이지당 개수
     * @param array $filters 필터 조건 [member_id, source_type, start_date, end_date]
     * @return array ['items' => BalanceLog[], 'pagination' => array]
     */
    public function getPaginatedList(int $domainId, int $page = 1, int $perPage = 20, array $filters = []): array
    {
        $offset = ($page - 1) * $perPage;

        $qb = $this->db->table($this->table)
            ->where('domain_id', $domainId);

        // 필터 적용
        if (!empty($filters['member_id'])) {
            $qb->where('member_id', (int) $filters['member_id']);
        }
        if (!empty($filters['source_type'])) {
            $qb->where('source_type', $filters['source_type']);
        }
        if (!empty($filters['start_date'])) {
            $qb->where('created_at', '>=', $filters['start_date'] . ' 00:00:00');
        }
        if (!empty($filters['end_date'])) {
            $qb->where('created_at', '<=', $filters['end_date'] . ' 23:59:59');
        }

        // 전체 개수 조회 (Clone 필요)
        $countQb = clone $qb;
        $total = $countQb->count();

        // 목록 조회
        $rows = $qb->orderBy('created_at', 'DESC')
            ->orderBy('log_id', 'DESC')
            ->limit($perPage)
            ->offset($offset)
            ->get();

        $items = array_map(fn($row) => BalanceLog::fromArray($row), $rows);

        return [
            'items' => $items,
            'pagination' => [
                'currentPage' => $page,
                'perPage' => $perPage,
                'totalItems' => $total,
                'totalPages' => (int) ceil($total / $perPage),
            ],
        ];
    }

    /**
     * 회원별 로그 목록 조회
     *
     * @param int $memberId 회원 ID
     * @param int $page 페이지 번호 (1-based)
     * @param int $perPage 페이지당 개수
     * @param array $filters 동등 조건 필터 (허용 키: domain_id, action, source_type, source_name, reference_type, reference_id)
     * @return BalanceLog[]
     */
    public function getByMember(int $memberId, int $page = 1, int $perPage = 20, array $filters = [], ?int $domainId = null): array
    {
        $offset = ($page - 1) * $perPage;

        $qb = $this->db->table($this->table)
            ->where('member_id', $memberId);

        // 도메인 스코프는 호출자가 명시한 domainId 로만 적용한다(인스턴스 상태 미사용).
        if ($domainId !== null) {
            $qb->where('domain_id', $domainId);
        }

        $this->applyMemberFilters($qb, $filters);

        $rows = $qb->orderBy('created_at', 'DESC')
            ->orderBy('log_id', 'DESC')
            ->limit($perPage)
            ->offset($offset)
            ->get();

        return array_map(fn($row) => BalanceLog::fromArray($row), $rows);
    }

    /**
     * 회원별 로그 수 조회
     *
     * @param array $filters getByMember 와 동일한 필터
     */
    public function countByMember(int $memberId, array $filters = [], ?int $domainId = null): int
    {
        $qb = $this->db->table($this->table)
            ->where('member_id', $memberId);

        // 도메인 스코프는 호출자가 명시한 domainId 로만 적용한다(getByMember 와 대칭).
        if ($domainId !== null) {
            $qb->where('domain_id', $domainId);
        }

        $this->applyMemberFilters($qb, $filters);

        return $qb->count();
    }

    /**
     * 참조(주문 등) 기준 지급 합계 — amount > 0 만 합산
     *
     * 포인트 "전액 환수"에서 해당 주문으로 실제 지급된 총액을 구할 때 쓴다.
     * 로그를 로드하지 않고 SQL SUM 으로 집계한다.
     */
    public function sumGrantedByReference(
        int $domainId,
        int $memberId,
        string $action,
        string $referenceType,
        string $referenceId
    ): int {
        $qb = $this->db->table($this->table)
            ->where('domain_id', $domainId)
            ->where('member_id', $memberId)
            ->where('action', $action)
            ->where('reference_type', $referenceType)
            ->where('reference_id', $referenceId)
            ->where('amount', '>', 0);

        return (int) $qb->sum('amount');
    }

    /**
     * 회원 스코프 조회의 필터 적용 — 허용 키만 동등 조건으로 반영
     *
     * domain_id 는 filters 에 명시될 때만 적용한다(인스턴스 상태 미사용).
     */
    private function applyMemberFilters($qb, array $filters): void
    {
        if (isset($filters['domain_id'])) {
            $qb->where('domain_id', (int) $filters['domain_id']);
        }

        foreach (['action', 'source_type', 'source_name', 'reference_type', 'reference_id'] as $key) {
            if (isset($filters[$key]) && $filters[$key] !== '') {
                $qb->where($key, $filters[$key]);
            }
        }
    }

    // ========================================
    // READ (집계)
    // ========================================

    /**
     * 회원 원장 합계 조회 (무결성 검증용)
     *
     * Source of Truth = SUM(balance_logs.amount)
     *
     * @param int $memberId 회원 ID
     * @param int|null $domainId 명시적 도메인 ID (미지정 시 도메인 스코프 미적용)
     */
    public function getSumByMember(int $memberId, ?int $domainId = null): int
    {
        $qb = $this->db->table($this->table)
            ->where('member_id', $memberId);

        if ($domainId !== null) {
            $qb->where('domain_id', $domainId);
        }

        return (int) $qb->sum('amount');
    }
}
