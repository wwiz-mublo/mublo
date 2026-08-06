<?php
declare(strict_types=1);

namespace Mublo\Contract\Balance;

/**
 * Balance 랭킹 점수의 의미.
 */
enum BalanceRankingMetric: string
{
    /** 기간 안에서 지급된 양수 포인트 합계. */
    case EARNED = 'earned';

    /** 기간 안의 지급·차감 순합계. */
    case NET = 'net';

    /** 현재 또는 특정 시각 직전의 보유 포인트. */
    case BALANCE = 'balance';
}
