<?php
declare(strict_types=1);

namespace Mublo\Contract\Balance;

/**
 * 도메인 스코프 랭킹 페이지.
 */
final readonly class BalanceRankingPage
{
    /** @var list<BalanceRankingEntry> */
    public array $items;

    /**
     * @param list<BalanceRankingEntry> $items
     */
    public function __construct(
        array $items,
        public int $page,
        public int $perPage,
        public int $total,
        public BalanceRankingMetric $metric,
        public ?\DateTimeImmutable $from,
        public ?\DateTimeImmutable $to,
    ) {
        foreach ($items as $item) {
            if (!$item instanceof BalanceRankingEntry) {
                throw new \InvalidArgumentException('랭킹 페이지에는 BalanceRankingEntry만 담을 수 있습니다.');
            }
        }

        $this->items = array_values($items);
    }

    public function totalPages(): int
    {
        return max(1, (int) ceil($this->total / $this->perPage));
    }
}
