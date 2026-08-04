<?php

namespace Tests\Banner\Unit;

use Mublo\Plugin\Banner\Service\BannerDataResetter;
use PHPUnit\Framework\TestCase;
use Tests\Support\DataResetterContractTests;

class BannerDataResetterTest extends TestCase
{
    use DataResetterContractTests;

    protected function resetterClass(): string { return BannerDataResetter::class; }
    protected function resetCategory(): string { return 'banners'; }
    protected function expectedTables(): array { return ['banners']; }
    protected function expectedTablesCleared(): int { return 1; }
    protected function expectedDeleteCount(): int { return 1; }
}
