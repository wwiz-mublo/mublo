<?php

namespace Tests\VisitorStats\Unit;

use Mublo\Plugin\VisitorStats\Service\VisitorStatsDataResetter;
use PHPUnit\Framework\TestCase;
use Tests\Support\DataResetterContractTests;

class VisitorStatsDataResetterTest extends TestCase
{
    use DataResetterContractTests;

    protected function resetterClass(): string { return VisitorStatsDataResetter::class; }
    protected function resetCategory(): string { return 'visitor_stats'; }
    protected function expectedTables(): array
    {
        return [
            'plugin_visitor_logs',
            'plugin_visitor_daily',
            'plugin_visitor_hourly',
            'plugin_visitor_pages',
            'plugin_visitor_referrers',
            'plugin_visitor_campaigns',
        ];
    }
    protected function expectedTablesCleared(): int { return 6; }
    protected function expectedDeleteCount(): int { return 6; }
}
