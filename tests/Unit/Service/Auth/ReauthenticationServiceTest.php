<?php
declare(strict_types=1);

namespace Tests\Unit\Service\Auth;

use Mublo\Core\Session\SessionInterface;
use Mublo\Service\Auth\ReauthenticationService;
use PHPUnit\Framework\TestCase;

final class ReauthenticationServiceTest extends TestCase
{
    private ReauthenticationService $service;
    /** @var array<string, mixed> */
    private array $store = [];

    protected function setUp(): void
    {
        $session = $this->createStub(SessionInterface::class);
        $session->method('set')->willReturnCallback(function (string $key, mixed $value): void {
            $this->store[$key] = $value;
        });
        $session->method('get')->willReturnCallback(
            fn (string $key, mixed $default = null): mixed => $this->store[$key] ?? $default
        );

        $this->service = new ReauthenticationService($session);
    }

    public function testUnconfirmedMemberIsNotTreatedAsVerified(): void
    {
        $this->assertFalse($this->service->isConfirmed(7));
    }

    public function testConfirmationHoldsForTheMemberItWasIssuedFor(): void
    {
        $this->service->confirm(7);

        $this->assertTrue($this->service->isConfirmed(7));
    }

    /**
     * 같은 세션으로 다른 계정에 들어간 사람이 앞사람의 확인을 물려받으면,
     * 비밀번호를 대지 않고 남의 계정을 바꾸거나 지울 수 있다.
     */
    public function testConfirmationDoesNotCarryOverToAnotherMember(): void
    {
        $this->service->confirm(7);

        $this->assertFalse($this->service->isConfirmed(8));
    }

    /**
     * 자리를 비운 사이 남이 쓰는 것을 막는 것이 유효시간의 목적이다.
     * 만료 시각을 지난 확인은 남아 있어도 통과시키지 않는다.
     */
    public function testExpiredConfirmationIsRejected(): void
    {
        $this->service->confirm(7);
        $this->store['reauth_confirmed']['expires_at'] = time() - 1;

        $this->assertFalse($this->service->isConfirmed(7));
    }

    public function testConfirmationSurvivesUntilItsDeadline(): void
    {
        $this->service->confirm(7);
        $this->store['reauth_confirmed']['expires_at'] = time() + 1;

        $this->assertTrue($this->service->isConfirmed(7));
    }

    /**
     * 세션에 남은 값이 손상됐거나 형태가 다르면 "확인되지 않음"으로 본다.
     * 판단할 수 없을 때 통과시키면 민감한 작업이 열린다.
     */
    public function testMalformedSessionValueIsNotTreatedAsConfirmation(): void
    {
        foreach (['yes', 1, ['member_id' => 7], []] as $broken) {
            $this->store['reauth_confirmed'] = $broken;

            $this->assertFalse($this->service->isConfirmed(7));
        }
    }
}
