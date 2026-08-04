<?php
declare(strict_types=1);

namespace Mublo\Contract\Balance;

use Mublo\Core\Result\Result;

/**
 * 잔액(포인트) 조정·조회의 공개 확장 계약
 *
 * 확장(Package/Plugin)이 잔액을 다루는 유일한 안정 통로다. 내부 구현
 * (`Mublo\Service\Balance\BalanceManager`)과 원장 Repository 는 안정 API 표면이
 * 아니므로 확장이 직접 import 하면 안 된다 (tools/check-extension-api.php 가 검사).
 *
 * ── 구현 규약 ──────────────────────────────────────────────────────────
 * - 모든 조회는 domainId 필수 — 멀티 도메인 격리를 계약 수준에서 강제한다.
 * - 반환에 내부 엔티티를 노출하지 않는다 (history/findLog 는 배열).
 * - adjust 는 원장 단일 진입점의 규약을 따른다: domain_id·member_id·amount·
 *   source_type·source_name·action·message 필수, idempotency_key 는 강력 권장(런타임 강제
 *   없음 — 중복 방지 책임은 호출자에게. 재시도·더블서브밋 시 이중 지급/차감을 막는다). 커밋 전
 *   BalanceAdjustingEvent(차단 가능)·커밋 후 BalanceAdjustedEvent 가 발행된다.
 * - 만들어진 원장 행은 불변(INSERT ONLY) — 정정은 반대 부호의 새 adjust 로 한다.
 * ───────────────────────────────────────────────────────────────────────
 */
interface BalanceGatewayInterface
{
    /**
     * 잔액 조정 (지급 양수 / 차감 음수) — 원장 기록 + 스냅샷 갱신 원자 처리
     *
     * @param array $params domain_id, member_id, amount, source_type, source_name,
     *                      action, message 필수 + idempotency_key(선택이지만 강력 권장 — 런타임 강제는
     *                      없다. 미제공 시 이 호출은 멱등하지 않아, UNIQUE 로 재요청을 막지 못해
     *                      더블서브밋 시 이중 지급/차감이 발생한다. 중복 방지 책임은 호출자에게 있으며,
     *                      관리자 수동 조정·결제 등은 폼 nonce 같은 안정적 키를 넘겨야 한다),
     *                      reference_type/reference_id/admin_id/ip_address/memo 선택
     *
     * 트랜잭션 제약: adjust 는 내부에서 자체 트랜잭션(begin/commit)을 연다. Database 는 중첩
     *   트랜잭션을 지원하지 않으므로, 이미 열린 외부 트랜잭션 안에서 adjust 를 호출하면 실패한다.
     *   주문·데이터 리셋 등 상위 트랜잭션에서 잔액을 다뤄야 하면 커밋 후 호출하거나 순수 SQL 로 맞춘다.
     */
    public function adjust(array $params): Result;

    /**
     * 잔액 조회 (스냅샷)
     */
    public function getBalance(int $memberId, int $domainId): int;

    /**
     * 회원 원장 내역 (도메인 스코프 + 페이지네이션)
     *
     * @param array $filters 동등 조건 (action, source_type, source_name, reference_type, reference_id)
     * @return array{items: list<array<string, mixed>>, pagination: array{current_page:int, per_page:int, total:int, total_pages:int}}
     */
    public function history(int $memberId, int $domainId, array $filters = [], int $page = 1, int $perPage = 20): array;

    /**
     * 참조(주문 등) 기준 지급 합계 — amount > 0 만 SQL SUM 집계 (전액 환수용)
     */
    public function sumGrantedByReference(int $memberId, int $domainId, string $action, string $referenceType, string $referenceId): int;

    /**
     * 멱등키로 원장 행 조회 — 지급/차감 이력 존재 확인용 (없으면 null)
     *
     * @return array<string, mixed>|null
     */
    public function findLogByIdempotencyKey(string $idempotencyKey, int $domainId): ?array;
}
