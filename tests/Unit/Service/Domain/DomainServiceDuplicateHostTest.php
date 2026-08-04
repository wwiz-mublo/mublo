<?php

namespace Tests\Unit\Service\Domain;

use Mublo\Repository\Domain\DomainRepository;
use Mublo\Service\Domain\DomainResolver;
use Mublo\Service\Domain\DomainService;
use Mublo\Service\Member\FieldEncryptionService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * 도메인 중복 판정 (isDomainTaken 단일 경로)
 *
 * 회귀 방지: 과거 checkDomainAvailability()만 포트를 뗀 형태로 비교해,
 * 저장된 값이 포트를 포함하면(개발환경 localhost:9315 등) 이미 등록된 도메인을
 * "사용 가능"으로 답했다. 저장 경계는 두 형태를 모두 봤기 때문에 앞단 확인과
 * 저장 결과가 어긋났다 — 판정 경로가 갈라지면 다시 생기는 종류의 버그다.
 */
class DomainServiceDuplicateHostTest extends TestCase
{
    /** 저장돼 있다고 가정하는 호스트명 (포트 포함) */
    private const REGISTERED = 'shop.localhost:9315';

    private DomainRepository&MockObject $domainRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->domainRepository = $this->createMock(DomainRepository::class);

        // 저장된 값과 '정확히' 일치할 때만 존재한다고 답한다 (실제 리포지터리와 동일: WHERE domain = ?)
        $this->domainRepository->method('existsByDomain')
            ->willReturnCallback(fn(string $host): bool => strtolower($host) === self::REGISTERED);

        $this->domainRepository->method('existsByDomainExcept')
            ->willReturnCallback(
                fn(string $host, ?int $excludeId = null): bool => strtolower($host) === self::REGISTERED
            );
    }

    private function makeService(): DomainService
    {
        return new DomainService(
            $this->domainRepository,
            $this->createMock(DomainResolver::class),
            null,
            null,
            $this->createMock(FieldEncryptionService::class)
        );
    }

    public function testDetectsRegisteredHostWithPort(): void
    {
        $this->assertTrue($this->makeService()->isDomainTaken(self::REGISTERED, 4));
    }

    public function testDetectionIsCaseInsensitiveAndTrimmed(): void
    {
        $this->assertTrue($this->makeService()->isDomainTaken('  SHOP.LOCALHOST:9315 ', 4));
    }

    public function testAvailabilityCheckAgreesWithSaveTimeGuard(): void
    {
        $service = $this->makeService();

        // 앞단 AJAX 확인과 저장 경계 판정이 같은 답을 내야 한다
        $availability = $service->checkDomainAvailability(self::REGISTERED, 4);

        $this->assertTrue($availability->isFailure());
        $this->assertStringContainsString('이미 등록된', $availability->getMessage());
        $this->assertTrue($service->isDomainTaken(self::REGISTERED, 4));
    }

    public function testFreeHostIsAvailable(): void
    {
        $service = $this->makeService();

        $this->assertFalse($service->isDomainTaken('brand-new.localhost:9315', 4));
        $this->assertTrue($service->checkDomainAvailability('brand-new.localhost:9315', 4)->isSuccess());
    }

    public function testRegisteredPortedHostBlocksTheSameHostWithPortOnly(): void
    {
        // 포트 표기 차이는 DomainResolver의 후보 순서(전체 → 포트제거)를 따른다.
        //
        // - 'a:9315'가 등록됨 + 'a'(포트 없음) 신규  → 허용:
        //   포트 없는 요청은 지금 'a:9315' 행으로 해석되지 않으므로 새 항목이다.
        // - 'a'가 등록됨 + 'a:9315' 신규            → 차단:
        //   'a:9315' 요청은 이미 'a' 행으로 폴백 해석되므로 새 행이 그것을 가린다.
        $service = $this->makeService();

        $this->assertFalse(
            $service->isDomainTaken('shop.localhost', 4),
            '포트 없는 호스트명은 별개 항목이라 차단하지 않는다'
        );
    }
}
