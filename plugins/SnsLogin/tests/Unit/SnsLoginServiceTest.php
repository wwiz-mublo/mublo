<?php

namespace Tests\SnsLogin\Unit;

use Mublo\Contract\Auth\MemberAuthenticatorInterface;
use Mublo\Contract\Member\MemberAccountGatewayInterface;
use Mublo\Contract\Member\MemberProfile;
use Mublo\Contract\Member\MemberQueryInterface;
use Mublo\Contract\Member\MemberRegistrationRequest;
use Mublo\Core\Session\SessionInterface;
use Mublo\Infrastructure\Database\Database;
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

        [$service, $accountRepository, $memberRepository, $authenticator, $generator, $database] = $this->createService();

        $generator->expects($this->once())->method('generate')->willReturn($nickname);
        $memberRepository->method('nicknameExists')->with(7, $nickname, true)->willReturn(false);
        $memberRepository->expects($this->once())
            ->method('create')
            ->willReturnCallback(function (MemberRegistrationRequest $data) use (&$capturedMember, $database): int {
                $this->assertTrue($database->inTransaction());
                $capturedMember = $data;
                return 321;
            });
        $accountRepository->expects($this->once())
            ->method('create')
            ->willReturnCallback(function () use ($database): void {
                $this->assertTrue($database->inTransaction());
            });
        $authenticator->expects($this->once())->method('loginByMemberId')->with(321, '127.0.0.1')->willReturn(true);

        $result = $service->handleCallback(7, $this->snsUser(), ['access_token' => 'token'], 'group-a', '127.0.0.1');

        $this->assertTrue($result->isSuccess());
        $this->assertSame('register', $result->get('action'));
        $this->assertSame($nickname, $capturedMember->nickname);
        $this->assertSame(7, $capturedMember->domainId);
        $this->assertSame(7, $capturedMember->originDomainId);
        $this->assertSame('group-a', $capturedMember->domainGroup);
        $this->assertFalse($database->inTransaction());
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
            ->willReturnCallback(function (MemberRegistrationRequest $data) use (&$createCalls): int {
                $createCalls++;
                if ($createCalls === 1) {
                    throw new DatabaseException("Duplicate entry '{$data->nickname}' for key 'uk_domain_nickname'");
                }

                $this->assertSame('다정한달빛고래', $data->nickname);
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
        [$service, $accountRepository, $memberRepository, $authenticator, $generator, $database] = $this->createService();

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
            ->willReturnCallback(function () use ($database): int {
                $this->assertTrue($database->inTransaction());
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
        $this->assertFalse($database->inTransaction());
    }

    public function testAccountLinkFailureRollsBackAutoRegisterTransaction(): void
    {
        [$service, $accountRepository, $memberRepository, , $generator, $database] = $this->createService();

        $generator->method('generate')->willReturn('고요한별빛수달');
        $memberRepository->method('nicknameExists')->willReturn(false);
        $memberRepository->method('create')->willReturnCallback(function () use ($database): int {
            $this->assertTrue($database->inTransaction());
            return 321;
        });
        $accountRepository->method('create')->willThrowException(new \RuntimeException('link failed'));

        try {
            $service->handleCallback(7, $this->snsUser(), ['access_token' => 'token']);
            $this->fail('SNS 계정 연결 실패가 전파되어야 합니다.');
        } catch (DatabaseException $e) {
            $this->assertStringContainsString('link failed', $e->getMessage());
        }

        $this->assertFalse($database->inTransaction());
    }

    /**
     * @return array{SnsLoginService, SnsAccountRepository&\PHPUnit\Framework\MockObject\MockObject, MemberAccountGatewayInterface&MemberQueryInterface&\PHPUnit\Framework\MockObject\MockObject, MemberAuthenticatorInterface&\PHPUnit\Framework\MockObject\MockObject, KoreanNicknameGenerator&\PHPUnit\Framework\MockObject\MockObject, Database&\PHPUnit\Framework\MockObject\MockObject}
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
        $session = $this->createMock(SessionInterface::class);
        $generator = $this->createMock(KoreanNicknameGenerator::class);
        $connectionManager = $this->createMock(SnsConnectionManager::class);
        $database = $this->createMock(Database::class);
        $inTransaction = false;

        $database->method('transaction')->willReturnCallback(
            function (callable $callback) use (&$inTransaction): mixed {
                $inTransaction = true;

                try {
                    $result = $callback();
                    $inTransaction = false;
                    return $result;
                } catch (\Throwable $e) {
                    $inTransaction = false;
                    throw new DatabaseException('Transaction failed: ' . $e->getMessage(), 0, $e);
                }
            }
        );
        $database->method('inTransaction')->willReturnCallback(
            function () use (&$inTransaction): bool {
                return $inTransaction;
            }
        );

        $configService->method('getConfig')->willReturn([
            'auto_register' => true,
            'register_level' => 1,
        ]);

        return [
            new SnsLoginService(
                $accountRepository,
                $memberRepository,
                $memberRepository,
                $database,
                $authenticator,
                $configService,
                $session,
                $generator,
                $connectionManager,
            ),
            $accountRepository,
            $memberRepository,
            $authenticator,
            $generator,
            $database,
        ];
    }

    private function snsUser(): SnsUserInfo
    {
        return new SnsUserInfo('kakao', 'provider-user-123', null, '카카오닉네임', null);
    }
}
