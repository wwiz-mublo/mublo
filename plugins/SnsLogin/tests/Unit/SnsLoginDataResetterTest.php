<?php

namespace Tests\SnsLogin\Unit;

use Mublo\Plugin\SnsLogin\Service\SnsLoginDataResetter;
use PHPUnit\Framework\TestCase;
use Tests\Support\DataResetterContractTests;

class SnsLoginDataResetterTest extends TestCase
{
    use DataResetterContractTests;

    protected function resetterClass(): string { return SnsLoginDataResetter::class; }
    protected function resetCategory(): string { return 'sns_accounts'; }
    protected function expectedTables(): array { return ['plugin_sns_login_accounts']; }
    protected function expectedTablesCleared(): int { return 1; }
    protected function expectedDeleteCount(): int { return 1; }
}
