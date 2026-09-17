<?php
declare(strict_types=1);

namespace Tests\SnsLogin\Unit;

use Mublo\Contract\Auth\MemberAuthenticatorInterface;
use Mublo\Contract\Member\MemberAccountGatewayInterface;
use Mublo\Contract\Member\MemberRegistrationRequest;
use Mublo\Core\Context\Context;
use Mublo\Core\Http\Request;
use Mublo\Core\Result\Result;
use Mublo\Plugin\SnsLogin\Controller\Front\SnsProfileController;
use Mublo\Plugin\SnsLogin\Service\SnsLoginService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SnsProfileControllerTest extends TestCase
{
    public static function linkOutcomes(): array
    {
        return ['success' => [false], 'link failure' => [true]];
    }

    #[DataProvider('linkOutcomes')]
    public function testFieldsAndLinkParticipateInCoreRegistrationBeforeLogin(bool $linkFails): void
    {
        $pending = [
            'domain_id' => 7, 'provider' => 'kakao', 'uid' => 'provider-123',
            'email' => null, 'profile_image' => null, 'access_token' => 'token',
            'refresh_token' => null, 'expires_in' => null,
        ];
        $login = $this->createMock(SnsLoginService::class);
        $accounts = $this->createMock(MemberAccountGatewayInterface::class);
        $auth = $this->createMock(MemberAuthenticatorInterface::class);
        $login->method('consumePendingSession')->willReturn($pending);
        $accounts->method('validateCustomFields')->willReturn(Result::success());
        $insideRegistration = false;
        $fieldsSaved = false;
        $linkSaved = false;
        $accounts->expects($this->once())->method('create')->willReturnCallback(
            function (MemberRegistrationRequest $request, callable $persistRelated) use (&$insideRegistration): int {
                $this->assertSame(7, $request->domainId);
                $this->assertSame('닉네임', $request->nickname);
                $insideRegistration = true;
                try {
                    $persistRelated(321);
                } finally {
                    $insideRegistration = false;
                }
                return 321;
            }
        );
        $accounts->expects($this->once())->method('saveCustomFields')->with(321, 7, [9 => 'value'])
            ->willReturnCallback(function () use (&$insideRegistration, &$fieldsSaved): void {
                $this->assertTrue($insideRegistration);
                $fieldsSaved = true;
            });
        $login->expects($this->once())->method('linkAccount')->willReturnCallback(
            function (int $id) use (&$insideRegistration, &$fieldsSaved, &$linkSaved, $linkFails): void {
                $this->assertSame(321, $id);
                $this->assertTrue($insideRegistration);
                $this->assertTrue($fieldsSaved);
                if ($linkFails) {
                    throw new \RuntimeException('link failed');
                }
                $linkSaved = true;
            }
        );
        if ($linkFails) {
            $login->expects($this->once())->method('setPendingSession')->with($pending);
            $auth->expects($this->never())->method('loginByMemberId');
        } else {
            $login->expects($this->never())->method('setPendingSession');
            $auth->expects($this->once())->method('loginByMemberId')->willReturnCallback(
                function () use (&$insideRegistration, &$linkSaved): bool {
                    $this->assertFalse($insideRegistration);
                    $this->assertTrue($linkSaved);
                    return true;
                }
            );
        }
        $request = $this->createStub(Request::class);
        $request->method('input')->willReturn(['nickname' => '닉네임', 'fields' => [9 => 'value']]);
        $request->method('getClientIp')->willReturn('127.0.0.1');
        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($request);
        $previous = ini_set('error_log', '/dev/null');
        try {
            (new SnsProfileController($login, $accounts, $auth))->store([], $context);
        } finally {
            ini_set('error_log', (string) $previous);
        }
    }
}
