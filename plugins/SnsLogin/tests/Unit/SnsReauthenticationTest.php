<?php
declare(strict_types=1);

namespace Tests\SnsLogin\Unit;

use Mublo\Contract\Auth\AuthContextInterface;
use Mublo\Contract\Auth\ReauthenticationInterface;
use Mublo\Core\Context\Context;
use Mublo\Core\Http\Request;
use Mublo\Core\Response\RedirectResponse;
use Mublo\Core\Session\SessionInterface;
use Mublo\Infrastructure\Log\Logger;
use Mublo\Plugin\SnsLogin\Contract\SnsProviderInterface;
use Mublo\Plugin\SnsLogin\Controller\Front\SnsAuthController;
use Mublo\Plugin\SnsLogin\Dto\SnsUserInfo;
use Mublo\Plugin\SnsLogin\Entity\SnsAccount;
use Mublo\Plugin\SnsLogin\Repository\SnsAccountRepository;
use Mublo\Plugin\SnsLogin\Service\SnsLoginConfigService;
use Mublo\Plugin\SnsLogin\Service\SnsLoginService;
use Mublo\Plugin\SnsLogin\SnsProviderRegistry;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * 제공자 재인증으로 본인 확인을 받는 흐름.
 *
 * 이 확인 하나로 비밀번호 설정과 탈퇴가 열리므로, 남의 계정 앞으로 확인이 새어 나가면
 * 계정을 통째로 넘기는 것과 같다. 세 주체가 모두 같은 회원을 가리킬 때만 통과한다 —
 * 왕복을 시작한 회원, 지금 로그인한 회원, 방금 인증한 SNS 계정의 주인.
 */
final class SnsReauthenticationTest extends TestCase
{
    private SnsAccountRepository&MockObject $accounts;
    private ReauthenticationInterface&MockObject $reauth;
    private AuthContextInterface&MockObject $auth;
    private SessionInterface&MockObject $session;
    private SnsAuthController $controller;
    /** @var array<string, mixed> */
    private array $store = [];

    protected function setUp(): void
    {
        $this->accounts = $this->createMock(SnsAccountRepository::class);
        $this->reauth = $this->createMock(ReauthenticationInterface::class);
        $this->auth = $this->createMock(AuthContextInterface::class);

        $this->session = $this->createMock(SessionInterface::class);
        $this->session->method('set')->willReturnCallback(function (string $k, mixed $v): void {
            $this->store[$k] = $v;
        });
        $this->session->method('get')->willReturnCallback(
            fn (string $k, mixed $d = null): mixed => $this->store[$k] ?? $d
        );
        $this->session->method('remove')->willReturnCallback(function (string $k): void {
            unset($this->store[$k]);
        });

        $provider = $this->createStub(SnsProviderInterface::class);
        $provider->method('getName')->willReturn('kakao');
        $provider->method('exchangeCode')->willReturn(['access_token' => 'token']);
        $provider->method('getUserInfo')->willReturn(new SnsUserInfo('kakao', 'uid-1', null, '닉', null));
        $registry = new SnsProviderRegistry();
        $registry->register($provider);

        $config = $this->createStub(SnsLoginConfigService::class);
        $config->method('getEnabledMap')->willReturn(['kakao' => true]);

        $this->controller = new SnsAuthController(
            $registry,
            $this->createStub(SnsLoginService::class),
            $config,
            $this->session,
            $this->createStub(Logger::class),
            $this->auth,
            $this->accounts,
            $this->reauth,
        );
    }

    public function testStartIsRefusedForAProviderTheMemberHasNotLinked(): void
    {
        $this->auth->method('id')->willReturn(7);
        $this->accounts->method('findByMemberAndProvider')->with(7, 'kakao')->willReturn(null);
        $this->reauth->expects($this->never())->method('confirm');

        $response = $this->controller->startReauthentication(['provider' => 'kakao'], $this->context());

        $this->assertStringContainsString('sns_not_linked', $this->target($response));
    }

    public function testOwnAccountConfirmsTheMember(): void
    {
        $this->beginRoundTrip(startedBy: 7, loggedInAs: 7);
        $this->accounts->method('findByProvider')->willReturn($this->account(memberId: 7));
        $this->reauth->expects($this->once())->method('confirm')->with(7);

        $response = $this->controller->callback(['provider' => 'kakao'], $this->context('code', 'state-1'));

        $this->assertSame('/mypage/profile', $this->target($response));
    }

    /**
     * 남의 카카오로 로그인해 내 계정의 확인을 받아내는 길을 막는다.
     */
    public function testAnUnrelatedProviderAccountDoesNotConfirm(): void
    {
        $this->beginRoundTrip(startedBy: 7, loggedInAs: 7);
        $this->accounts->method('findByProvider')->willReturn($this->account(memberId: 99));
        $this->reauth->expects($this->never())->method('confirm');

        $response = $this->controller->callback(['provider' => 'kakao'], $this->context('code', 'state-1'));

        $this->assertStringContainsString('reauth_account_mismatch', $this->target($response));
    }

    public function testAConnectionUnknownToThisSiteDoesNotConfirm(): void
    {
        $this->beginRoundTrip(startedBy: 7, loggedInAs: 7);
        $this->accounts->method('findByProvider')->willReturn(null);
        $this->reauth->expects($this->never())->method('confirm');

        $response = $this->controller->callback(['provider' => 'kakao'], $this->context('code', 'state-1'));

        $this->assertStringContainsString('reauth_account_mismatch', $this->target($response));
    }

    /**
     * 왕복 도중 다른 계정으로 로그인했다면 그 확인은 시작한 사람의 것이 아니다.
     */
    public function testAccountSwitchDuringTheRoundTripDoesNotConfirm(): void
    {
        $this->beginRoundTrip(startedBy: 7, loggedInAs: 8);
        $this->accounts->method('findByProvider')->willReturn($this->account(memberId: 8));
        $this->reauth->expects($this->never())->method('confirm');

        $response = $this->controller->callback(['provider' => 'kakao'], $this->context('code', 'state-1'));

        $this->assertStringContainsString('reauth_session_changed', $this->target($response));
    }

    public function testLoggedOutSessionDoesNotConfirm(): void
    {
        $this->beginRoundTrip(startedBy: 7, loggedInAs: null);
        $this->reauth->expects($this->never())->method('confirm');

        $response = $this->controller->callback(['provider' => 'kakao'], $this->context('code', 'state-1'));

        $this->assertStringContainsString('reauth_session_changed', $this->target($response));
    }

    /**
     * state 가 어긋난 왕복은 제3자가 시작시킨 것일 수 있다.
     */
    public function testMismatchedStateDoesNotConfirm(): void
    {
        $this->beginRoundTrip(startedBy: 7, loggedInAs: 7);
        $this->reauth->expects($this->never())->method('confirm');

        $response = $this->controller->callback(['provider' => 'kakao'], $this->context('code', '다른-state'));

        $this->assertStringContainsString('invalid_state', $this->target($response));
    }

    /**
     * 가입이 여러 단계로 나뉘면(약관 동의·프로필 입력) 콜백에서 끝나지 않는다.
     * 그 자리에서 돌아갈 주소를 지우면 마지막 단계가 그 자리를 잃어, 특정 글에서
     * 로그인한 사람이 원래 보던 곳으로 돌아가지 못한다.
     */
    public function testReturnAddressSurvivesAMultiStepSignup(): void
    {
        $this->store['sns_login_redirect'] = '/board/notice/12';

        $this->assertSame(
            '/board/notice/12',
            SnsAuthController::consumeRedirectFrom($this->session)
        );
        // 한 번 쓰면 사라진다 — 다음 로그인까지 남으면 엉뚱한 곳으로 보낸다.
        $this->assertSame('/', SnsAuthController::consumeRedirectFrom($this->session));
    }

    /** 외부 주소로 보내는 열린 리다이렉트를 막는다. */
    public function testOffsiteReturnAddressIsRefused(): void
    {
        foreach (['//evil.example.com', 'https://evil.example.com', ''] as $hostile) {
            $this->store['sns_login_redirect'] = $hostile;

            $this->assertSame('/', SnsAuthController::consumeRedirectFrom($this->session));
        }
    }

    private function beginRoundTrip(int $startedBy, ?int $loggedInAs): void
    {
        $this->store['sns_oauth_state'] = [
            'token' => 'state-1',
            'expires_at' => time() + 600,
            'intent' => 'reauth',
            'member_id' => $startedBy,
        ];
        $this->store['sns_login_redirect'] = '/mypage/profile';
        $this->auth->method('id')->willReturn($loggedInAs);
    }

    private function account(int $memberId): SnsAccount
    {
        return new SnsAccount(
            id: 1,
            domainId: 1,
            memberId: $memberId,
            provider: 'kakao',
            providerUid: 'uid-1',
            providerEmail: null,
            linkedAt: '2026-09-17 10:00:00',
        );
    }

    private function context(string $code = '', string $state = ''): Context
    {
        $request = $this->createStub(Request::class);
        $request->method('get')->willReturnCallback(
            fn (string $key, mixed $default = null): mixed => match ($key) {
                'code' => $code,
                'state' => $state,
                default => $default,
            }
        );

        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getDomainId')->willReturn(1);

        return $context;
    }

    private function target(mixed $response): string
    {
        $this->assertInstanceOf(RedirectResponse::class, $response);

        return $response->getLocation();
    }
}
