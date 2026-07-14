<?php

namespace Tests\Manual\Unit;

use Mublo\Plugin\Manual\Service\ManualDataResetter;
use PHPUnit\Framework\TestCase;
use Tests\Support\DataResetterContractTests;

class ManualDataResetterTest extends TestCase
{
    use DataResetterContractTests;

    protected function resetterClass(): string { return ManualDataResetter::class; }
    protected function resetCategory(): string { return 'manual'; }
    protected function expectedTables(): array { return ['manual_books', 'manual_pages']; }
    protected function expectedTablesCleared(): int { return 2; }
    protected function expectedDeleteCount(): int { return 2; }
}
