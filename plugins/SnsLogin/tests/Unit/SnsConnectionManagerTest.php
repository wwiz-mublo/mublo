<?php
namespace Tests\SnsLogin\Unit;

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
        // 폐기에 성공한 행만 사라진다 — 실패한 행은 재시도에 쓸 토큰을 들고 남아야 한다.
        $repository->expects($this->once())->method('deleteById')->with(1, 7)->willReturn(true);
        $repository->expects($this->once())->method('markRevokeFailed')
            ->with(2, $this->stringContains('Google'));

        $registry = new SnsProviderRegistry();
        $registry->register($this->provider('kakao', '카카오'));
        $registry->register($this->provider('google', 'Google', new \RuntimeException('provider unavailable')));

        $manager = new SnsConnectionManager($repository, $registry, $this->createMock(Logger::class));

        $this->assertSame(['revoked' => 1, 'failed' => 1], $manager->revokeAndCleanupForMember(10));
    }

    public function testCleanupSurvivesUnusableProviderInsteadOfThrowing(): void
    {
        $account = $this->account(1, 'kakao');

        $repository = $this->createMock(SnsAccountRepository::class);
        $repository->method('findByMember')->willReturn([$account]);
        $repository->expects($this->never())->method('deleteById');
        $repository->expects($this->once())->method('markRevokeFailed');

        // 관리자가 client_id 를 지우면 제공자가 등록조차 되지 않는다.
        // 그래도 탈퇴 정리 자체는 끝나야 한다 (탈퇴는 이미 확정됐다).
        $manager = new SnsConnectionManager($repository, new SnsProviderRegistry(), $this->createMock(Logger::class));

        $this->assertSame(['revoked' => 0, 'failed' => 1], $manager->revokeAndCleanupForMember(10));
    }

    public function testCleanupReportsNothingWhenStorageIsUnreachable(): void
    {
        $repository = $this->createMock(SnsAccountRepository::class);
        $repository->method('findByMember')->willThrowException(new \RuntimeException('table missing'));

        $manager = new SnsConnectionManager($repository, new SnsProviderRegistry(), $this->createMock(Logger::class));

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

        $manager = new SnsConnectionManager($repository, $registry, $this->createMock(Logger::class));

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
        $manager = new SnsConnectionManager($repository, $registry, $this->createMock(Logger::class));

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

        $manager = new SnsConnectionManager($repository, $registry, $this->createMock(Logger::class));

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

    private function account(int $id, string $provider): SnsAccount
    {
        return new SnsAccount($id, 7, 10, $provider, 'uid', null, '2026-07-26 21:00:00', 'access', 'refresh');
    }
}
