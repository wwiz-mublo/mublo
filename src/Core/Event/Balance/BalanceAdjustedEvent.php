<?php
namespace Mublo\Core\Event\Balance;

use Mublo\Core\Event\AbstractEvent;

/**
 * 잔액 변경 완료 이벤트 (readonly)
 *
 * 발행 시점: DB 저장 후 (트랜잭션 커밋 이후)
 * 용도: 알림, 포인트 통계, 로깅 등
 *
 * Note: 이 이벤트는 차단 불가 - 이미 변경이 완료된 후 발행됨
 */
class BalanceAdjustedEvent extends AbstractEvent
{
    public const NAME = 'balance.adjusted';

    private int $memberId;
    private int $logId;
    private int $newBalance;

    public function __construct(int $memberId, int $logId, int $newBalance)
    {
        $this->memberId = $memberId;
        $this->logId = $logId;
        $this->newBalance = $newBalance;
    }

    public function getMemberId(): int
    {
        return $this->memberId;
    }

    public function getLogId(): int
    {
        return $this->logId;
    }

    public function getNewBalance(): int
    {
        return $this->newBalance;
    }
}
