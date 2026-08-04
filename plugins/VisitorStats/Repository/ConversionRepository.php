<?php
declare(strict_types=1);

namespace Mublo\Plugin\VisitorStats\Repository;

use Mublo\Infrastructure\Database\Database;

/**
 * 전환 추적 Repository
 *
 * form_submissions 테이블 기반으로 전환 데이터를 조회한다.
 * VisitorStats 플러그인이 AutoForm의 데이터를 읽기 전용으로 참조.
 *
 * 전환 기준: outcome = 'success' (성공 건만 전환으로 집계)
 * 전체 접수: outcome 필터 없이 COUNT(*) (접수 건수)
 */
class ConversionRepository
{
    private ?bool $tableExists = null;

    public function __construct(private Database $db) {}

    /**
     * form_submissions 테이블 존재 여부 (AutoForm 플러그인 의존)
     */
    public function hasTable(): bool
    {
        if ($this->tableExists === null) {
            $row = $this->db->selectOne(
                "SELECT COUNT(*) AS cnt FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'form_submissions'"
            );
            $this->tableExists = ((int) ($row['cnt'] ?? 0)) > 0;
        }
        return $this->tableExists;
    }

    /**
     * 기간별 캠페인키별 전환 건수 (접수 총 건수 + 성공 건수)
     */
    public function getConversionsByCampaign(int $domainId, string $startDate, string $endDate): array
    {
        $sql = "SELECT COALESCE(campaign_key, '') AS campaign_key,
                       COUNT(*) AS submissions,
                       SUM(CASE WHEN outcome = 'success' THEN 1 ELSE 0 END) AS conversions
                FROM form_submissions
                WHERE domain_id = ?
                  AND created_at BETWEEN ? AND ?
                GROUP BY campaign_key
                ORDER BY conversions DESC";

        return $this->db->select($sql, [
            $domainId,
            $startDate . ' 00:00:00',
            $endDate . ' 23:59:59',
        ]);
    }

    /**
     * 기간별 전환 총 건수 + 접수 총 건수
     */
    public function getTotalConversions(int $domainId, string $startDate, string $endDate): array
    {
        $sql = "SELECT COUNT(*) AS submissions,
                       SUM(CASE WHEN outcome = 'success' THEN 1 ELSE 0 END) AS conversions
                FROM form_submissions
                WHERE domain_id = ?
                  AND created_at BETWEEN ? AND ?";

        $row = $this->db->selectOne($sql, [
            $domainId,
            $startDate . ' 00:00:00',
            $endDate . ' 23:59:59',
        ]);

        return [
            'submissions' => (int) ($row['submissions'] ?? 0),
            'conversions' => (int) ($row['conversions'] ?? 0),
        ];
    }

    /**
     * 일별 전환 추이 (접수 + 성공)
     */
    public function getDailyConversions(
        int $domainId,
        string $startDate,
        string $endDate,
        ?string $campaignKey = null
    ): array {
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

        $sql = "SELECT DATE(created_at) AS conv_date,
                       COUNT(*) AS submissions,
                       SUM(CASE WHEN outcome = 'success' THEN 1 ELSE 0 END) AS conversions
                FROM form_submissions
                WHERE domain_id = ?
                  AND created_at BETWEEN ? AND ?
                  {$campaignWhere}
                GROUP BY DATE(created_at)
                ORDER BY conv_date ASC";

        return $this->db->select($sql, $params);
    }

    /**
     * 폼별 전환 현황 (접수 + 성공)
     */
    public function getConversionsByForm(int $domainId, string $startDate, string $endDate): array
    {
        $sql = "SELECT s.form_id,
                       f.form_name,
                       COUNT(*) AS submissions,
                       SUM(CASE WHEN s.outcome = 'success' THEN 1 ELSE 0 END) AS conversions,
                       COUNT(s.campaign_key) AS campaign_conversions
                FROM form_submissions s
                LEFT JOIN forms f ON f.form_id = s.form_id
                WHERE s.domain_id = ?
                  AND s.created_at BETWEEN ? AND ?
                GROUP BY s.form_id, f.form_name
                ORDER BY conversions DESC";

        return $this->db->select($sql, [
            $domainId,
            $startDate . ' 00:00:00',
            $endDate . ' 23:59:59',
        ]);
    }

    /**
     * 특정 폼의 캠페인별 전환 (접수 + 성공)
     */
    public function getFormConversionsByCampaign(
        int $domainId,
        int $formId,
        string $startDate,
        string $endDate
    ): array {
        $sql = "SELECT COALESCE(campaign_key, '') AS campaign_key,
                       COUNT(*) AS submissions,
                       SUM(CASE WHEN outcome = 'success' THEN 1 ELSE 0 END) AS conversions
                FROM form_submissions
                WHERE domain_id = ?
                  AND form_id = ?
                  AND created_at BETWEEN ? AND ?
                GROUP BY campaign_key
                ORDER BY conversions DESC";

        return $this->db->select($sql, [
            $domainId,
            $formId,
            $startDate . ' 00:00:00',
            $endDate . ' 23:59:59',
        ]);
    }

    /**
     * 전환 목록 (페이지네이션) — outcome 컬럼 포함
     */
    public function getConversionList(
        int $domainId,
        string $startDate,
        string $endDate,
        ?int $formId = null,
        ?string $campaignKey = null,
        int $page = 1,
        int $perPage = 20
    ): array {
        $params = [$domainId, $startDate . ' 00:00:00', $endDate . ' 23:59:59'];
        $where = '';

        if ($formId !== null) {
            $where .= ' AND s.form_id = ?';
            $params[] = $formId;
        }

        if ($campaignKey !== null) {
            if ($campaignKey === '') {
                $where .= ' AND s.campaign_key IS NULL';
            } else {
                $where .= ' AND s.campaign_key = ?';
                $params[] = $campaignKey;
            }
        }

        $countSql = "SELECT COUNT(*) AS cnt
                     FROM form_submissions s
                     WHERE s.domain_id = ?
                       AND s.created_at BETWEEN ? AND ?
                       {$where}";

        $total = (int) ($this->db->selectOne($countSql, $params)['cnt'] ?? 0);

        $offset = ($page - 1) * $perPage;
        $listParams = array_merge($params, [$perPage, $offset]);

        $sql = "SELECT s.submission_id,
                       s.form_id,
                       f.form_name,
                       s.campaign_key,
                       s.outcome,
                       s.ip_address,
                       s.created_at
                FROM form_submissions s
                LEFT JOIN forms f ON f.form_id = s.form_id
                WHERE s.domain_id = ?
                  AND s.created_at BETWEEN ? AND ?
                  {$where}
                ORDER BY s.created_at DESC
                LIMIT ? OFFSET ?";

        $items = $this->db->select($sql, $listParams);

        return [
            'items' => $items,
            'total' => $total,
        ];
    }

    /**
     * 캠페인별 전환 + 최다 전환 폼 (접수 + 성공)
     */
    public function getCampaignConversionDetail(int $domainId, string $startDate, string $endDate): array
    {
        $sql = "SELECT COALESCE(s.campaign_key, '') AS campaign_key,
                       COUNT(*) AS submissions,
                       SUM(CASE WHEN s.outcome = 'success' THEN 1 ELSE 0 END) AS conversions,
                       (SELECT f2.form_name
                        FROM form_submissions s2
                        LEFT JOIN forms f2 ON f2.form_id = s2.form_id
                        WHERE s2.domain_id = s.domain_id
                          AND COALESCE(s2.campaign_key, '') = COALESCE(s.campaign_key, '')
                          AND s2.created_at BETWEEN ? AND ?
                        GROUP BY s2.form_id, f2.form_name
                        ORDER BY COUNT(*) DESC
                        LIMIT 1) AS top_form
                FROM form_submissions s
                WHERE s.domain_id = ?
                  AND s.created_at BETWEEN ? AND ?
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
     * 폼 목록 (필터 드롭다운용)
     */
    public function getFormList(int $domainId): array
    {
        $sql = "SELECT form_id, form_name
                FROM forms
                WHERE domain_id = ? AND status = 'active'
                ORDER BY form_name ASC";

        return $this->db->select($sql, [$domainId]);
    }

    /**
     * 특정 폼의 일별 전환 추이 (접수 + 성공)
     */
    public function getFormDailyConversions(
        int $domainId,
        int $formId,
        string $startDate,
        string $endDate
    ): array {
        $sql = "SELECT DATE(created_at) AS conv_date,
                       COUNT(*) AS submissions,
                       SUM(CASE WHEN outcome = 'success' THEN 1 ELSE 0 END) AS conversions
                FROM form_submissions
                WHERE domain_id = ?
                  AND form_id = ?
                  AND created_at BETWEEN ? AND ?
                GROUP BY DATE(created_at)
                ORDER BY conv_date ASC";

        return $this->db->select($sql, [
            $domainId,
            $formId,
            $startDate . ' 00:00:00',
            $endDate . ' 23:59:59',
        ]);
    }
}
