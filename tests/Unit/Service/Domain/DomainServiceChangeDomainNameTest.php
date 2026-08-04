<?php

namespace Tests\Unit\Service\Domain;

use Mublo\Core\Result\Result;
use Mublo\Entity\Domain\Domain;
use Mublo\Repository\Domain\DomainRepository;
use Mublo\Service\Domain\DomainResolver;
use Mublo\Service\Domain\DomainService;
use Mublo\Service\Domain\DomainVerificationService;
use Mublo\Service\Member\FieldEncryptionService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * 호스트명 변경은 검증 통과 기록 없이는 저장되지 않아야 한다.
 *
 * 사이트 주소를 바꾸는 작업이라 실패 시 그 사이트가 즉시 접속 불가가 되므로,
 * "검증을 건너뛰고 저장되는 경로가 없다"가 이 테스트의 핵심 계약이다.
 */
class DomainServiceChangeDomainNameTest extends TestCase
{
    private DomainRepository&MockObject $domainRepository;
    private DomainVerificationService&MockObject $verificationService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->domainRepository = $this->createMock(DomainRepository::class);
        $this->verificationService = $this->createMock(DomainVerificationService::class);

        $this->domainRepository->method('find')->willReturn($this->existingDomain());
        $this->domainRepository->method('existsByDomainExcept')->willReturn(false);
    }

    private function existingDomain(): Domain
    {
        $domain = $this->createMock(Domain::class);
        $domain->method('getDomainId')->willReturn(1);
        $domain->method('getDomain')->willReturn('old.example.com');
        $domain->method('getDomainGroup')->willReturn('1');

        return $domain;
    }

    private function makeService(?DomainVerificationService $verificationService): DomainService
    {
        return new DomainService(
            $this->domainRepository,
            $this->createMock(DomainResolver::class),
            null,
            null,
            $this->createMock(FieldEncryptionService::class),
            null,
            $verificationService
        );
    }

    public function testRejectsWhenVerificationHasNotPassed(): void
    {
        $this->verificationService->method('consumeForChange')
            ->willReturn(Result::failure('DNS 확인을 통과한 기록이 없습니다.'));

        $this->domainRepository->expects($this->never())->method('update');

        $result = $this->makeService($this->verificationService)
            ->changeDomainName(1, 'new.example.com', 7);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('DNS 확인', $result->getMessage());
    }

    public function testRejectsWhenVerificationServiceIsUnavailable(): void
    {
        $this->domainRepository->expects($this->never())->method('update');

        $result = $this->makeService(null)->changeDomainName(1, 'new.example.com', 7);

        $this->assertTrue($result->isFailure());
    }

    public function testRejectsSameDomainWithoutConsumingVerification(): void
    {
        $this->verificationService->expects($this->never())->method('consumeForChange');
        $this->domainRepository->expects($this->never())->method('update');

        $result = $this->makeService($this->verificationService)
            ->changeDomainName(1, 'OLD.example.com', 7);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('동일', $result->getMessage());
    }

    public function testRejectsInvalidHostnameBeforeConsumingVerification(): void
    {
        // 검증을 소진하기 전에 형식 검사에서 걸러져야 한다
        // (통과 기록이 엉뚱한 입력으로 낭비되지 않도록)
        $this->verificationService->expects($this->never())->method('consumeForChange');
        $this->domainRepository->expects($this->never())->method('update');

        $result = $this->makeService($this->verificationService)
            ->changeDomainName(1, 'not a host!', 7);

        $this->assertTrue($result->isFailure());
    }

    public function testPersistsNormalizedHostWhenVerificationPasses(): void
    {
        // 게이트에 직전 호스트명과 실행자를 넘겨야 검증 행에 변경 이력이 남는다
        $this->verificationService->expects($this->once())
            ->method('consumeForChange')
            ->with('new.example.com', 1, 'old.example.com', 7)
            ->willReturn(Result::success('검증 확인됨', ['verification_id' => 42, 'verdict' => 'reachable']));

        $this->domainRepository->expects($this->once())
            ->method('update')
            ->with(1, ['domain' => 'new.example.com'])
            ->willReturn(1);

        $result = $this->makeService($this->verificationService)
            ->changeDomainName(1, '  NEW.example.com  ', 7);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('old.example.com', $result->get('old_domain'));
        $this->assertSame('new.example.com', $result->get('new_domain'));
    }

    public function testRejectsDuplicateHostBeforeConsumingVerification(): void
    {
        $repository = $this->createMock(DomainRepository::class);
        $repository->method('find')->willReturn($this->existingDomain());
        $repository->method('existsByDomainExcept')->willReturn(true);
        $repository->expects($this->never())->method('update');

        $this->verificationService->expects($this->never())->method('consumeForChange');

        $service = new DomainService(
            $repository,
            $this->createMock(DomainResolver::class),
            null,
            null,
            $this->createMock(FieldEncryptionService::class),
            null,
            $this->verificationService
        );

        $result = $service->changeDomainName(1, 'taken.example.com', 7);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('이미 등록된', $result->getMessage());
    }
}
