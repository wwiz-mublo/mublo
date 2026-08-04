<?php

namespace Tests\Unit\Service\System;

use Mublo\Infrastructure\Database\Database;
use Mublo\Service\System\CoreDataResetter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CoreDataResetterTest extends TestCase
{
    #[Test]
    public function memberResetSoftWithdrawsOnlyUnprotectedMembersAndUsesRealProxyTokenColumn(): void
    {
        $selectCount = 0;
        $queries = [];
        $db = $this->createMock(Database::class);
        $db->method('select')->willReturnCallback(function () use (&$selectCount): array {
            $selectCount++;
            return $selectCount === 1 ? [['member_id' => 1]] : [['member_id' => 2]];
        });
        $db->method('tableExists')->willReturnCallback(static fn(string $table): bool => $table === 'proxy_login_tokens');
        $db->method('execute')->willReturnCallback(function (string $sql, array $params = []) use (&$queries): int { $queries[] = [$sql, $params]; return 1; });

        $result = (new CoreDataResetter($db))->reset('members', 7);

        $this->assertSame(2, $result->tablesCleared);
        $this->assertStringContainsString('admin_member_id', $queries[0][0]);
        $this->assertSame([2], $queries[0][1]);
        $this->assertStringContainsString("status = 'withdrawn'", $queries[1][0]);
        $this->assertStringNotContainsString('SET domain_id = 1', $queries[1][0]);
        $this->assertSame([2], $queries[1][1]);
    }

    #[Test]
    public function blockResetIncludesStacksRevisionsAndApplicationHistory(): void
    {
        $queries = [];
        $db = $this->createMock(Database::class);
        $db->method('tableExists')->willReturn(true);
        $db->method('execute')->willReturnCallback(function (string $sql, array $params = []) use (&$queries): int { $queries[] = [$sql, $params]; return 1; });

        $result = (new CoreDataResetter($db))->reset('blocks', 5);
        $allSql = implode("\n", array_column($queries, 0));

        $this->assertSame(6, $result->tablesCleared);
        $this->assertStringContainsString('block_column_contents', $allSql);
        $this->assertStringContainsString('block_row_revisions', $allSql);
        $this->assertStringContainsString('block_kit_applications', $allSql);
    }
}
