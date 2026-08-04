<?php
declare(strict_types=1);

namespace Mublo\Plugin\VisitorStats\Service;

use Mublo\Contract\DataResetCategory;
use Mublo\Contract\DataResetResult;
use Mublo\Contract\DataResettableInterface;
use Mublo\Infrastructure\Database\Database;

class VisitorStatsDataResetter implements DataResettableInterface
{
    private const TABLES = [
        'plugin_visitor_logs',
        'plugin_visitor_daily',
        'plugin_visitor_hourly',
        'plugin_visitor_pages',
        'plugin_visitor_referrers',
        'plugin_visitor_campaigns',
        // 확장이 통보한 전환 기록. 캠페인 키 설정(plugin_visitor_campaign_keys)은
        // 통계가 아니라 운영자 설정이므로 여기 없는 것이 맞다.
        'plugin_visitor_conversions',
    ];

    public function __construct(private Database $db)
    {
    }

    public function getResetCategories(): array
    {
        return [
            new DataResetCategory('visitor_stats', '방문자 통계', '방문자 통계 데이터를 모두 삭제합니다.', 'bi-graph-up'),
        ];
    }

    public function reset(string $category, int $domainId): DataResetResult
    {
        if ($category !== 'visitor_stats') {
            return new DataResetResult(details: '알 수 없는 카테고리');
        }

        $cleared = 0;
        foreach (self::TABLES as $table) {
            if ($this->db->tableExists($table)) {
                $this->db->execute("DELETE FROM {$table} WHERE domain_id = ?", [$domainId]);
                $cleared++;
            }
        }

        return new DataResetResult($cleared, details: '방문자 통계 데이터 삭제');
    }
}
