<?php

namespace Tests\Qna\Unit;

use Mublo\Plugin\Qna\Service\QnaDataResetter;
use PHPUnit\Framework\TestCase;
use Tests\Support\DataResetterContractTests;

class QnaDataResetterTest extends TestCase
{
    use DataResetterContractTests;

    protected function resetterClass(): string { return QnaDataResetter::class; }
    protected function resetCategory(): string { return 'qna'; }
    protected function expectedTables(): array { return ['qna_posts', 'qna_categories']; }
    protected function expectedTablesCleared(): int { return 2; }
    protected function expectedDeleteCount(): int { return 3; }
}
