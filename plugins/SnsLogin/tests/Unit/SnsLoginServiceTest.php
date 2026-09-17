<?php

namespace Tests\SnsLogin\Unit;

use Mublo\Contract\Auth\MemberAuthenticatorInterface;
use Mublo\Contract\Member\MemberAccountGatewayInterface;
use Mublo\Contract\Member\MemberProfile;
use Mublo\Contract\Member\MemberQueryInterface;
use Mublo\Contract\Member\PolicyDocument;
use Mublo\Contract\Member\PolicyQueryInterface;
use Mublo\Contract\Member\MemberRegistrationRequest;
use Mublo\Core\Session\SessionInterface;
use Mublo\Infrastructure\Database\DatabaseException;
use Mublo\Plugin\SnsLogin\Dto\SnsUserInfo;
use Mublo\Plugin\SnsLogin\Entity\SnsAccount;
use Mublo\Plugin\SnsLogin\Repository\SnsAccountRepository;
use Mublo\Plugin\SnsLogin\Service\KoreanNicknameGenerator;
use Mublo\Plugin\SnsLogin\Service\SnsLoginConfigService;
use Mublo\Plugin\SnsLogin\Service\SnsConnectionManager;
use Mublo\Plugin\SnsLogin\Service\SnsLoginService;
use PHPUnit\Framework\TestCase;

class SnsLoginServiceTest extends TestCase
{
    public function testAutoRegisterUsesGeneratedNicknameAndRecordsOriginDomain(): void
    {
        $capturedMember = null;
        $nickname = '고요한별빛수달';

        [$service, $accountRepository, $memberRepository, $authenticator, $generator] = $this->createService();

        $generator->expects($this->once())->method('generate')->willReturn($nickname);
        $memberRepository->method('nicknameExists')->with(7, $nickname, true)->willReturn(false);
        $memberRepository->expects($this->once())
            ->method('create')
            ->willReturnCallback(function (MemberRegistrationRequest $data, callable $persistRelated) use (&$capturedMember): int {
                $capturedMember = $data;
                // SNS 연결은 코어가 여는 트랜잭션 안에서만 저장돼야 한다. 여기서 콜백을
                // 부르지 않으면 아래 accountRepository 기대가 깨진다.
                $persistRelated(321);
                return 321;
            });
        $accountRepository->expects($this->once())->method('create');
        $authenticator->expects($this->once())->method('loginByMemberId')->with(321, '127.0.0.1')->willReturn(true);

        $result = $service->handleCallback(7, $this->snsUser(), ['access_token' => 'token'], 'group-a', '127.0.0.1');

        $this->assertTrue($result->isSuccess());
        $this->assertSame('register', $result->get('action'));
        $this->assertSame($nickname, $capturedMember->nickname);
        // 로컬 비밀번호 없음 — 본인도 모르는 임의 해시를 넣으면 코어가 '비밀번호 있는
        // 회원' 과 구분하지 못하고, 회원은 그 값을 영영 쓸 수 없다.
        $this->assertSame('', $capturedMember->passwordHash);
        $this->assertSame(7, $capturedMember->domainId);
        $this->assertSame(7, $capturedMember->originDomainId);
        $this->assertSame('group-a', $capturedMember->domainGroup);
    }

    public function testAutoRegisterRetriesAfterDatabaseUniqueKeyCollision(): void
    {
        $nicknames = ['고요한별빛수달', '다정한달빛고래'];
        $createCalls = 0;

        [$service, $accountRepository, $memberRepository, $authenticator, $generator] = $this->createService();

        $generator->expects($this->exactly(2))
            ->method('generate')
            ->willReturnCallback(function () use (&$nicknames): string {
                return array_shift($nicknames);
            });
        $memberRepository->method('nicknameExists')->willReturn(false);
        $memberRepository->expects($this->exactly(2))
            ->method('create')
            ->willReturnCallback(function (MemberRegistrationRequest $data, callable $persistRelated) use (&$createCalls): int {
                $createCalls++;
                if ($createCalls === 1) {
                    throw new DatabaseException("Duplicate entry '{$data->nickname}' for key 'uk_domain_nickname'");
                }

                $this->assertSame('다정한달빛고래', $data->nickname);
                $persistRelated(654);
                return 654;
            });
        $accountRepository->expects($this->once())->method('create');
        $authenticator->method('loginByMemberId')->willReturn(true);

        $result = $service->handleCallback(7, $this->snsUser(), ['access_token' => 'token']);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(2, $createCalls);
    }

    public function testAutoRegisterStopsAfterNicknameAttemptsAreExhausted(): void
    {
        [$service, $accountRepository, $memberRepository, $authenticator, $generator] = $this->createService();

        $generator->expects($this->exactly(20))->method('generate')->willReturn('고요한별빛수달');
        $memberRepository->method('nicknameExists')->willReturn(true);
        $memberRepository->expects($this->never())->method('create');
        $accountRepository->expects($this->never())->method('create');
        $authenticator->expects($this->never())->method('loginByMemberId');

        $result = $service->handleCallback(7, $this->snsUser(), ['access_token' => 'token']);

        $this->assertTrue($result->isFailure());
        $this->assertSame('사용 가능한 닉네임을 생성하지 못했습니다. 잠시 후 다시 시도해 주세요.', $result->getMessage());
    }

    public function testConcurrentProviderLinkRollsBackNewMemberAndLogsIntoWinner(): void
    {
        [$service, $accountRepository, $memberRepository, $authenticator, $generator] = $this->createService();

        $linkedAccount = new SnsAccount(
            id: 99,
            domainId: 7,
            memberId: 777,
            provider: 'kakao',
            providerUid: 'provider-user-123',
            providerEmail: null,
            linkedAt: '2026-07-26 21:00:00',
        );
        $member = new MemberProfile(777, 7, 'winner', null, 1, true);

        $accountRepository->expects($this->exactly(2))
            ->method('findByProvider')
            ->with(7, 'kakao', 'provider-user-123')
            ->willReturnOnConsecutiveCalls(null, $linkedAccount);
        $generator->expects($this->once())->method('generate')->willReturn('고요한별빛수달');
        $memberRepository->method('nicknameExists')->willReturn(false);
        $memberRepository->expects($this->once())
            ->method('create')
            ->willReturnCallback(function (MemberRegistrationRequest $data, callable $persistRelated): int {
                $persistRelated(321);
                return 321;
            });
        $accountRepository->expects($this->once())
            ->method('create')
            ->willThrowException(new DatabaseException(
                "Duplicate entry '7-kakao-provider-user-123' for key 'uk_provider_uid'"
            ));
        $memberRepository->expects($this->once())->method('findProfile')->with(777)->willReturn($member);
        $authenticator->expects($this->once())->method('loginByMemberId')->with(777, null)->willReturn(true);

        $result = $service->handleCallback(7, $this->snsUser(), ['access_token' => 'token']);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('login', $result->get('action'));
    }

    public function testAccountLinkFailurePropagatesInsteadOfLoggingTheMemberIn(): void
    {
        // 롤백 자체는 코어 트랜잭션의 책임이라 tests/Unit/Service/Member 에서 검증한다.
        // 여기서는 연결 실패를 삼키고 로그인시키는 일이 없는지만 본다.
        [$service, $accountRepository, $memberRepository, $authenticator, $generator] = $this->createService();

        $generator->method('generate')->willReturn('고요한별빛수달');
        $memberRepository->method('nicknameExists')->willReturn(false);
        $memberRepository->method('create')->willReturnCallback(
            function (MemberRegistrationRequest $data, callable $persistRelated): int {
                $persistRelated(321);
                return 321;
            }
        );
        $accountRepository->method('create')->willThrowException(
            new DatabaseException('Transaction failed: link failed')
        );
        $authenticator->expects($this->never())->method('loginByMemberId');

        try {
            $service->handleCallback(7, $this->snsUser(), ['access_token' => 'token']);
            $this->fail('SNS 계정 연결 실패가 전파되어야 합니다.');
        } catch (DatabaseException $e) {
            $this->assertStringContainsString('link failed', $e->getMessage());
        }
    }

    public function testAutoRegisterAppliesTheConfiguredRegisterLevel(): void
    {
        [$service, , $memberRepository, $authenticator, $generator, $configService] = $this->createService();

        // 관리자가 정한 가입 레벨은 가입 방식과 무관하게 같아야 한다.
        $configService->method('getRegisterLevel')->with(7)->willReturn(6);
        $generator->method('generate')->willReturn('고요한별빛수달');
        $memberRepository->method('nicknameExists')->willReturn(false);
        $captured = null;
        $memberRepository->method('create')->willReturnCallback(
            function (MemberRegistrationRequest $data, callable $persistRelated) use (&$captured): int {
                $captured = $data;
                $persistRelated(321);
                return 321;
            }
        );
        $authenticator->method('loginByMemberId')->willReturn(true);

        $service->handleCallback(7, $this->snsUser(), ['access_token' => 'token']);

        $this->assertSame(6, $captured->levelValue);
    }

    /**
     * 가입 약관을 운영하는 사이트에서는 SNS 가입도 동의를 받아야 한다. 바로 가입이
     * 동의 없이 회원을 만들어 버리면 같은 사이트인데 SNS 회원만 이력이 비어 버린다.
     */
    public function testSignupPoliciesInterruptAutoRegistrationForConsent(): void
    {
        [$service, , $memberRepository, $authenticator, , , $policies] = $this->createService();

        $policies->method('registerDocuments')->willReturn([$this->policyDocument()]);
        $memberRepository->expects($this->never())->method('create');
        $authenticator->expects($this->never())->method('loginByMemberId');

        $result = $service->handleCallback(7, $this->snsUser(), ['access_token' => 'token']);

        $this->assertSame('agreement_needed', $result->get('action'));
    }

    /** 동의를 마치면 번호가 코어로 넘어가 증빙이 된다 — 스냅샷은 코어가 만든다. */
    public function testAgreedPolicyIdsReachTheCoreRegistration(): void
    {
        [$service, , $memberRepository, $authenticator, $generator, , $policies] = $this->createService();

        $policies->method('registerDocuments')->willReturn([$this->policyDocument()]);
        $generator->method('generate')->willReturn('고요한별빛수달');
        $memberRepository->method('nicknameExists')->willReturn(false);
        $captured = null;
        $memberRepository->method('create')->willReturnCallback(
            function (MemberRegistrationRequest $data, callable $persistRelated) use (&$captured): int {
                $captured = $data;
                $persistRelated(321);
                return 321;
            }
        );
        $authenticator->method('loginByMemberId')->willReturn(true);

        $service->handleCallback(7, $this->snsUser(), ['access_token' => 'token'], null, '127.0.0.1', 'UA/1.0');
        $service->rememberAgreements([11, 22]);
        $result = $service->continueAfterAgreement('127.0.0.1', 'UA/1.0');

        $this->assertSame('register', $result->get('action'));
        $this->assertSame([11, 22], $captured->agreedPolicyIds);
        // 동의 기록에도 코어 가입 폼과 같은 접속 정보를 남긴다.
        $this->assertSame('127.0.0.1', $captured->ipAddress);
        $this->assertSame('UA/1.0', $captured->userAgent);
    }

    /** 약관을 운영하지 않는 사이트는 종전처럼 한 번에 가입한다. */
    public function testSiteWithoutSignupPoliciesRegistersInOneStep(): void
    {
        [$service, , $memberRepository, $authenticator, $generator] = $this->createService();

        $generator->method('generate')->willReturn('고요한별빛수달');
        $memberRepository->method('nicknameExists')->willReturn(false);
        $memberRepository->method('create')->willReturnCallback(
            function (MemberRegistrationRequest $data, callable $persistRelated): int {
                $persistRelated(321);
                return 321;
            }
        );
        $authenticator->method('loginByMemberId')->willReturn(true);

        $this->assertSame('register', $service->handleCallback(7, $this->snsUser(), ['access_token' => 'token'])->get('action'));
    }

    private function policyDocument(): PolicyDocument
    {
        return new PolicyDocument(
            policyId: 11,
            revisionId: 1,
            domainId: 7,
            version: '1.0',
            title: '이용약관',
            content: '내용',
            contentHash: 'hash',
            required: true,
            active: true,
            createdAt: '2026-09-17 10:00:00',
        );
    }

    public function testExistingLinkedAccountLoginDoesNotAnnounceRegistration(): void
    {
        [$service, $accountRepository, $memberRepository, $authenticator] = $this->createService();

        $accountRepository->method('findByProvider')->willReturn(new SnsAccount(
            id: 5,
            domainId: 7,
            memberId: 500,
            provider: 'kakao',
            providerUid: 'provider-user-123',
            providerEmail: null,
            linkedAt: '2026-07-26 21:00:00',
        ));
        $memberRepository->method('findProfile')->with(500)->willReturn(new MemberProfile(500, 7, 'old', null, 1, true));
        $memberRepository->expects($this->never())->method('create');
        $authenticator->method('loginByMemberId')->willReturn(true);

        $result = $service->handleCallback(7, $this->snsUser(), ['access_token' => 'token']);

        $this->assertSame('login', $result->get('action'));
    }

    /**
     * @return array{SnsLoginService, SnsAccountRepository&\PHPUnit\Framework\MockObject\MockObject, MemberAccountGatewayInterface&MemberQueryInterface&\PHPUnit\Framework\MockObject\MockObject, MemberAuthenticatorInterface&\PHPUnit\Framework\MockObject\MockObject, KoreanNicknameGenerator&\PHPUnit\Framework\MockObject\MockObject, SnsLoginConfigService&\PHPUnit\Framework\MockObject\MockObject}
     */
    private function createService(): array
    {
        $accountRepository = $this->createMock(SnsAccountRepository::class);
        $memberRepository = $this->createMockForIntersectionOfInterfaces([
            MemberAccountGatewayInterface::class,
            MemberQueryInterface::class,
        ]);
        $authenticator = $this->createMock(MemberAuthenticatorInterface::class);
        $configService = $this->createMock(SnsLoginConfigService::class);
        // 가입 재료는 세션을 거쳐 흐른다(콜백 → 약관 → 가입). 값을 보관하지 않는
        // 목으로는 그 흐름이 끊겨, 서비스가 아니라 목을 검증하게 된다.
        $sessionStore = [];
        $session = $this->createStub(SessionInterface::class);
        $session->method('set')->willReturnCallback(function (string $k, mixed $v) use (&$sessionStore): void {
            $sessionStore[$k] = $v;
        });
        // 화살표 함수는 값으로 캡처한다 — 참조로 받아야 set 이 넣은 값이 보인다.
        $session->method('get')->willReturnCallback(
            function (string $k, mixed $d = null) use (&$sessionStore): mixed {
                return $sessionStore[$k] ?? $d;
            }
        );
        $session->method('remove')->willReturnCallback(function (string $k) use (&$sessionStore): void {
            unset($sessionStore[$k]);
        });
        $generator = $this->createMock(KoreanNicknameGenerator::class);
        $connectionManager = $this->createMock(SnsConnectionManager::class);
        // 기본은 약관 없는 사이트 — 목의 배열 기본 반환값이 그 상태다.
        // 여기서 미리 스텁하면 테스트별 재정의가 먹히지 않는다(첫 매처가 이긴다).
        $policies = $this->createMock(PolicyQueryInterface::class);
        $configService->method('getConfig')->willReturn([
            'auto_register' => true,
            'register_level' => 1,
        ]);


        return [
            new SnsLoginService(
                $accountRepository,
                $memberRepository,
                $memberRepository,
                $authenticator,
                $configService,
                $session,
                $generator,
                $connectionManager,
                // 대부분의 테스트는 약관과 무관하다 — 약관을 운영하지 않는 사이트로 둔다.
                $policies,
            ),
            $accountRepository,
            $memberRepository,
            $authenticator,
            $generator,
            $configService,
            $policies,
        ];
    }

    private function snsUser(): SnsUserInfo
    {
        return new SnsUserInfo('kakao', 'provider-user-123', null, '카카오닉네임', null);
    }
}
