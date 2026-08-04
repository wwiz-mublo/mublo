<?php

namespace Tests\Popup\Unit;

use Mublo\Plugin\Popup\Service\PopupDataResetter;
use PHPUnit\Framework\TestCase;
use Tests\Support\DataResetterContractTests;

class PopupDataResetterTest extends TestCase
{
    use DataResetterContractTests;

    protected function resetterClass(): string { return PopupDataResetter::class; }
    protected function resetCategory(): string { return 'popups'; }
    protected function expectedTables(): array { return ['popups', 'plugin_popup_configs']; }
    protected function expectedTablesCleared(): int { return 2; }
    protected function expectedDeleteCount(): int { return 2; }
}
