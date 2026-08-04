<?php
namespace Tests\EmailNotify\Unit;
use Mublo\Plugin\EmailNotify\Service\EmailNotifyDataResetter;
use PHPUnit\Framework\TestCase;
use Tests\Support\DataResetterContractTests;
final class EmailNotifyDataResetterTest extends TestCase
{
    use DataResetterContractTests;
    protected function resetterClass(): string { return EmailNotifyDataResetter::class; }
    protected function resetCategory(): string { return 'email_logs'; }
    protected function expectedTables(): array { return ['plugin_email_notify_logs']; }
    protected function expectedTablesCleared(): int { return 1; }
    protected function expectedDeleteCount(): int { return 1; }
}
