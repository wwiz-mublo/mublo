<?php
namespace Tests\SendonTalk\Unit;
use Mublo\Plugin\SendonTalk\Service\SendonTalkDataResetter;
use PHPUnit\Framework\TestCase;
use Tests\Support\DataResetterContractTests;
final class SendonTalkDataResetterTest extends TestCase
{
    use DataResetterContractTests;
    protected function resetterClass(): string { return SendonTalkDataResetter::class; }
    protected function resetCategory(): string { return 'talk_logs'; }
    protected function expectedTables(): array { return ['plugin_sendon_talk_logs']; }
    protected function expectedTablesCleared(): int { return 1; }
    protected function expectedDeleteCount(): int { return 1; }
}
