<?php
declare(strict_types=1);

namespace Mublo\Contract\Balance;

/**
 * 출처별 잔액 원장 초기화 계약.
 *
 * 플러그인은 코어 소유의 balance_logs/members 테이블을 직접 수정하지 않고 이 계약을
 * 통해 자기 출처의 원장만 삭제한다. 구현체는 영향 회원 잠금, 원장 삭제, 잔액
 * 스냅샷 재정합을 하나의 외부 트랜잭션 안에서 수행해야 한다.
 */
interface BalanceResetGatewayInterface
{
    /**
     * 지정 출처의 원장 행을 삭제하고 영향 회원의 잔액을 남은 원장 합계로 재정합한다.
     *
     * 호출자는 먼저 트랜잭션을 시작해야 한다. sourceType과 sourceName을 함께 사용해
     * plugin/package/core 사이의 이름 충돌로 인한 교차 삭제를 방지한다.
     *
     * @return int 삭제된 원장 행 수
     */
    public function resetSource(int $domainId, string $sourceType, string $sourceName): int;
}
