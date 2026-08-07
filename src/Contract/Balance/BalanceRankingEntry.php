<?php
declare(strict_types=1);

namespace Mublo\Contract\Balance;

/**
 * 내부 회원 Entity를 노출하지 않는 랭킹 한 행.
 */
final readonly class BalanceRankingEntry
{
    public function __construct(
        public int $memberId,
        public int $score,
        public int $rank,
    ) {
        if ($memberId < 1 || $score < 1 || $rank < 1) {
            throw new \InvalidArgumentException('랭킹 항목 값이 올바르지 않습니다.');
        }
    }
}
