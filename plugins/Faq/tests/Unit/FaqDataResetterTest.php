<?php

namespace Tests\Faq\Unit;

use Mublo\Plugin\Faq\Service\FaqDataResetter;
use PHPUnit\Framework\TestCase;
use Tests\Support\DataResetterContractTests;

class FaqDataResetterTest extends TestCase
{
    use DataResetterContractTests;

    protected function resetterClass(): string { return FaqDataResetter::class; }
    protected function resetCategory(): string { return 'faq'; }
    protected function expectedTables(): array { return ['faq_items', 'faq_categories']; }
    protected function expectedTablesCleared(): int { return 2; }
    protected function expectedDeleteCount(): int { return 2; }
}
