<?php
namespace Tests\SnsLogin\Unit;

use Mublo\Contract\Member\MemberAccountGatewayInterface;
use Mublo\Infrastructure\Log\Logger;
use Mublo\Plugin\SnsLogin\Contract\RevocableSnsProviderInterface;
use Mublo\Plugin\SnsLogin\Entity\SnsAccount;
use Mublo\Plugin\SnsLogin\Repository\SnsAccountRepository;
use Mublo\Plugin\SnsLogin\Service\SnsConnectionManager;
use Mublo\Plugin\SnsLogin\SnsProviderRegistry;
use PHPUnit\Framework\TestCase;

class SnsConnectionManagerTest extends TestCase
{
    public function testCleanupDeletesOnlyConnectionsThatWereActuallyRevoked(): void
    {
        $revoked = $this->account(1, 'kakao');
        $stuck   = $this->account(2, 'google');

        $repository = $this->createMock(SnsAccountRepository::class);
        $repository->method('findByMember')->with(10)->willReturn([$revoked, $stuck]);
        // 탈퇴 정리는 폐기 성공 여부와 무관하게 로컬 행을 지운다. provider_uid 가 유니크
        // 키라, 남은 행은 그 사람이 같은 SNS 로 다시 가입하는 길을 영구히 막는다.
        $deleted = [];
        $repository->expects($this->exactly(2))->method('deleteById')
            ->willReturnCallback(function (int $id) use (&$deleted): bool {
                $deleted[] = $id;
                return true;
            });

        $registry = new SnsProviderRegistry();
        $registry->register($this->provider('kakao', '카카오'));
        $registry->register($this->provider('google', 'Google', new \RuntimeException('provider unavailable')));

        $manager = new SnsConnectionManager($repository, $registry, $this->createMock(Logger::class), $this->memberAccounts());

        $this->assertSame(['revoked' => 1, 'failed' => 1], $manager->revokeAndCleanupForMember(10));
        $this->assertSame([1, 2], $deleted);
    }

    public function testCleanupSurvivesUnusableProviderInsteadOfThrowing(): void
    {
        $account = $this->account(1, 'kakao');

        $repository = $this->createMock(SnsAccountRepository::class);
        $repository->method('findByMember')->willReturn([$account]);
        // 폐기하지 못했더라도 떠난 회원의 행은 남기지 않는다(재가입을 막지 않기 위해).
        $repository->expects($this->once())->method('deleteById')->with(1, 7)->willReturn(true);

        // 관리자가 client_id 를 지우면 제공자가 등록조차 되지 않는다.
        // 그래도 탈퇴 정리 자체는 끝나야 한다 (탈퇴는 이미 확정됐다).
        $manager = new SnsConnectionManager($repository, new SnsProviderRegistry(), $this->createMock(Logger::class), $this->memberAccounts());

        $this->assertSame(['revoked' => 0, 'failed' => 1], $manager->revokeAndCleanupForMember(10));
    }

    public function testCleanupReportsNothingWhenStorageIsUnreachable(): void
    {
        $repository = $this->createMock(SnsAccountRepository::class);
        $repository->method('findByMember')->willThrowException(new \RuntimeException('table missing'));

        $manager = new SnsConnectionManager($repository, new SnsProviderRegistry(), $this->createMock(Logger::class), $this->memberAccounts());

        $this->assertSame(['revoked' => 0, 'failed' => 0], $manager->revokeAndCleanupForMember(10));
    }

    public function testDetachedRevocationNeverTouchesAlreadyCascadedRows(): void
    {
        $repository = $this->createMock(SnsAccountRepository::class);
        // 하드 삭제 뒤라 행이 없다 — 쓰기를 시도하면 유령 UPDATE 가 된다.
        $repository->expects($this->never())->method('markRevokeFailed');
        $repository->expects($this->never())->method('deleteById');

        $registry = new SnsProviderRegistry();
        $registry->register($this->provider('naver', '네이버'));
        $registry->register($this->provider('google', 'Google', new \RuntimeException('down')));

        $manager = new SnsConnectionManager($repository, $registry, $this->createMock(Logger::class), $this->memberAccounts());

        $summary = $manager->revokeDetachedAccounts([
            $this->account(1, 'naver'),
            $this->account(2, 'google'),
        ]);

        $this->assertSame(['revoked' => 1, 'failed' => 1], $summary);
    }

    public function testExplicitUnlinkRevokesProviderBeforeDeletingLocalRecord(): void
    {
        $account = $this->account(1, 'naver');
        $calls = [];
        $repository = $this->createMock(SnsAccountRepository::class);
        $repository->method('findByMemberAndProvider')->with(10, 'naver')->willReturn($account);
        $repository->method('deleteByMemberAndProvider')->willReturnCallback(
            function () use (&$calls): bool {
                $calls[] = 'delete';
                return true;
            },
        );

        $provider = $this->createMock(RevocableSnsProviderInterface::class);
        $provider->method('getName')->willReturn('naver');
        $provider->method('getLabel')->willReturn('네이버');
        $provider->method('revokeConnection')->willReturnCallback(
            function () use (&$calls): void {
                $calls[] = 'revoke';
            },
        );

        $registry = new SnsProviderRegistry();
        $registry->register($provider);
        $manager = new SnsConnectionManager($repository, $registry, $this->createMock(Logger::class), $this->memberAccounts());

        $this->assertTrue($manager->revokeAndDelete(10, 'naver')->isSuccess());
        $this->assertSame(['revoke', 'delete'], $calls);
    }

    public function testFailedUnlinkLeavesRetryMarkerOnTheRow(): void
    {
        $account = $this->account(4, 'kakao');
        $repository = $this->createMock(SnsAccountRepository::class);
        $repository->method('findByIdAndDomain')->with(4, 7)->willReturn($account);
        $repository->expects($this->never())->method('deleteById');
        $repository->expects($this->once())->method('markRevokeFailed')
            ->with(4, $this->stringContains('카카오'));

        $registry = new SnsProviderRegistry();
        $registry->register($this->provider('kakao', '카카오', new \RuntimeException('boom')));

        $manager = new SnsConnectionManager($repository, $registry, $this->createMock(Logger::class), $this->memberAccounts());

        $this->assertTrue($manager->revokeAndDeleteById(4, 7)->isFailure());
    }

    private function provider(string $name, string $label, ?\Throwable $failure = null): RevocableSnsProviderInterface
    {
        $provider = $this->createMock(RevocableSnsProviderInterface::class);
        $provider->method('getName')->willReturn($name);
        $provider->method('getLabel')->willReturn($label);

        if ($failure !== null) {
            $provider->method('revokeConnection')->willThrowException($failure);
        }

        return $provider;
    }

    /**
     * 비밀번호 없이 이 연결에만 의지하던 회원이 해제하면 계정에 영영 들어올 수 없다.
     * 되돌릴 방법이 없으므로 제공자 폐기를 시도하기 전에 막는다.
     */
    public function testLastLoginMethodCannotBeUnlinkedWithoutAPassword(): void
    {
        $repository = $this->createMock(SnsAccountRepository::class);
        $repository->method('findByMemberAndProvider')->willReturn($this->account(1, 'kakao'));
        $repository->method('findByMember')->willReturn([$this->account(1, 'kakao')]);
        $repository->expects($this->never())->method('deleteByMemberAndProvider');

        $registry = new SnsProviderRegistry();
        $registry->register($this->provider('kakao', '카카오'));
        $manager = new SnsConnectionManager(
            $repository,
            $registry,
            $this->createMock(Logger::class),
            $this->memberAccounts(hasLocalPassword: false)
        );

        $result = $manager->revokeAndDelete(10, 'kakao');

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('비밀번호를 먼저 설정', $result->getMessage());
    }

    public function testLastConnectionCanBeUnlinkedOnceAPasswordExists(): void
    {
        $repository = $this->createMock(SnsAccountRepository::class);
        $repository->method('findByMemberAndProvider')->willReturn($this->account(1, 'kakao'));
        $repository->method('findByMember')->willReturn([$this->account(1, 'kakao')]);
        $repository->expects($this->once())->method('deleteByMemberAndProvider')->willReturn(true);

        $registry = new SnsProviderRegistry();
        $registry->register($this->provider('kakao', '카카오'));
        $manager = new SnsConnectionManager(
            $repository,
            $registry,
            $this->createMock(Logger::class),
            $this->memberAccounts(hasLocalPassword: true)
        );

        $this->assertTrue($manager->revokeAndDelete(10, 'kakao')->isSuccess());
    }

    /** 다른 연결이 남으면 비밀번호가 없어도 들어올 길이 있다. */
    public function testAnotherRemainingConnectionKeepsUnlinkAllowed(): void
    {
        $repository = $this->createMock(SnsAccountRepository::class);
        $repository->method('findByMemberAndProvider')->willReturn($this->account(1, 'kakao'));
        $repository->method('findByMember')->willReturn([$this->account(1, 'kakao'), $this->account(2, 'naver')]);
        $repository->expects($this->once())->method('deleteByMemberAndProvider')->willReturn(true);

        $registry = new SnsProviderRegistry();
        $registry->register($this->provider('kakao', '카카오'));
        $manager = new SnsConnectionManager(
            $repository,
            $registry,
            $this->createMock(Logger::class),
            $this->memberAccounts(hasLocalPassword: false)
        );

        $this->assertTrue($manager->revokeAndDelete(10, 'kakao')->isSuccess());
    }

    /** 대부분의 테스트는 마지막 수단 보호와 무관하다 — 비밀번호 있는 회원으로 둔다. */
    private function memberAccounts(bool $hasLocalPassword = true): MemberAccountGatewayInterface
    {
        $gateway = $this->createStub(MemberAccountGatewayInterface::class);
        $gateway->method('hasLocalPassword')->willReturn($hasLocalPassword);

        return $gateway;
    }

    private function account(int $id, string $provider): SnsAccount
    {
        return new SnsAccount($id, 7, 10, $provider, 'uid', null, '2026-07-26 21:00:00', 'access', 'refresh');
    }
}
