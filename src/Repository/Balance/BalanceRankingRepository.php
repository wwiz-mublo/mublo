<?php
declare(strict_types=1);

namespace Mublo\Repository\Balance;

use Mublo\Contract\Balance\BalanceRankingFilter;
use Mublo\Contract\Balance\BalanceRankingMetric;
use Mublo\Infrastructure\Database\Database;

/**
 * Balance 랭킹 SQL. 공개 계약의 DTO 변환과 입력 규칙은 Service가 담당한다.
 */
class BalanceRankingRepository
{
    public function __construct(private Database $db)
    {
    }

    /**
     * @return array{items:list<array{member_id:mixed,score:mixed,rank_position:mixed}>,total:int}
     */
    public function paginate(
        int $domainId,
        BalanceRankingMetric $metric,
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $to,
        int $page,
        int $perPage,
        BalanceRankingFilter $filter,
    ): array {
        $source = $this->scoreSource($domainId, $metric, $from, $to, $filter);
        [$keywordSql, $keywordParams] = $this->keywordCondition($filter, 'listed');

        $countRow = $this->db->selectOne(
            "SELECT COUNT(*) AS total FROM ({$source['sql']}) listed{$keywordSql}",
            array_merge($source['params'], $keywordParams)
        );
        $total = (int) ($countRow['total'] ?? 0);

        if ($total === 0) {
            return ['items' => [], 'total' => 0];
        }

        $offset = ($page - 1) * $perPage;
        $sql = "SELECT listed.member_id,
                       listed.score,
                       (SELECT COUNT(*) + 1
                          FROM ({$source['sql']}) higher
                         WHERE higher.score > listed.score) AS rank_position
                  FROM ({$source['sql']}) listed{$keywordSql}
                 ORDER BY listed.score DESC, listed.member_id ASC
                 LIMIT {$perPage} OFFSET {$offset}";

        $rows = $this->db->select(
            $sql,
            array_merge($source['params'], $source['params'], $keywordParams)
        );

        return ['items' => $rows, 'total' => $total];
    }

    /** @return array{member_id:mixed,score:mixed,rank_position:mixed}|null */
    public function findMemberRank(
        int $domainId,
        int $memberId,
        BalanceRankingMetric $metric,
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $to,
        BalanceRankingFilter $filter,
    ): ?array {
        $source = $this->scoreSource($domainId, $metric, $from, $to, $filter);
        $sql = "SELECT listed.member_id,
                       listed.score,
                       (SELECT COUNT(*) + 1
                          FROM ({$source['sql']}) higher
                         WHERE higher.score > listed.score) AS rank_position
                  FROM ({$source['sql']}) listed
                 WHERE listed.member_id = ?
                 LIMIT 1";

        return $this->db->selectOne(
            $sql,
            array_merge($source['params'], $source['params'], [$memberId])
        );
    }

    /** @param list<int> $memberIds @return list<array{member_id:mixed,score:mixed,rank_position:mixed}> */
    public function findMemberRanks(
        int $domainId,
        array $memberIds,
        BalanceRankingMetric $metric,
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $to,
        BalanceRankingFilter $filter,
    ): array {
        if ($memberIds === []) {
            return [];
        }

        $source = $this->scoreSource($domainId, $metric, $from, $to, $filter);
        $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
        $sql = "SELECT listed.member_id,
                       listed.score,
                       (SELECT COUNT(*) + 1
                          FROM ({$source['sql']}) higher
                         WHERE higher.score > listed.score) AS rank_position
                  FROM ({$source['sql']}) listed
                 WHERE listed.member_id IN ({$placeholders})
                 ORDER BY listed.member_id ASC";

        return $this->db->select(
            $sql,
            array_merge($source['params'], $source['params'], $memberIds)
        );
    }

    /** @return array{sql:string,params:list<mixed>} */
    private function scoreSource(
        int $domainId,
        BalanceRankingMetric $metric,
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $to,
        BalanceRankingFilter $filter,
    ): array {
        [$eligibilitySql, $eligibilityParams] = $this->eligibilityCondition($domainId, $filter);

        if ($metric === BalanceRankingMetric::BALANCE && $to === null) {
            return [
                'sql' => "SELECT m.member_id, m.nickname, m.point_balance AS score
                            FROM members m
                       LEFT JOIN member_levels ml ON ml.level_value = m.level_value
                           WHERE {$eligibilitySql}
                             AND m.point_balance > 0",
                'params' => $eligibilityParams,
            ];
        }

        if ($metric === BalanceRankingMetric::BALANCE) {
            return [
                'sql' => "SELECT m.member_id, m.nickname, SUM(bl.amount) AS score
                            FROM members m
                      INNER JOIN balance_logs bl
                              ON bl.domain_id = m.domain_id AND bl.member_id = m.member_id
                       LEFT JOIN member_levels ml ON ml.level_value = m.level_value
                           WHERE {$eligibilitySql}
                             AND bl.created_at < ?
                        GROUP BY m.member_id, m.nickname
                          HAVING score > 0",
                'params' => array_merge($eligibilityParams, [$this->formatDate($to)]),
            ];
        }

        $scoreExpression = $metric === BalanceRankingMetric::EARNED
            ? 'SUM(bl.amount)'
            : 'SUM(bl.amount)';
        $positiveOnly = $metric === BalanceRankingMetric::EARNED ? ' AND bl.amount > 0' : '';

        return [
            'sql' => "SELECT m.member_id, m.nickname, {$scoreExpression} AS score
                        FROM members m
                  INNER JOIN balance_logs bl
                          ON bl.domain_id = m.domain_id AND bl.member_id = m.member_id
                   LEFT JOIN member_levels ml ON ml.level_value = m.level_value
                       WHERE {$eligibilitySql}
                         AND bl.created_at >= ?
                         AND bl.created_at < ?{$positiveOnly}
                    GROUP BY m.member_id, m.nickname
                      HAVING score > 0",
            'params' => array_merge(
                $eligibilityParams,
                [$this->formatDate($from), $this->formatDate($to)]
            ),
        ];
    }

    /** @return array{0:string,1:list<mixed>} */
    private function eligibilityCondition(int $domainId, BalanceRankingFilter $filter): array
    {
        $statusPlaceholders = implode(',', array_fill(0, count($filter->statuses), '?'));
        $sql = "m.domain_id = ? AND m.status IN ({$statusPlaceholders})";
        $params = array_merge([$domainId], $filter->statuses);

        if ($filter->excludedLevelTypes !== []) {
            $levelPlaceholders = implode(',', array_fill(0, count($filter->excludedLevelTypes), '?'));
            $sql .= " AND (ml.level_type IS NULL OR ml.level_type NOT IN ({$levelPlaceholders}))";
            $params = array_merge($params, $filter->excludedLevelTypes);
        }

        return [$sql, $params];
    }

    /** @return array{0:string,1:list<string>} */
    private function keywordCondition(BalanceRankingFilter $filter, string $alias): array
    {
        if ($filter->keyword === null) {
            return ['', []];
        }

        $escaped = addcslashes($filter->keyword, '\\%_');
        $like = '%' . $escaped . '%';

        return [
            " WHERE {$alias}.nickname LIKE ?",
            [$like],
        ];
    }

    private function formatDate(?\DateTimeImmutable $date): string
    {
        if ($date === null) {
            throw new \LogicException('랭킹 기간 경계가 필요합니다.');
        }

        return $date->format('Y-m-d H:i:s');
    }
}
