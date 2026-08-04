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
    public function testRevokesAllExternalConnectionsWithoutDeletingBeforeWithdrawalCommit(): void
    {
        $account = $this->account('kakao');
        $repository = $this->createMock(SnsAccountRepository::class);
        $repository->method('findByMember')->with(10)->willReturn([$account]);
        $repository->expects($this->never())->method('deleteByMember');

        $provider = $this->createMock(RevocableSnsProviderInterface::class);
        $provider->method('getName')->willReturn('kakao');
        $provider->method('getLabel')->willReturn('카카오');
        $provider->expects($this->once())->method('revokeConnection')->with($account);

        $registry = new SnsProviderRegistry();
        $registry->register($provider);

        $manager = new SnsConnectionManager($repository, $registry, $this->createMock(Logger::class));
        $this->assertTrue($manager->revokeAllForMember(10)->isSuccess());
    }

    public function testProviderFailurePreventsLocalDeletion(): void
    {
        $account = $this->account('google');
        $repository = $this->createMock(SnsAccountRepository::class);
        $repository->method('findByMember')->willReturn([$account]);
        $repository->expects($this->never())->method('deleteByMember');

        $provider = $this->createMock(RevocableSnsProviderInterface::class);
        $provider->method('getName')->willReturn('google');
        $provider->method('getLabel')->willReturn('Google');
        $provider->method('revokeConnection')->willThrowException(new \RuntimeException('provider unavailable'));

        $registry = new SnsProviderRegistry();
        $registry->register($provider);

        $manager = new SnsConnectionManager($repository, $registry, $this->createMock(Logger::class));
        $result = $manager->revokeAllForMember(10);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('Google', $result->getMessage());
    }

    public function testExplicitUnlinkRevokesProviderBeforeDeletingLocalRecord(): void
    {
        $account = $this->account('naver');
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

    private function account(string $provider): SnsAccount
    {
        return new SnsAccount(1, 7, 10, $provider, 'uid', null, '2026-07-26 21:00:00', 'access', 'refresh');
    }
}
