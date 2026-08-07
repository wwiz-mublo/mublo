<?php
declare(strict_types=1);

namespace Mublo\Contract\Balance;

/**
 * 랭킹 모집단과 결과 검색 조건.
 *
 * keyword는 결과를 좁힐 뿐 순위 모집단에는 영향을 주지 않는다. 따라서 닉네임을
 * 검색해도 검색 결과 안에서 순위를 다시 매기지 않고 전체 순위를 유지한다.
 */
final readonly class BalanceRankingFilter
{
    private const MEMBER_STATUSES = [
        'active', 'inactive', 'dormant', 'blocked', 'pending', 'withdrawn',
    ];

    private const LEVEL_TYPES = [
        'SUPER', 'STAFF', 'PARTNER', 'SITE_MASTER', 'SELLER', 'SUPPLIER', 'BASIC',
    ];

    /** @var list<string> */
    public array $statuses;

    /** @var list<string> */
    public array $excludedLevelTypes;

    public ?string $keyword;

    /**
     * @param list<string> $statuses
     * @param list<string> $excludedLevelTypes
     */
    public function __construct(
        array $statuses = ['active'],
        array $excludedLevelTypes = [],
        ?string $keyword = null,
    ) {
        $statuses = array_values(array_unique(array_map('strtolower', $statuses)));
        if ($statuses === [] || array_diff($statuses, self::MEMBER_STATUSES) !== []) {
            throw new \InvalidArgumentException('랭킹 회원 상태 조건이 올바르지 않습니다.');
        }

        $excludedLevelTypes = array_values(array_unique(array_map('strtoupper', $excludedLevelTypes)));
        if (array_diff($excludedLevelTypes, self::LEVEL_TYPES) !== []) {
            throw new \InvalidArgumentException('랭킹 제외 레벨 타입이 올바르지 않습니다.');
        }

        $keyword = $keyword !== null ? trim($keyword) : null;
        if ($keyword === '') {
            $keyword = null;
        }
        if ($keyword !== null && mb_strlen($keyword) > 50) {
            throw new \InvalidArgumentException('랭킹 검색어는 50자 이하여야 합니다.');
        }

        $this->statuses = $statuses;
        $this->excludedLevelTypes = $excludedLevelTypes;
        $this->keyword = $keyword;
    }

    public function withoutKeyword(): self
    {
        return new self($this->statuses, $this->excludedLevelTypes);
    }
}
