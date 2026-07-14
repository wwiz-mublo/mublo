<?php
namespace Tests\SendonSms\Unit;
use Mublo\Plugin\SendonSms\Service\SendonSmsDataResetter;
use PHPUnit\Framework\TestCase;
use Tests\Support\DataResetterContractTests;
final class SendonSmsDataResetterTest extends TestCase
{
    use DataResetterContractTests;
    protected function resetterClass(): string { return SendonSmsDataResetter::class; }
    protected function resetCategory(): string { return 'sms_logs'; }
    protected function expectedTables(): array { return ['plugin_sendon_sms_logs']; }
    protected function expectedTablesCleared(): int { return 1; }
    protected function expectedDeleteCount(): int { return 1; }
}
