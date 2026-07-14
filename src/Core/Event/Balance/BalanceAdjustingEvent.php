<?php
namespace Mublo\Core\Event\Balance;

use Mublo\Core\Event\AbstractEvent;
use Mublo\Core\Event\FailFastEventInterface;

/**
 * 잔액 변경 전 이벤트 (차단 가능)
 *
 * 발행 시점: DB 저장 전 (트랜잭션 내)
 * 용도: 추가 검증, 차단 (스팸 필터링 등)
 *
 * Note: 이벤트에서 stopPropagation() 및 setBlocked()로 잔액 변경을 차단할 수 있음
 */
class BalanceAdjustingEvent extends AbstractEvent implements FailFastEventInterface
{
    public const NAME = 'balance.adjusting';

    private int $memberId;
    private int $amount;
    private int $currentBalance;

    private bool $blocked = false;
    private ?string $blockReason = null;

    public function __construct(int $memberId, int $amount, int $currentBalance)
    {
        $this->memberId = $memberId;
        $this->amount = $amount;
        $this->currentBalance = $currentBalance;
    }

    public function getMemberId(): int
    {
        return $this->memberId;
    }

    public function getAmount(): int
    {
        return $this->amount;
    }

    public function getCurrentBalance(): int
    {
        return $this->currentBalance;
    }

    public function getNewBalance(): int
    {
        return $this->currentBalance + $this->amount;
    }

    /**
     * 차단 여부 설정
     */
    public function setBlocked(bool $blocked): void
    {
        $this->blocked = $blocked;
    }

    /**
     * 차단 여부 조회
     */
    public function isBlocked(): bool
    {
        return $this->blocked;
    }

    /**
     * 차단 사유 설정
     */
    public function setBlockReason(?string $reason): void
    {
        $this->blockReason = $reason;
    }

    /**
     * 차단 사유 조회
     */
    public function getBlockReason(): ?string
    {
        return $this->blockReason;
    }

    /**
     * 지급인지 여부
     */
    public function isAddition(): bool
    {
        return $this->amount > 0;
    }

    /**
     * 차감인지 여부
     */
    public function isDeduction(): bool
    {
        return $this->amount < 0;
    }
}
