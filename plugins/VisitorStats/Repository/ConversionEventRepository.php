<?php
declare(strict_types=1);

namespace Mublo\Plugin\VisitorStats\Repository;

use Mublo\Infrastructure\Database\Database;

/**
 * 이벤트 기반 전환 Repository
 *
 * ConversionRecordedEvent 로 통보된 전환을 plugin_visitor_conversions 에 쌓고 집계한다.
 * VisitorStats 가 소유한 유일한 전환 저장소이며, 발행 확장의 테이블은 읽지 않는다.
 *
 * 전환의 갈래는 `source_type`(주문·상담·폼 접수 등)과 `source_label`(발행 쪽이 실어 보낸
 * 구체 이름)로만 구분한다 — 어떤 확장이 발행했는지는 알지 못한다.
 */
class ConversionEventRepository
{
    private string $table = 'plugin_visitor_conversions';

    public function __construct(private Database $db) {}

    /**
     * 전환 1건 기록 (멱등)
     *
     * 같은 (도메인, 소스 타입, 소스 ID) 가 다시 오면 최신 값으로 갱신한다.
     * 주문이 결제완료 → 취소로 바뀌는 것처럼 같은 사건의 상태가 변할 수 있어,
     * 무시하지 않고 마지막 통보를 남긴다.
     */
    public function record(array $conversion): void
    {
        $table = $this->table;

        $sql = "INSERT INTO `{$table}`
                    (domain_id, source_type, source_id, source_label, campaign_key,
                     status, member_id, value_amount, currency, occurred_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    source_label = VALUES(source_label),
                    campaign_key = VALUES(campaign_key),
                    status       = VALUES(status),
                    member_id    = VALUES(member_id),
                    value_amount = VALUES(value_amount),
                    currency     = VALUES(currency),
                    occurred_at  = VALUES(occurred_at)";

        $this->db->execute($sql, [
            $conversion['domain_id'],
            $conversion['source_type'],
            $conversion['source_id'],
            $conversion['source_label'],
            $conversion['campaign_key'],
            $conversion['status'],
            $conversion['member_id'],
            $conversion['value_amount'],
            $conversion['currency'],
            $conversion['occurred_at'],
        ]);
    }

    /**
     * 소스별 집계 (성공 건수 + 금액 합)
     *
     * 타입뿐 아니라 **라벨까지 묶음 축에 넣는다.** 한 타입 안의 갈래(폼 제목·상품군
     * 등)를 나눠 보여줄지는 발행 쪽이 `sourceLabel` 을 어떻게 싣느냐로 정한다 —
     * 라벨이 하나뿐인 소스는 한 줄로 남고, 갈래별로 실어 보내면 갈래별로 갈라진다.
     *
     * @return array<int, array{source_type: string, source_label: ?string, total: int, conversions: int, value_sum: ?string}>
     */
    public function summaryBySource(int $domainId, string $startDate, string $endDate): array
    {
        $table = $this->table;

        $sql = "SELECT source_type,
                       source_label,
                       COUNT(*) AS total,
                       SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) AS conversions,
                       SUM(CASE WHEN status = 'success' THEN value_amount ELSE 0 END) AS value_sum
                FROM `{$table}`
                WHERE domain_id = ?
                  AND occurred_at BETWEEN ? AND ?
                GROUP BY source_type, source_label
                ORDER BY conversions DESC, source_type ASC, source_label ASC";

        return $this->db->select($sql, [
            $domainId,
            $startDate . ' 00:00:00',
            $endDate . ' 23:59:59',
        ]);
    }

    /**
     * 캠페인 키별 집계 — 유입(캠페인 통계)과 같은 축으로 붙여 보기 위한 것이다.
     *
     * 통보만 되고 아직 성공이 없는 캠페인도 남긴다. 접수 대비 성공률을 보여주는
     * 화면에서 분모가 사라지면 안 된다.
     *
     * @return array<int, array{campaign_key: string, total: int, conversions: int, value_sum: ?string, top_source: ?string}>
     */
    public function summaryByCampaign(int $domainId, string $startDate, string $endDate): array
    {
        $table = $this->table;

        $sql = "SELECT COALESCE(c.campaign_key, '') AS campaign_key,
                       COUNT(*) AS total,
                       SUM(CASE WHEN c.status = 'success' THEN 1 ELSE 0 END) AS conversions,
                       SUM(CASE WHEN c.status = 'success' THEN c.value_amount ELSE 0 END) AS value_sum,
                       (SELECT COALESCE(c2.source_label, c2.source_type)
                        FROM `{$table}` c2
                        WHERE c2.domain_id = c.domain_id
                          AND COALESCE(c2.campaign_key, '') = COALESCE(c.campaign_key, '')
                          AND c2.occurred_at BETWEEN ? AND ?
                        GROUP BY c2.source_type, c2.source_label
                        ORDER BY COUNT(*) DESC
                        LIMIT 1) AS top_source
                FROM `{$table}` c
                WHERE c.domain_id = ?
                  AND c.occurred_at BETWEEN ? AND ?
                GROUP BY campaign_key
                ORDER BY conversions DESC";

        return $this->db->select($sql, [
            $startDate . ' 00:00:00',
            $endDate . ' 23:59:59',
            $domainId,
            $startDate . ' 00:00:00',
            $endDate . ' 23:59:59',
        ]);
    }

    /**
     * 기간 내 통보 총건수 + 성공 건수
     *
     * @return array{total: int, conversions: int}
     */
    public function totals(int $domainId, string $startDate, string $endDate): array
    {
        $table = $this->table;

        $sql = "SELECT COUNT(*) AS total,
                       SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) AS conversions
                FROM `{$table}`
                WHERE domain_id = ?
                  AND occurred_at BETWEEN ? AND ?";

        $row = $this->db->selectOne($sql, [
            $domainId,
            $startDate . ' 00:00:00',
            $endDate . ' 23:59:59',
        ]);

        return [
            'total'       => (int) ($row['total'] ?? 0),
            'conversions' => (int) ($row['conversions'] ?? 0),
        ];
    }

    /**
     * 일별 추이 (통보 + 성공)
     *
     * @return array<int, array{conv_date: string, total: int, conversions: int}>
     */
    public function dailyTrend(
        int $domainId,
        string $startDate,
        string $endDate,
        ?string $campaignKey = null
    ): array {
        $table = $this->table;

        $params = [$domainId, $startDate . ' 00:00:00', $endDate . ' 23:59:59'];
        $campaignWhere = '';

        if ($campaignKey !== null) {
            if ($campaignKey === '') {
                $campaignWhere = ' AND campaign_key IS NULL';
            } else {
                $campaignWhere = ' AND campaign_key = ?';
                $params[] = $campaignKey;
            }
        }

        $sql = "SELECT DATE(occurred_at) AS conv_date,
                       COUNT(*) AS total,
                       SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) AS conversions
                FROM `{$table}`
                WHERE domain_id = ?
                  AND occurred_at BETWEEN ? AND ?
                  {$campaignWhere}
                GROUP BY DATE(occurred_at)
                ORDER BY conv_date ASC";

        return $this->db->select($sql, $params);
    }

    /**
     * 전환 목록 (페이지네이션)
     *
     * @return array{items: array<int, array<string, mixed>>, total: int}
     */
    public function listConversions(
        int $domainId,
        string $startDate,
        string $endDate,
        ?string $sourceType = null,
        ?string $sourceLabel = null,
        ?string $campaignKey = null,
        int $page = 1,
        int $perPage = 20
    ): array {
        $table = $this->table;

        $params = [$domainId, $startDate . ' 00:00:00', $endDate . ' 23:59:59'];
        $where = '';

        if ($sourceType !== null && $sourceType !== '') {
            $where .= ' AND source_type = ?';
            $params[] = $sourceType;

            // 라벨은 타입 안의 갈래라 타입과 함께일 때만 의미가 있다.
            // 라벨 없이 기록된 행을 고르려면 빈 문자열을 넘긴다.
            if ($sourceLabel !== null) {
                if ($sourceLabel === '') {
                    $where .= ' AND source_label IS NULL';
                } else {
                    $where .= ' AND source_label = ?';
                    $params[] = $sourceLabel;
                }
            }
        }

        if ($campaignKey !== null) {
            if ($campaignKey === '') {
                $where .= ' AND campaign_key IS NULL';
            } else {
                $where .= ' AND campaign_key = ?';
                $params[] = $campaignKey;
            }
        }

        $countSql = "SELECT COUNT(*) AS cnt
                     FROM `{$table}`
                     WHERE domain_id = ?
                       AND occurred_at BETWEEN ? AND ?
                       {$where}";

        $total = (int) ($this->db->selectOne($countSql, $params)['cnt'] ?? 0);

        $offset = ($page - 1) * $perPage;
        $listParams = array_merge($params, [$perPage, $offset]);

        $sql = "SELECT conversion_id,
                       source_type,
                       source_label,
                       source_id,
                       campaign_key,
                       status,
                       value_amount,
                       currency,
                       occurred_at
                FROM `{$table}`
                WHERE domain_id = ?
                  AND occurred_at BETWEEN ? AND ?
                  {$where}
                ORDER BY occurred_at DESC, conversion_id DESC
                LIMIT ? OFFSET ?";

        return [
            'items' => $this->db->select($sql, $listParams),
            'total' => $total,
        ];
    }

    /**
     * 필터 드롭다운용 소스 목록 — 기간 내 실적이 있는 갈래만 나온다.
     *
     * 집계 표와 같은 축(타입+라벨)으로 뽑는다. 표는 폼별로 갈라지는데 필터는
     * 폼 전체만 고를 수 있으면 어긋나 보인다.
     *
     * @return array<int, array{source_type: string, source_label: ?string}>
     */
    public function sourceOptions(int $domainId, string $startDate, string $endDate): array
    {
        $table = $this->table;

        $sql = "SELECT source_type,
                       source_label
                FROM `{$table}`
                WHERE domain_id = ?
                  AND occurred_at BETWEEN ? AND ?
                GROUP BY source_type, source_label
                ORDER BY source_type ASC, source_label ASC";

        return $this->db->select($sql, [
            $domainId,
            $startDate . ' 00:00:00',
            $endDate . ' 23:59:59',
        ]);
    }
}
