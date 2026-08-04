<?php
namespace Tests\Widget\Unit;
use Mublo\Plugin\Widget\Service\WidgetDataResetter;
use PHPUnit\Framework\TestCase;
use Tests\Support\DataResetterContractTests;
final class WidgetDataResetterTest extends TestCase
{
    use DataResetterContractTests;
    protected function resetterClass(): string { return WidgetDataResetter::class; }
    protected function resetCategory(): string { return 'widgets'; }
    protected function expectedTables(): array { return ['plugin_widget_items']; }
    protected function expectedTablesCleared(): int { return 1; }
    protected function expectedDeleteCount(): int { return 1; }
}
