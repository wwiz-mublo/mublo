<?php

namespace Tests\Unit\Service\Domain;

use Mublo\Core\Result\Result;
use Mublo\Entity\Domain\Domain;
use Mublo\Infrastructure\Database\Database;
use Mublo\Repository\Domain\DomainAliasRepository;
use Mublo\Repository\Domain\DomainRepository;
use Mublo\Service\Domain\DomainResolver;
use Mublo\Service\Domain\DomainService;
use Mublo\Service\Domain\DomainVerificationService;
use Mublo\Service\Member\FieldEncryptionService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * 별칭은 대표 주소와 같은 이름 공간이고, 대표 주소처럼 DNS 확인을 거쳐야 생긴다.
 *
 * 사이트 1 = wizcoin.kr (별칭 rankcash.kr), 사이트 2 = raaaa.kr (별칭 rbbbb.kr)
 */
class DomainServiceAliasTest extends TestCase
{
    /** @var array<string,int> 대표 주소 → domain_id */
    private array $sites = ['wizcoin.kr' => 1, 'raaaa.kr' => 2];

    /** @var array<string,int> 별칭 → domain_id */
    private array $aliases = ['rankcash.kr' => 1, 'rbbbb.kr' => 2];

    /** @var list<string> 저장 순서 기록 */
    private array $writes = [];

    private DomainRepository&MockObject $domainRepository;
    private DomainAliasRepository&MockObject $aliasRepository;
    private DomainVerificationService&MockObject $verificationService;
    private DomainResolver&MockObject $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->domainRepository = $this->createMock(DomainRepository::class);
        $this->domainRepository->method('find')->willReturnCallback(
            fn(int $id) => $id === 1
                ? Domain::fromArray(['domain_id' => 1, 'domain' => 'wizcoin.kr', 'domain_group' => '1'])
                : null
        );
        $this->domainRepository->method('existsByDomain')
            ->willReturnCallback(fn(string $name) => isset($this->sites[$name]));
        $this->domainRepository->method('existsByDomainExcept')
            ->willReturnCallback(fn(string $name, ?int $except) => isset($this->sites[$name]) && $this->sites[$name] !== $except);
        $this->domainRepository->method('update')->willReturnCallback(function (int $id, array $data): int {
            $this->writes[] = 'update:' . ($data['domain'] ?? '');
            return 1;
        });

        $db = $this->createMock(Database::class);
        $db->method('transaction')->willReturnCallback(fn(callable $callback) => $callback($db));
        $this->domainRepository->method('getDb')->willReturn($db);

        $this->aliasRepository = $this->createMock(DomainAliasRepository::class);
        $this->aliasRepository->method('findDomainIdByHost')
            ->willReturnCallback(fn(string $host) => $this->aliases[$host] ?? null);

        $this->verificationService = $this->createMock(DomainVerificationService::class);
        $this->resolver = $this->createMock(DomainResolver::class);
    }

    private function service(?DomainAliasRepository $aliasRepository = null): DomainService
    {
        return new DomainService(
            $this->domainRepository,
            $this->resolver,
            null,
            null,
            $this->createMock(FieldEncryptionService::class),
            null,
            $this->verificationService,
            null,
            $aliasRepository ?? $this->aliasRepository
        );
    }

    private function passVerification(): void
    {
        $this->verificationService->method('consumeForChange')
            ->willReturn(Result::success('검증 확인됨', ['verification_id' => 42, 'verdict' => 'reachable']));
    }

    // =========================================================================
    // 이름 공간
    // =========================================================================

    public function testAliasOfAnySiteIsTakenForNewSites(): void
    {
        $this->assertTrue($this->service()->isDomainTaken('rbbbb.kr'));
        $this->assertTrue($this->service()->isDomainTaken('rankcash.kr'));
        $this->assertFalse($this->service()->isDomainTaken('free.kr'));
    }

    public function testOwnAliasIsNotTakenWhenRenamingOwnSite(): void
    {
        $this->assertFalse($this->service()->isDomainTaken('rankcash.kr', 1));
        // 다른 사이트의 별칭은 여전히 막힌다
        $this->assertTrue($this->service()->isDomainTaken('rbbbb.kr', 1));
    }

    public function testAliasWithPortIsCheckedWithoutPort(): void
    {
        $this->assertTrue($this->service()->isDomainTaken('rbbbb.kr:8080'));
    }

    // =========================================================================
    // www. 금지 — 해석기가 www.를 떼므로 www. 주소는 등록해도 그 주소로 가지 않는다
    // =========================================================================

    public function testWwwHostsAreRejected(): void
    {
        foreach (['www.raaaa.kr', 'WWW.raaaa.kr', 'www.raaaa.kr:8080'] as $host) {
            $result = $this->service()->validateDomainName($host);
            $this->assertTrue($result->isFailure(), $host);
            $this->assertStringContainsString('www.', $result->getMessage());
        }
    }

    public function testHostsMerelyContainingWwwAreAllowed(): void
    {
        foreach (['wwwx.kr', 'api.www-shop.kr', 'raaaa.kr'] as $host) {
            $this->assertTrue($this->service()->validateDomainName($host)->isSuccess(), $host);
        }
    }

    public function testWwwAliasIsRejectedBeforeConsumingVerification(): void
    {
        $this->verificationService->expects($this->never())->method('consumeForChange');
        $this->aliasRepository->expects($this->never())->method('add');

        $this->assertTrue($this->service()->addAlias(1, 'www.raaaa.kr', 7)->isFailure());
        $this->assertTrue($this->service()->changeDomainName(1, 'www.new.kr', 7, true)->isFailure());
    }

    // =========================================================================
    // 별칭 추가
    // =========================================================================

    public function testAddAliasRejectsHostsAlreadyInUseBeforeConsumingVerification(): void
    {
        $this->verificationService->expects($this->never())->method('consumeForChange');
        $this->aliasRepository->expects($this->never())->method('add');

        foreach (['raaaa.kr', 'rbbbb.kr', 'wizcoin.kr', 'rankcash.kr'] as $host) {
            $result = $this->service()->addAlias(1, $host, 7);
            $this->assertTrue($result->isFailure(), $host);
            $this->assertStringContainsString('이미 등록된', $result->getMessage());
        }
    }

    public function testAddAliasRequiresPassedVerification(): void
    {
        $this->verificationService->method('consumeForChange')
            ->willReturn(Result::failure('DNS 확인을 통과한 기록이 없습니다.'));
        $this->aliasRepository->expects($this->never())->method('add');

        $result = $this->service()->addAlias(1, 'new.kr', 7);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('DNS 확인', $result->getMessage());
    }

    public function testAddAliasConsumesVerificationAsAliasAndStoresNormalizedHost(): void
    {
        $this->verificationService->expects($this->once())
            ->method('consumeForChange')
            ->with('new.kr', 1, null, 7, 'alias')
            ->willReturn(Result::success('검증 확인됨', ['verification_id' => 42, 'verdict' => 'reachable']));
        $this->aliasRepository->expects($this->once())->method('add')->with(1, 'new.kr', 7);
        $this->resolver->expects($this->once())->method('invalidate')->with('new.kr');

        $result = $this->service()->addAlias(1, '  NEW.kr ', 7);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('new.kr', $result->get('alias'));
    }

    public function testAddAliasFailsWithoutAliasRepository(): void
    {
        $service = new DomainService(
            $this->domainRepository,
            $this->resolver,
            null,
            null,
            $this->createMock(FieldEncryptionService::class),
            null,
            $this->verificationService
        );

        $this->assertTrue($service->addAlias(1, 'new.kr', 7)->isFailure());
    }

    // =========================================================================
    // 별칭 삭제
    // =========================================================================

    public function testRemoveAliasOfAnotherSiteFails(): void
    {
        $this->aliasRepository->method('removeHost')->willReturn(false);
        $this->resolver->expects($this->never())->method('invalidate');

        $this->assertTrue($this->service()->removeAlias(1, 'rbbbb.kr', 7)->isFailure());
    }

    public function testRemoveAliasInvalidatesItsCache(): void
    {
        $this->aliasRepository->expects($this->once())->method('removeHost')->with(1, 'rankcash.kr')->willReturn(true);
        $this->resolver->expects($this->once())->method('invalidate')->with('rankcash.kr');

        $this->assertTrue($this->service()->removeAlias(1, 'RankCash.kr', 7)->isSuccess());
    }

    // =========================================================================
    // 대표 주소 변경과 별칭
    // =========================================================================

    public function testPromotingOwnAliasSwapsItWithOldDomain(): void
    {
        $this->passVerification();
        $this->aliasRepository->method('removeHost')->willReturnCallback(function (int $id, string $host): bool {
            $this->writes[] = "alias-remove:{$id}:{$host}";
            return true;
        });
        $this->aliasRepository->method('add')->willReturnCallback(function (int $id, string $host, ?int $by): int {
            $this->writes[] = "alias-add:{$id}:{$host}";
            return 1;
        });

        $result = $this->service()->changeDomainName(1, 'rankcash.kr', 7, true);

        $this->assertTrue($result->isSuccess(), $result->getMessage());
        $this->assertTrue($result->get('old_kept_as_alias'));
        // 별칭 정리를 먼저 하고, 이벤트를 내는 주소 변경은 마지막에
        $this->assertSame([
            'alias-remove:1:rankcash.kr',
            'alias-add:1:wizcoin.kr',
            'update:rankcash.kr',
        ], $this->writes);
    }

    public function testRenameCannotTakeAnotherSitesAlias(): void
    {
        $this->verificationService->expects($this->never())->method('consumeForChange');

        $result = $this->service()->changeDomainName(1, 'rbbbb.kr', 7, true);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('이미 등록된', $result->getMessage());
        $this->assertSame([], $this->writes);
    }

    public function testRenameWithoutAliasWorkKeepsOldPath(): void
    {
        $this->passVerification();
        $this->domainRepository->expects($this->never())->method('getDb');
        $this->aliasRepository->expects($this->never())->method('add');
        $this->aliasRepository->expects($this->never())->method('removeHost');

        $result = $this->service()->changeDomainName(1, 'new.kr', 7, false);

        $this->assertTrue($result->isSuccess());
        $this->assertFalse($result->get('old_kept_as_alias'));
        $this->assertSame(['update:new.kr'], $this->writes);
    }

    public function testKeepOldAsAliasIsIgnoredWithoutAliasRepository(): void
    {
        $this->passVerification();
        $service = new DomainService(
            $this->domainRepository,
            $this->resolver,
            null,
            null,
            $this->createMock(FieldEncryptionService::class),
            null,
            $this->verificationService
        );

        $result = $service->changeDomainName(1, 'new.kr', 7, true);

        $this->assertTrue($result->isSuccess());
        $this->assertFalse($result->get('old_kept_as_alias'));
    }

    public function testFailedAliasWriteAbortsRenameBeforeDomainUpdate(): void
    {
        $this->passVerification();
        $this->aliasRepository->method('add')->willThrowException(new \RuntimeException('duplicate'));

        $result = $this->service()->changeDomainName(1, 'new.kr', 7, true);

        $this->assertTrue($result->isFailure());
        $this->assertSame([], $this->writes, '주소 변경까지 가지 않아야 한다');
    }
}
