<?php

namespace Tests\Board\Unit\Service;

use Mublo\Contract\Auth\AuthContextInterface;
use Mublo\Contract\Member\MemberQueryInterface;
use Mublo\Core\Context\Context;
use Mublo\Core\Http\Request;
use Mublo\Packages\Board\Entity\BoardConfig;
use Mublo\Packages\Board\Repository\BoardArticleRepository;
use Mublo\Packages\Board\Repository\BoardConfigRepository;
use Mublo\Packages\Board\Service\BoardArticleService;
use Mublo\Packages\Board\Service\BoardPermissionService;
use Tests\Board\TestCase;

/**
 * 비회원에게도 1일 글쓰기 제한이 걸리는지 검사한다.
 *
 * 제한 설정은 원래 있었지만 `$memberId !== null` 조건 때문에 비회원은 건너뛰고
 * 있었다. 비회원 글쓰기를 연 게시판에서는 그 설정이 있으나 마나였다.
 */
class BoardArticleGuestDailyLimitTest extends TestCase
{
    private BoardArticleRepository $articles;
    private BoardPermissionService $permissions;
    private AuthContextInterface $auth;
    private BoardArticleService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->articles = $this->createMock(BoardArticleRepository::class);
        $this->permissions = $this->createMock(BoardPermissionService::class);
        $this->auth = $this->createMock(AuthContextInterface::class);

        $boards = $this->createMock(BoardConfigRepository::class);
        $boards->method('find')->willReturn(BoardConfig::fromArray([
            'board_id' => 20,
            'domain_id' => 1,
            'group_id' => 1,
            'board_slug' => 'guest',
            'board_name' => 'Guest',
            // 레벨 0(비회원) 은 하루 2건
            'daily_write_limit' => ['0' => 2],
        ]));

        $this->permissions->method('canWrite')->willReturn(true);

        $this->service = new BoardArticleService(
            $this->articles,
            $boards,
            $this->createMock(MemberQueryInterface::class),
            $this->permissions,
            null,
            $this->auth,
        );
    }

    /** 비회원 컨텍스트 — currentUser() 가 null 이라 레벨 0 으로 판정된다. */
    private function guestContext(string $ip = '203.0.113.9'): Context
    {
        $context = $this->createMock(Context::class);
        $context->method('getDomainId')->willReturn(1);
        $context->method('getRequest')->willReturn(
            new Request('POST', '/board/guest/write', [], [], ['REMOTE_ADDR' => $ip])
        );

        return $context;
    }

    public function testGuestOverTheDailyLimitIsRejectedByIp(): void
    {
        $this->articles->method('countTodayByIp')->willReturn(2);
        $this->articles->expects($this->never())->method('create');

        $result = $this->service->create(1, 20, [
            'title' => '스팸',
            'content' => '본문',
            'author_name' => '손님',
            'author_password' => 'pw1234',
        ], $this->guestContext());

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('1일 글쓰기 제한', $result->getMessage());
    }

    public function testGuestUnderTheDailyLimitIsCountedByIpNotByMember(): void
    {
        // 회원 집계를 부르면 비회원 경로가 잘못 배선된 것이다.
        $this->articles->expects($this->never())->method('countTodayByMember');
        $this->articles->expects($this->once())
            ->method('countTodayByIp')
            ->with(20, '203.0.113.9')
            ->willReturn(1);

        $this->service->create(1, 20, [
            'title' => '정상 글',
            'content' => '본문',
            'author_name' => '손님',
            'author_password' => 'pw1234',
        ], $this->guestContext());
    }
}
