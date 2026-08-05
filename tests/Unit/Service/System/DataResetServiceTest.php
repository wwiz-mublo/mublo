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

    /**
     * 커밋 이후 로그 기록이 실패해도 초기화는 성공으로 보고해야 한다 (회귀).
     *
     * 예전에는 writeResetLog() 가 트랜잭션 try 안에 있어서, 로그 디렉터리 권한 문제
     * 하나로 "초기화 중 오류가 발생했습니다" 가 떴다 — 데이터는 이미 지워진 뒤였고,
     * 관리자는 실패한 줄 알고 다시 지우려 든다.
     */
    #[Test]
    public function resetsSucceedWhenPostCommitLogWriteFails(): void
    {
        $password = 'reset-password';
        $member = $this->createMock(Member::class);
        $member->method('getPassword')->willReturn(password_hash($password, PASSWORD_DEFAULT));
        $member->method('isSuper')->willReturn(true);
        $member->method('getUserId')->willReturn('super');

        // find(): 1) 비밀번호 검증 2) SUPER 재확인 3) writeResetLog 내부 조회 ← 여기서 실패
        $lookups = 0;
        $members = $this->createMock(MemberRepository::class);
        $members->method('find')->willReturnCallback(function () use (&$lookups, $member) {
            if (++$lookups >= 3) {
                throw new \RuntimeException('로그 기록 실패');
            }
            return $member;
        });

        $extensions = $this->createMock(ExtensionService::class);
        $extensions->method('getEnabledPlugins')->willReturn([]);
        $extensions->method('getEnabledPackages')->willReturn([]);
        $manager = $this->createMock(ExtensionManager::class);

        $core = $this->createMock(CoreDataResetter::class);
        $core->method('getResetCategories')->willReturn([
            new DataResetCategory('blocks', '블록', '블록', 'bi-grid'),
        ]);
        $core->method('reset')->willReturn(new DataResetResult(1));
        $core->method('resetFiles')->willReturn(0);

        $db = $this->createMock(Database::class);
        $db->method('commit')->willReturn(true);
        // 커밋이 끝났으니 되돌릴 트랜잭션도 없다 — 롤백이 불려선 안 된다
        $db->method('inTransaction')->willReturn(false);
        $db->expects($this->never())->method('rollBack');

        $service = new DataResetService($db, $members, $extensions, $core, $manager);

        $all = $service->resetAll(3, 1, $password, '전체 초기화');
        $this->assertTrue($all->isSuccess());
        $this->assertStringContainsString('전체 초기화가 완료되었습니다', $all->getMessage());

        $lookups = 0;
        $category = $service->resetCategory('core:Core:blocks', 3, 1, $password);
        $this->assertTrue($category->isSuccess());
        $this->assertStringContainsString('초기화되었습니다', $category->getMessage());
    }
}
