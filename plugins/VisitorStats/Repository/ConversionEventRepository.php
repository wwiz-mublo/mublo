<?php
declare(strict_types=1);

namespace Mublo\Plugin\VisitorStats\Repository;

use Mublo\Infrastructure\Database\Database;

/**
 * 이벤트 기반 전환 Repository
 *
 * ConversionRecordedEvent 로 통보된 전환을 plugin_visitor_conversions 에 쌓고 집계한다.
 * form_submissions 를 읽는 ConversionRepository 와 별개 저장소다 — 그쪽은 AutoForm 이
 * 소유한 폼 접수 데이터이고, 이쪽은 VisitorStats 가 소유한 전환 기록이다.
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
     * 소스 타입별 집계 (성공 건수 + 금액 합)
     *
     * @return array<int, array{source_type: string, source_label: ?string, total: int, conversions: int, value_sum: ?string}>
     */
    public function summaryBySourceType(int $domainId, string $startDate, string $endDate): array
    {
        $table = $this->table;

        $sql = "SELECT source_type,
                       MAX(source_label) AS source_label,
                       COUNT(*) AS total,
                       SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) AS conversions,
                       SUM(CASE WHEN status = 'success' THEN value_amount ELSE 0 END) AS value_sum
                FROM `{$table}`
                WHERE domain_id = ?
                  AND occurred_at BETWEEN ? AND ?
                GROUP BY source_type
                ORDER BY conversions DESC, source_type ASC";

        return $this->db->select($sql, [
            $domainId,
            $startDate . ' 00:00:00',
            $endDate . ' 23:59:59',
        ]);
    }

    /**
     * 캠페인 키별 집계 — 유입(캠페인 통계)과 같은 축으로 붙여 보기 위한 것이다.
     *
     * @return array<int, array{campaign_key: string, conversions: int, value_sum: ?string}>
     */
    public function summaryByCampaign(int $domainId, string $startDate, string $endDate): array
    {
        $table = $this->table;

        $sql = "SELECT COALESCE(campaign_key, '') AS campaign_key,
                       SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) AS conversions,
                       SUM(CASE WHEN status = 'success' THEN value_amount ELSE 0 END) AS value_sum
                FROM `{$table}`
                WHERE domain_id = ?
                  AND occurred_at BETWEEN ? AND ?
                GROUP BY campaign_key
                HAVING conversions > 0
                ORDER BY conversions DESC";

        return $this->db->select($sql, [
            $domainId,
            $startDate . ' 00:00:00',
            $endDate . ' 23:59:59',
        ]);
    }
}
