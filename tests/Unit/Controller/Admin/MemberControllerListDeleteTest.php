<?php

namespace Tests\Unit\Controller\Admin;

use PHPUnit\Framework\TestCase;
use Mublo\Controller\Admin\MemberController;
use Mublo\Service\Member\MemberService;
use Mublo\Service\Member\MemberAdminService;
use Mublo\Service\Member\MemberFieldService;
use Mublo\Service\Member\MemberLevelService;
use Mublo\Service\Auth\AuthService;
use Mublo\Service\Balance\BalanceManager;
use Mublo\Service\Domain\DomainService;
use Mublo\Core\Context\Context;
use Mublo\Core\Http\Request;
use Mublo\Core\Result\Result;

/**
 * listDelete 벌크 삭제가 단일 delete·listModify 와 동일하게 admin_id 를 실어
 * MemberAdminService::delete 에 전달함을 고정한다(누락 시 도메인 주인이 하위
 * 관리자를 벌크 삭제 못 하는 fail-closed 불일치).
 */
class MemberControllerListDeleteTest extends TestCase
{
    public function testListDeletePassesAdminIdToService(): void
    {
        $memberAdminService = $this->createMock(MemberAdminService::class);
        $authService = $this->createMock(AuthService::class);
        $authService->method('user')->willReturn(['member_id' => 5, 'is_super' => false]);

        $captured = [];
        $memberAdminService->method('delete')
            ->willReturnCallback(function (int $memberId, array $ctx) use (&$captured): Result {
                $captured[] = $ctx;
                return Result::success('ok');
            });

        $controller = new MemberController(
            $this->createMock(MemberService::class),
            $memberAdminService,
            $this->createMock(MemberFieldService::class),
            $this->createMock(MemberLevelService::class),
            $authService,
            $this->createMock(BalanceManager::class),
            $this->createMock(DomainService::class),
        );

        $request = $this->createMock(Request::class);
        $request->method('input')->with('chk')->willReturn([10, 11]);

        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getDomainId')->willReturn(1);

        $controller->listDelete([], $context);

        $this->assertCount(2, $captured, '선택된 두 회원 모두 delete 호출');
        foreach ($captured as $ctx) {
            $this->assertArrayHasKey('admin_id', $ctx);
            $this->assertSame(5, $ctx['admin_id']);
            $this->assertSame(1, $ctx['admin_domain_id']);
        }
    }
}
