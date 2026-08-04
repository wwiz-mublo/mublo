<?php

namespace Tests\Unit\Service\System;

use Mublo\Contract\DataResetCategory;
use Mublo\Contract\DataResetResult;
use Mublo\Core\Extension\ExtensionManager;
use Mublo\Entity\Member\Member;
use Mublo\Infrastructure\Database\Database;
use Mublo\Repository\Member\MemberRepository;
use Mublo\Service\Extension\ExtensionService;
use Mublo\Service\System\CoreDataResetter;
use Mublo\Service\System\DataResetService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DataResetServiceTest extends TestCase
{
    #[Test]
    public function categoriesReceiveGloballyUniqueIdsAndMembersRunLast(): void
    {
        $password = 'reset-password';
        $member = $this->createMock(Member::class);
        $member->method('getPassword')->willReturn(password_hash($password, PASSWORD_DEFAULT));
        $member->method('isSuper')->willReturn(true);
        $member->method('getUserId')->willReturn('super');

        $members = $this->createMock(MemberRepository::class);
        $members->method('find')->willReturn($member);
        $extensions = $this->createMock(ExtensionService::class);
        $extensions->method('getEnabledPlugins')->willReturn([]);
        $extensions->method('getEnabledPackages')->willReturn([]);
        $manager = $this->createMock(ExtensionManager::class);

        $order = [];
        $events = [];
        $core = $this->createMock(CoreDataResetter::class);
        $core->method('getResetCategories')->willReturn([
            new DataResetCategory('members', '회원', '회원', 'bi-people'),
            new DataResetCategory('blocks', '블록', '블록', 'bi-grid'),
        ]);
        $core->method('reset')->willReturnCallback(function (string $category) use (&$order, &$events): DataResetResult {
            $order[] = $category;
            $events[] = "reset:{$category}";
            return new DataResetResult(1);
        });
        $core->method('resetFiles')->willReturnCallback(function (string $category) use (&$events): int {
            $events[] = "files:{$category}";
            return 0;
        });

        $db = $this->createMock(Database::class);
        $db->method('commit')->willReturnCallback(function () use (&$events): bool {
            $events[] = 'commit';
            return true;
        });
        $service = new DataResetService($db, $members, $extensions, $core, $manager);

        $items = $service->getResetItems(3);
        $this->assertSame('core:Core:members', $items[0]['categories'][0]['id']);
        $this->assertSame('core:Core:blocks', $items[0]['categories'][1]['id']);

        $result = $service->resetAll(3, 1, $password, '전체 초기화');
        $this->assertTrue($result->isSuccess());
        $this->assertSame(['blocks', 'members'], $order);
        $this->assertSame(
            ['reset:blocks', 'reset:members', 'commit', 'files:blocks', 'files:members'],
            $events
        );
    }
}
