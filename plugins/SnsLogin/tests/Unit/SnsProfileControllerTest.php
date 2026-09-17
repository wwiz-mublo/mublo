<?php
declare(strict_types=1);

namespace Tests\SnsLogin\Unit;

use Mublo\Contract\Auth\MemberAuthenticatorInterface;
use Mublo\Contract\Member\MemberAccountGatewayInterface;
use Mublo\Contract\Member\MemberRegistrationRequest;
use Mublo\Core\Context\Context;
use Mublo\Core\Http\Request;
use Mublo\Core\Result\Result;
use Mublo\Infrastructure\Database\DatabaseException;
use Mublo\Plugin\SnsLogin\Controller\Front\SnsProfileController;
use Mublo\Plugin\SnsLogin\Service\SnsLoginConfigService;
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
        $config = $this->createMock(SnsLoginConfigService::class);
        $config->method('getRegisterLevel')->with(7)->willReturn(4);
        $login->method('consumePendingSession')->willReturn($pending);
        $login->method('generateCredentials')->with('kakao', 'provider-123')
            ->willReturn(['user_id' => 'sns_kakao_provider_ab12', 'password_hash' => 'throwaway-hash']);
        $accounts->method('validateCustomFields')->willReturn(Result::success());
        $insideRegistration = false;
        $fieldsSaved = false;
        $linkSaved = false;
        $accounts->expects($this->once())->method('create')->willReturnCallback(
            function (MemberRegistrationRequest $request, callable $persistRelated) use (&$insideRegistration): int {
                $this->assertSame(7, $request->domainId);
                $this->assertSame('닉네임', $request->nickname);
                // 자격은 두 가입 경로가 공유하는 생성기에서 온다.
                $this->assertSame('sns_kakao_provider_ab12', $request->userId);
                $this->assertSame('throwaway-hash', $request->passwordHash);
                // 관리자가 정한 가입 레벨이 가입 방식에 따라 갈리면 안 된다.
                $this->assertSame(4, $request->levelValue);
                $this->assertSame(7, $request->originDomainId);
                $insideRegistration = true;
                try {
                    $persistRelated(321);
                } catch (\Throwable $e) {
                    // 코어 Database::transaction 과 같은 재포장 — 롤백 후 DatabaseException 으로 올린다.
                    throw new DatabaseException('Transaction failed: ' . $e->getMessage(), 0, $e);
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
            (new SnsProfileController($login, $accounts, $auth, $config))->store([], $context);
        } finally {
            ini_set('error_log', (string) $previous);
        }
    }

    public function testExternalTransactionMisuseIsNotHiddenAsARetryableError(): void
    {
        $pending = [
            'domain_id' => 7, 'provider' => 'kakao', 'uid' => 'provider-123',
            'email' => null, 'profile_image' => null, 'access_token' => 'token',
            'refresh_token' => null, 'expires_in' => null,
        ];
        $login = $this->createMock(SnsLoginService::class);
        $accounts = $this->createMock(MemberAccountGatewayInterface::class);
        $auth = $this->createMock(MemberAuthenticatorInterface::class);
        $config = $this->createMock(SnsLoginConfigService::class);
        $config->method('getRegisterLevel')->with(7)->willReturn(4);
        $login->method('consumePendingSession')->willReturn($pending);
        $login->method('generateCredentials')
            ->willReturn(['user_id' => 'sns_kakao_provider_ab12', 'password_hash' => 'throwaway-hash']);
        $accounts->method('validateCustomFields')->willReturn(Result::success());
        // 코어가 외부 트랜잭션을 거부할 때 나는 예외. 프로그래밍 오류이므로 일반 오류로
        // 감싸 재시도를 권하면 사용자는 같은 실패를 반복한다.
        $accounts->method('create')->willThrowException(
            new \LogicException('회원 가입은 코어가 소유한 트랜잭션에서 실행해야 합니다.')
        );
        $login->expects($this->never())->method('setPendingSession');
        $auth->expects($this->never())->method('loginByMemberId');
        $request = $this->createStub(Request::class);
        $request->method('input')->willReturn(['nickname' => '닉네임']);
        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($request);

        $this->expectException(\LogicException::class);
        (new SnsProfileController($login, $accounts, $auth, $config))->store([], $context);
    }
}
