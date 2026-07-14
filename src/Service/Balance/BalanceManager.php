<?php
namespace Mublo\Service\Balance;

use Mublo\Repository\Balance\BalanceLogRepository;
use Mublo\Repository\Balance\BalanceRepairAuditRepository;
use Mublo\Repository\Member\MemberRepository;
use Mublo\Entity\Balance\BalanceLog;
use Mublo\Infrastructure\Database\Database;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Core\Event\EventInterface;
use Mublo\Core\Event\Balance\BalanceAdjustingEvent;
use Mublo\Core\Event\Balance\BalanceAdjustedEvent;
use Mublo\Core\Result\Result;
use Mublo\Contract\Balance\BalanceGatewayInterface;

/**
 * Class BalanceManager
 *
 * 포인트/잔액 중앙 관리 서비스
 *
 * 책임:
 * - 잔액 조정 (adjust) - 단일 진입점
 * - 잔액 조회 (getBalance)
 * - 이력 조회 (getHistory)
 * - 무결성 검증 (verifyIntegrity)
 * - 무결성 복구 (repair) - 관리자 전용
 *
 * 핵심 원칙:
 * - 원장 불변성: balance_logs는 INSERT ONLY
 * - 원장 = 진실: Source of Truth는 balance_logs, point_balance는 스냅샷
 * - 동시성 제어: SELECT ... FOR UPDATE (Pessimistic Lock)
 * - 트랜잭션 원자성: 원장 기록 + 스냅샷 업데이트 = 하나의 트랜잭션
 * - 음수 거부: 기본 정책 - 잔액 부족 시 차감 실패
 *
 * 확장(Package/Plugin)은 이 클래스를 직접 import 하지 말고
 * BalanceGatewayInterface(공개 계약)로 소비한다 — 이 클래스는 내부 API 다.
 */
class BalanceManager implements BalanceGatewayInterface
{
    private BalanceLogRepository $logRepository;
    private BalanceRepairAuditRepository $repairAuditRepository;
    private MemberRepository $memberRepository;
    private Database $db;
    private ?EventDispatcher $eventDispatcher;

    /**
     * 필수 필드 목록
     */
    private const REQUIRED_FIELDS = [
        'domain_id',
        'member_id',
        'amount',
        'source_type',
        'source_name',
        'action',
        'message',
    ];

    public function __construct(
        BalanceLogRepository $logRepository,
        BalanceRepairAuditRepository $repairAuditRepository,
        MemberRepository $memberRepository,
        Database $db,
        ?EventDispatcher $eventDispatcher = null
    ) {
        $this->logRepository = $logRepository;
        $this->repairAuditRepository = $repairAuditRepository;
        $this->memberRepository = $memberRepository;
        $this->db = $db;
        $this->eventDispatcher = $eventDispatcher;
    }

    /**
     * 이벤트 발행 헬퍼
     */
    private function dispatch(EventInterface $event): EventInterface
    {
        return $this->eventDispatcher?->dispatch($event) ?? $event;
    }

    // ========================================
    // 핵심 메서드: adjust()
    // ========================================

    /**
     * 잔액 조정 (지급/차감)
     *
     * @param array $params [
     *   'member_id' => int,         // 필수
     *   'amount' => int,            // 필수 (+지급, -차감)
     *   'source_type' => string,    // 필수 ('plugin', 'package', 'admin', 'system')
     *   'source_name' => string,    // 필수 ('MemberPoint', 'Shop' 등)
     *   'action' => string,         // 필수 ('article_write', 'purchase' 등)
     *   'message' => string,        // 필수 (사용자 친화적 메시지)
     *   'reference_type' => ?string,
     *   'reference_id' => ?string,
     *   'admin_id' => ?int,
     *   'memo' => ?string,
     *   'ip_address' => ?string,
     *   'idempotency_key' => ?string, // 중복 요청 방지
     * ]
     * @return Result success data: ['log_id', 'balance_before', 'balance_after', 'idempotent']
     */
    public function adjust(array $params): Result
    {
        // 1. 필수 필드 검증
        $validation = $this->validateParams($params);
        if (!$validation['valid']) {
            return Result::failure($validation['message']);
        }

        // 2. 멱등성 키 체크 (도메인 스코프 적용)
        $idempotencyKey = $params['idempotency_key'] ?? null;
        $domainId = (int) $params['domain_id'];
        if ($idempotencyKey) {
            $existing = $this->logRepository->findByIdempotencyKey($idempotencyKey, $domainId);
            if ($existing) {
                return Result::success('이미 처리된 요청입니다.', [
                    'log_id' => $existing->getLogId(),
                    'balance_before' => $existing->getBalanceBefore(),
                    'balance_after' => $existing->getBalanceAfter(),
                    'idempotent' => true,
                ]);
            }
        }

        $memberId = (int) $params['member_id'];
        $amount = (int) $params['amount'];

        $this->db->beginTransaction();

        try {
            // 3. SELECT ... FOR UPDATE (행 락킹 + 도메인 소유 검증)
            $currentBalance = $this->memberRepository->getBalanceForUpdate($memberId, $domainId);

            if ($currentBalance === null) {
                $this->db->rollBack();
                return Result::failure('회원을 찾을 수 없습니다.');
            }

            // 4. 잔액 검증 (차감 시)
            $newBalance = $currentBalance + $amount;
            if ($amount < 0 && $newBalance < 0) {
                $this->db->rollBack();
                return Result::failure('잔액이 부족합니다.');
            }

            // 5. BalanceAdjustingEvent 발행 (차단 가능)
            $adjustingEvent = new BalanceAdjustingEvent($memberId, $amount, $currentBalance);
            $this->dispatch($adjustingEvent);

            if ($adjustingEvent->isBlocked()) {
                $this->db->rollBack();
                return Result::failure($adjustingEvent->getBlockReason() ?? '잔액 조정이 차단되었습니다.');
            }

            // 6. 원장 기록 (INSERT)
            $logData = [
                'domain_id' => (int) $params['domain_id'],
                'member_id' => $memberId,
                'amount' => $amount,
                'balance_before' => $currentBalance,
                'balance_after' => $newBalance,
                'source_type' => $params['source_type'],
                'source_name' => $params['source_name'],
                'action' => $params['action'],
                'message' => $params['message'],
                'reference_type' => $params['reference_type'] ?? null,
                'reference_id' => $params['reference_id'] ?? null,
                'ip_address' => $params['ip_address'] ?? null,
                'admin_id' => $params['admin_id'] ?? null,
                'memo' => $params['memo'] ?? null,
                'idempotency_key' => $idempotencyKey,
            ];

            $logId = $this->logRepository->create($logData);

            // 7. 스냅샷 업데이트 (UPDATE) — 실패 시 롤백
            $updated = $this->memberRepository->updateBalance($memberId, $newBalance);
            if (!$updated) {
                throw new \RuntimeException("잔액 스냅샷 업데이트 실패 (member_id={$memberId})");
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();

            // 동시성: 멱등키 UNIQUE 제약(uk_domain_idempotency) 위반이면
            // 먼저 커밋된 요청의 결과를 멱등 응답으로 반환한다.
            if ($idempotencyKey && $this->isDuplicateKeyError($e)) {
                $existing = $this->logRepository->findByIdempotencyKey($idempotencyKey, $domainId);
                if ($existing) {
                    return Result::success('이미 처리된 요청입니다.', [
                        'log_id' => $existing->getLogId(),
                        'balance_before' => $existing->getBalanceBefore(),
                        'balance_after' => $existing->getBalanceAfter(),
                        'idempotent' => true,
                    ]);
                }
            }

            throw $e;
        }

        // 8. BalanceAdjustedEvent 발행 — 커밋 이후이므로 구독자 예외가
        // 조정 자체를 실패로 오보하거나 rollBack 을 유발해서는 안 된다.
        try {
            $this->dispatch(new BalanceAdjustedEvent($memberId, $logId, $newBalance));
        } catch (\Throwable $e) {
            error_log(sprintf(
                '[BALANCE] BalanceAdjustedEvent 구독자 예외 (조정은 커밋됨) — member_id=%d, log_id=%d: %s',
                $memberId,
                $logId,
                $e->getMessage()
            ));
        }

        return Result::success('포인트가 조정되었습니다.', [
            'log_id' => $logId,
            'balance_before' => $currentBalance,
            'balance_after' => $newBalance,
        ]);
    }

    /**
     * MySQL 중복 키 제약 위반(SQLSTATE 23000 / errno 1062) 여부
     */
    private function isDuplicateKeyError(\Throwable $e): bool
    {
        for ($cur = $e; $cur !== null; $cur = $cur->getPrevious()) {
            if ((string) $cur->getCode() === '23000') {
                return true;
            }
            if (str_contains($cur->getMessage(), 'Duplicate entry')) {
                return true;
            }
        }
        return false;
    }

    // ========================================
    // 조회 메서드
    // ========================================

    /**
     * 잔액 조회
     *
     * @param int $memberId 회원 ID
     * @param int|null $domainId 도메인 ID (지정 시 도메인 소유 검증)
     */
    public function getBalance(int $memberId, ?int $domainId = null): int
    {
        $balance = $this->memberRepository->getBalance($memberId, $domainId);
        return $balance ?? 0;
    }

    /**
     * 이력 조회 (특정 회원)
     *
     * @param array $filters 동등 조건 필터 (허용 키: domain_id, action, source_type, source_name, reference_type, reference_id)
     * @return array ['items' => BalanceLog[], 'pagination' => array]
     */
    public function getHistory(int $memberId, array $filters = [], int $page = 1, int $perPage = 20, ?int $domainId = null): array
    {
        // 도메인을 명시 전달한다. 미전달 시 원장이 도메인 스코프 없이 조회된다(회원이 도메인을
        // 이동한 경우 과거 도메인 원장까지 섞임). 프런트/관리자 호출부는 domainId 를 넘겨야 한다.
        $items = $this->logRepository->getByMember($memberId, $page, $perPage, $filters, $domainId);
        $total = $this->logRepository->countByMember($memberId, $filters, $domainId);

        return [
            'items' => $items,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) ceil($total / $perPage),
            ],
        ];
    }

    /**
     * 회원 원장 내역 — 공개 계약(BalanceGatewayInterface)판.
     *
     * getHistory 와 달리 domainId 필수 + 항목을 배열로 반환(엔티티 미노출).
     */
    public function history(int $memberId, int $domainId, array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $filters['domain_id'] = $domainId;
        $result = $this->getHistory($memberId, $filters, $page, $perPage);
        $result['items'] = array_map(
            static fn(BalanceLog $log): array => $log->toArray(),
            $result['items']
        );

        return $result;
    }

    /**
     * 멱등키로 원장 행 조회 — 공개 계약판 (배열 반환, 없으면 null)
     */
    public function findLogByIdempotencyKey(string $idempotencyKey, int $domainId): ?array
    {
        return $this->logRepository->findByIdempotencyKey($idempotencyKey, $domainId)?->toArray();
    }

    /**
     * 참조(주문 등) 기준 지급 합계 — amount > 0 만 합산
     *
     * 포인트 "전액 환수" 등에서 특정 참조로 실제 지급된 총액을 구할 때 쓴다.
     */
    public function sumGrantedByReference(
        int $memberId,
        int $domainId,
        string $action,
        string $referenceType,
        string $referenceId
    ): int {
        return $this->logRepository->sumGrantedByReference(
            $domainId,
            $memberId,
            $action,
            $referenceType,
            $referenceId
        );
    }

    /**
     * 회원별 최근 포인트 내역 (도메인 스코프)
     *
     * 관리자 회원 상세 화면에서 최근 몇 건을 미리 보여줄 때 쓴다.
     * 도메인 격리를 위해 조회 전 도메인 스코프를 지정한다.
     *
     * @param int $domainId 도메인 ID (멀티테넌시 격리)
     * @param int $memberId 회원 ID
     * @param int $limit 최근 건수
     * @return BalanceLog[]
     */
    public function getRecentByMember(int $domainId, int $memberId, int $limit = 5): array
    {
        // domainId 를 명시 인자로 넘긴다. setDomainId 로 공유 리포지토리 인스턴스 상태를 변조하면
        // 이후 같은 요청의 다른 조회가 이 도메인으로 오필터되는 크로스테넌트 누수가 생긴다.
        return $this->logRepository->getByMember($memberId, 1, $limit, [], $domainId);
    }

    /**
     * 관리자용 포인트 로그 목록 조회 (페이지네이션)
     *
     * @param int $domainId 도메인 ID
     * @param int $page 페이지 번호
     * @param int $perPage 페이지당 항목 수
     * @param array $filters 필터 조건 [member_id, source_type, start_date, end_date]
     * @return array ['items' => BalanceLog[], 'pagination' => array]
     */
    public function getPaginatedLogs(int $domainId, int $page = 1, int $perPage = 20, array $filters = []): array
    {
        return $this->logRepository->getPaginatedList($domainId, $page, $perPage, $filters);
    }

    // ========================================
    // 무결성 검증
    // ========================================

    /**
     * 단일 회원 무결성 검증
     *
     * Source of Truth: SUM(balance_logs.amount)
     * 스냅샷: members.point_balance
     *
     * @param int $memberId 회원 ID
     * @param int $domainId 도메인 ID (멀티도메인 경계 보장)
     */
    public function verifyIntegrity(int $memberId, int $domainId): array
    {
        $ledgerSum = $this->logRepository->getSumByMember($memberId, $domainId);
        $snapshot = $this->memberRepository->getBalance($memberId, $domainId) ?? 0;

        $isValid = ($ledgerSum === $snapshot);

        return [
            'valid' => $isValid,
            'member_id' => $memberId,
            'ledger_sum' => $ledgerSum,
            'snapshot' => $snapshot,
            'diff' => $ledgerSum - $snapshot,
        ];
    }

    /**
     * 무결성 복구 (관리자 전용)
     *
     * 원장 기준으로 스냅샷 복구
     */
    public function repair(int $memberId, int $domainId, int $adminId, string $reason): Result
    {
        $this->db->beginTransaction();

        try {
            // Lock the member row, then recalculate inside the transaction.
            $snapshot = $this->memberRepository->getBalanceForUpdate($memberId, $domainId);
            if ($snapshot === null) {
                $this->db->rollBack();
                return Result::failure('회원을 찾을 수 없습니다.');
            }

            $ledgerSum = $this->logRepository->getSumByMember($memberId, $domainId);
            $diff = $ledgerSum - $snapshot;

            if ($diff === 0) {
                $this->db->commit();
                return Result::failure('불일치가 없습니다. 복구가 필요하지 않습니다.');
            }

            // Snapshot repair only. Do not insert into balance_logs because the
            // ledger sum is the source of truth.
            $updated = $this->memberRepository->updateBalance($memberId, $ledgerSum, $domainId);
            if (!$updated) {
                throw new \RuntimeException("잔액 스냅샷 복구 실패 (member_id={$memberId}, domain_id={$domainId})");
            }

            $auditId = $this->repairAuditRepository->create([
                'domain_id' => $domainId,
                'member_id' => $memberId,
                'snapshot_before' => $snapshot,
                'ledger_sum' => $ledgerSum,
                'diff' => $diff,
                'admin_id' => $adminId,
                'reason' => $reason,
                'memo' => "Diff: {$diff}, Snapshot: {$snapshot}, Ledger: {$ledgerSum}",
            ]);

            $this->db->commit();

            return Result::success('무결성이 복구되었습니다.', [
                'audit_id' => $auditId,
                'balance_before' => $snapshot,
                'balance_after' => $ledgerSum,
                'diff' => $diff,
            ]);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    // ========================================
    // 헬퍼 메서드
    // ========================================

    /**
     * 파라미터 유효성 검증
     */
    private function validateParams(array $params): array
    {
        foreach (self::REQUIRED_FIELDS as $field) {
            if (!isset($params[$field]) || $params[$field] === '') {
                return [
                    'valid' => false,
                    'message' => "필수 필드가 누락되었습니다: {$field}",
                ];
            }
        }

        if (!is_numeric($params['amount']) || (int) $params['amount'] === 0) {
            return [
                'valid' => false,
                'message' => 'amount는 0이 아닌 정수여야 합니다.',
            ];
        }

        return ['valid' => true];
    }
}
