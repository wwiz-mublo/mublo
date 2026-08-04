<?php

namespace Tests\Unit\Service\Domain;

use Mublo\Core\Env\Env;
use Mublo\Repository\Domain\DomainVerificationRepository;
use Mublo\Service\Domain\DomainVerificationService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * 도메인 검증 판정·게이트 계약
 *
 * 실제 DNS/HTTP는 타지 않는다. lookupDns()/probe()를 대체한 테스트 서브클래스로
 * "어떤 측정 결과가 어떤 판정이 되는가"만 검증한다.
 */
class DomainVerificationServiceTest extends TestCase
{
    private DomainVerificationRepository&MockObject $repository;
    private ?string $originalAppEnv = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = $this->createMock(DomainVerificationRepository::class);
        $this->repository->method('createPending')->willReturn(11);

        $this->originalAppEnv = $_ENV['APP_ENV'] ?? null;
        // 판정이 실행 환경의 APP_ENV에 좌우되지 않도록 기본은 운영으로 고정한다.
        $this->setAppEnv('production');
    }

    protected function tearDown(): void
    {
        $this->setAppEnv($this->originalAppEnv ?? 'production');

        parent::tearDown();
    }

    private function setAppEnv(string $value): void
    {
        Env::clear();
        Env::set('APP_ENV', $value);
    }

    private function makeService(array $dns, array $probe): DomainVerificationService
    {
        return new class ($this->repository, $dns, $probe) extends DomainVerificationService {
            public function __construct(
                DomainVerificationRepository $repository,
                private array $dnsStub,
                private array $probeStub
            ) {
                parent::__construct($repository);
            }

            protected function lookupDns(string $host): array
            {
                return $this->dnsStub;
            }

            protected function probe(string $host, string $nonce): array
            {
                return $this->probeStub;
            }
        };
    }

    private function dns(array $a = [], array $aaaa = [], array $cname = []): array
    {
        return ['a' => $a, 'aaaa' => $aaaa, 'cname' => $cname, 'error' => ''];
    }

    private function probe(bool $ok, int $code = 0, string $error = ''): array
    {
        return ['url' => 'http://host/probe', 'http_code' => $code, 'ok' => $ok, 'error' => $error];
    }

    public function testProbeSuccessPasses(): void
    {
        $this->repository->expects($this->once())
            ->method('saveResult')
            ->with(11, 'passed', 'reachable');

        $result = $this->makeService($this->dns(['1.2.3.4']), $this->probe(true, 200))
            ->verify('new.example.com', 1, 7);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('reachable', $result->get('verdict'));
    }

    public function testUnreachableWithDnsRecordsFails(): void
    {
        $result = $this->makeService($this->dns(['1.2.3.4']), $this->probe(false, 502))
            ->verify('unreachable-host.invalid', 1, 7);

        $this->assertTrue($result->isFailure());
        $this->assertSame('unreachable', $result->get('verdict'));
        $this->assertStringContainsString('502', $result->getMessage());
    }

    /**
     * CDN/프록시(Cloudflare 등) 경유 구성에서 실제로 겪는 실패를 응답 코드로 구분해 안내한다.
     * "응답 코드 403"만 보여주면 어디를 봐야 할지 알 수 없다.
     */
    public function testUnreachableMessageCarriesCauseHintPerHttpCode(): void
    {
        $cases = [
            200 => '이 설치본이 아닙니다',   // 응답은 왔지만 nonce 불일치 = 다른 사이트
            404 => '배포되지 않았습니다',     // 오리진에 이 버전이 없음
            403 => '봇 차단',                // CF WAF/챌린지
            503 => '봇 차단',
            522 => '오리진 서버에 연결하지 못했습니다',
        ];

        foreach ($cases as $code => $expected) {
            $result = $this->makeService($this->dns(['1.2.3.4']), $this->probe(false, $code))
                ->verify('unreachable-host.invalid', 1, 7);

            $this->assertTrue($result->isFailure(), "code {$code} 는 실패여야 한다");
            $this->assertStringContainsString(
                $expected,
                $result->getMessage(),
                "code {$code} 안내에 원인 힌트가 있어야 한다"
            );
        }
    }

    public function testMissingDnsFails(): void
    {
        $result = $this->makeService($this->dns(), $this->probe(false, 0, 'Could not resolve host'))
            ->verify('unreachable-host.invalid', 1, 7);

        $this->assertTrue($result->isFailure());
        $this->assertSame('dns_missing', $result->get('verdict'));
    }

    public function testProbeResponseBodyIsNotReturnedToClient(): void
    {
        // 프로브 응답 본문은 리포트에 담기지 않는다 (관리자 화면 경유 정보 유출 방지)
        $probe = $this->probe(false, 200) + ['body' => 'secret-internal-page'];

        $result = $this->makeService($this->dns(['1.2.3.4']), $probe)
            ->verify('unreachable-host.invalid', 1, 7);

        $this->assertArrayNotHasKey('body', $result->get('probe'));
    }

    // =====================================================================
    // 도달 확인 생략(dev_local)은 개발 APP_ENV에서만
    //
    // APP_DEBUG가 아니라 APP_ENV로 판정한다 — APP_DEBUG는 운영에서 장애 진단용으로
    // 잠깐 켤 수 있는 값이라, 그때 게이트가 느슨해지면 안 된다.
    // =====================================================================

    public function testLocalHostBypassesProbeOnlyInDevelopmentEnv(): void
    {
        $this->setAppEnv('development');

        $result = $this->makeService($this->dns(), $this->probe(false, 0, 'Connection refused'))
            ->verify('new.localhost:9315', 1, 7);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('dev_local', $result->get('verdict'));
    }

    public function testLocalHostDoesNotBypassProbeInProduction(): void
    {
        $this->setAppEnv('production');

        $result = $this->makeService($this->dns(), $this->probe(false, 0, 'Connection refused'))
            ->verify('new.localhost:9315', 1, 7);

        $this->assertTrue($result->isFailure());
        $this->assertNotSame('dev_local', $result->get('verdict'));
    }

    public function testUnknownEnvValueDoesNotBypassProbe(): void
    {
        // 화이트리스트가 아닌 값(오타 포함)은 우회를 허용하지 않는다 (fail-closed)
        $this->setAppEnv('prod');

        $result = $this->makeService($this->dns(), $this->probe(false))
            ->verify('new.localhost:9315', 1, 7);

        $this->assertTrue($result->isFailure());
    }

    public function testPublicHostDoesNotBypassProbeEvenInDevelopmentEnv(): void
    {
        // 개발환경이어도 로컬 호스트가 아니면 도달 확인을 건너뛰지 않는다
        $this->setAppEnv('development');

        $result = $this->makeService($this->dns(['104.20.23.154']), $this->probe(false, 404))
            ->verify('real-public-site.example.com', 1, 7);

        $this->assertTrue($result->isFailure());
        $this->assertSame('unreachable', $result->get('verdict'));
    }

    public function testEmptyHostIsRejectedWithoutRecording(): void
    {
        $this->repository->expects($this->never())->method('saveResult');

        $result = $this->makeService($this->dns(), $this->probe(false))->verify('   ', 1, 7);

        $this->assertTrue($result->isFailure());
    }

    // =====================================================================
    // 변경 게이트
    // =====================================================================

    public function testConsumeForChangeFailsWithoutPassedRecord(): void
    {
        $this->repository->method('findUsablePassed')->willReturn(null);
        $this->repository->expects($this->never())->method('consume');

        $result = $this->makeService($this->dns(), $this->probe(false))
            ->consumeForChange('new.example.com', 1);

        $this->assertTrue($result->isFailure());
    }

    public function testConsumeForChangeFailsWhenAlreadyConsumed(): void
    {
        $this->repository->method('findUsablePassed')->willReturn(['verification_id' => 5, 'verdict' => 'reachable']);
        $this->repository->method('consume')->willReturn(false);

        $result = $this->makeService($this->dns(), $this->probe(false))
            ->consumeForChange('new.example.com', 1);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('이미 사용된', $result->getMessage());
    }

    public function testConsumeForChangeNormalizesHostAndSucceeds(): void
    {
        $this->repository->expects($this->once())
            ->method('findUsablePassed')
            ->with('new.example.com', 1)
            ->willReturn(['verification_id' => 5, 'verdict' => 'reachable']);
        $this->repository->method('consume')->with(5)->willReturn(true);

        $result = $this->makeService($this->dns(), $this->probe(false))
            ->consumeForChange('  NEW.example.com ', 1);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(5, $result->get('verification_id'));
    }

    public function testConsumeForChangeRecordsAuditInfoOnTheConsumedRow(): void
    {
        // 소진되는 행에 "무엇에서 바뀌었나(previous_host)"와 "누가 눌렀나(consumed_by)"를
        // 같은 UPDATE로 남긴다 — 그 행만 보고 변경 이력을 읽을 수 있어야 한다.
        $this->repository->method('findUsablePassed')
            ->willReturn(['verification_id' => 5, 'verdict' => 'reachable']);

        $this->repository->expects($this->once())
            ->method('consume')
            ->with(5, 'old.example.com', 42)
            ->willReturn(true);

        $result = $this->makeService($this->dns(), $this->probe(false))
            ->consumeForChange('new.example.com', 1, '  OLD.example.com  ', 42);

        $this->assertTrue($result->isSuccess());
    }

    // =====================================================================
    // 변경 이력
    // =====================================================================

    public function testChangeHistoryDelegatesToRepository(): void
    {
        $rows = [['verification_id' => 9, 'host' => 'new.example.com', 'previous_host' => 'old.example.com']];

        $this->repository->expects($this->once())
            ->method('findChangeHistory')
            ->with(1, 10)
            ->willReturn($rows);

        $this->assertSame($rows, $this->makeService($this->dns(), $this->probe(false))->getChangeHistory(1, 10));
    }

    public function testChangeHistoryIsEmptyForInvalidDomainWithoutQuery(): void
    {
        $this->repository->expects($this->never())->method('findChangeHistory');

        $this->assertSame([], $this->makeService($this->dns(), $this->probe(false))->getChangeHistory(0));
    }

    // =====================================================================
    // 프로브 응답 (공개 엔드포인트)
    // =====================================================================

    public function testAcceptProbeRejectsMalformedNonceWithoutQuery(): void
    {
        $this->repository->expects($this->never())->method('findLiveNonce');

        $service = $this->makeService($this->dns(), $this->probe(false));

        $this->assertFalse($service->acceptProbe('new.example.com', 'short'));
        $this->assertFalse($service->acceptProbe('new.example.com', ''));
        $this->assertFalse($service->acceptProbe('', str_repeat('a', 64)));
    }

    public function testAcceptProbeMatchesLiveNonceForSameHost(): void
    {
        $nonce = str_repeat('ab', 32);

        $this->repository->expects($this->once())
            ->method('findLiveNonce')
            ->with('new.example.com', $nonce)
            ->willReturn(['verification_id' => 5]);

        $this->assertTrue(
            $this->makeService($this->dns(), $this->probe(false))->acceptProbe('NEW.example.com', $nonce)
        );
    }
}
