<?php
declare(strict_types=1);

namespace Mublo\Contract\Balance;

/**
 * 랭킹 모집단과 결과 검색 조건.
 *
 * keyword는 결과를 좁힐 뿐 순위 모집단에는 영향을 주지 않는다. 따라서 닉네임을
 * 검색해도 검색 결과 안에서 순위를 다시 매기지 않고 전체 순위를 유지한다.
 *
 * actions·sources는 점수에 넣을 원장 행을 고른다 — actions는 balance_logs.action,
 * sources는 balance_logs.source_name(Plugin/Package 이름)이다. 비어 있으면 그 조건을 걸지 않는다.
 * 둘 다 주면 둘 다 맞는 행만 센다. action 이름은 확장마다 자유롭게 지어 겹칠 수 있으므로
 * (예: signup, admin_adjust) 특정 확장의 적립만 세려면 sources를 함께 준다.
 *
 * 원장을 합산하는 지표(earned·net, 시점을 준 balance)에만 쓸 수 있고, 현재 잔액(balance, to=null)은
 * 원장을 보지 않으므로 함께 주면 거부한다(BalanceRankingQueryService).
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

    /** @var list<string> */
    public array $actions;

    /** @var list<string> */
    public array $sources;

    /**
     * @param list<string> $statuses
     * @param list<string> $excludedLevelTypes
     * @param list<string> $actions 점수에 넣을 원장 action (비면 전부)
     * @param list<string> $sources 점수에 넣을 원장 출처 source_name (비면 전부)
     */
    public function __construct(
        array $statuses = ['active'],
        array $excludedLevelTypes = [],
        ?string $keyword = null,
        array $actions = [],
        array $sources = [],
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
        $this->actions = self::ledgerNames($actions, '랭킹 원장 action 조건이 올바르지 않습니다.');
        $this->sources = self::ledgerNames($sources, '랭킹 원장 출처 조건이 올바르지 않습니다.');
    }

    public function withoutKeyword(): self
    {
        return new self($this->statuses, $this->excludedLevelTypes, null, $this->actions, $this->sources);
    }

    /**
     * 원장 이름 목록 정리 — balance_logs.action·source_name 은 모두 VARCHAR(50).
     *
     * @param list<string> $names
     * @return list<string>
     */
    private static function ledgerNames(array $names, string $error): array
    {
        $names = array_values(array_unique(array_map('strval', $names)));
        foreach ($names as $name) {
            if (preg_match('/\A[A-Za-z0-9_.:-]{1,50}\z/', $name) !== 1) {
                throw new \InvalidArgumentException($error);
            }
        }

        return $names;
    }
}
