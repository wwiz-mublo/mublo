<?php
namespace Tests\Board\Unit\Plugins;
use Mublo\Packages\Board\Plugins\BoardReport\Service\BoardReportDataResetter;
use PHPUnit\Framework\TestCase;
use Tests\Support\DataResetterContractTests;
final class BoardReportDataResetterTest extends TestCase
{
    use DataResetterContractTests;
    protected function resetterClass(): string { return BoardReportDataResetter::class; }
    protected function resetCategory(): string { return 'board_reports'; }
    protected function expectedTables(): array { return ['board_report_blinds', 'board_reports']; }
    protected function expectedTablesCleared(): int { return 2; }
    protected function expectedDeleteCount(): int { return 2; }
}
