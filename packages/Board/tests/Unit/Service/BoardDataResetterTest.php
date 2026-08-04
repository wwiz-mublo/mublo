<?php

namespace Tests\Board\Unit\Service;

use Mublo\Packages\Board\Service\BoardDataResetter;
use Mublo\Contract\Balance\BalanceResetGatewayInterface;
use Mublo\Contract\DataResettableInterface;
use Mublo\Infrastructure\Database\Database;
use PHPUnit\Framework\TestCase;
use Tests\Support\DataResetterContractTests;

class BoardDataResetterTest extends TestCase
{
    use DataResetterContractTests;

    protected function resetterClass(): string { return BoardDataResetter::class; }
    protected function resetCategory(): string { return 'board'; }
    protected function expectedTables(): array
    {
        return [
            'board_reactions',
            'board_comments',
            'board_attachments',
            'board_links',
            'board_articles',
        ];
    }
    protected function expectedTablesCleared(): int { return 5; }
    protected function expectedDeleteCount(): int { return 5; }

    protected function createResetter(Database $db): DataResettableInterface
    {
        $gateway = $this->createStub(BalanceResetGatewayInterface::class);
        $gateway->method('resetSource')->willReturn(0);
        return new BoardDataResetter($db, $gateway);
    }
}
