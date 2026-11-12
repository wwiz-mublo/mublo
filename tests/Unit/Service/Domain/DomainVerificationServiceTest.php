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
        // Env::set()은 putenv까지 하므로 캐시만 비우면 다음 테스트로 새어 나간다.
        foreach (['DOMAIN_DNS_A', 'DOMAIN_DNS_CNAME'] as $key) {
            putenv($key);
            unset($_ENV[$key]);
        }
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

    // =========================================================================
    // DNS 설정 안내
    // =========================================================================

    /**
     * @param array<string,array> $dnsByHost 호스트별 lookupDns 결과
     * @param array<string,string[]> $nsByDomain 도메인별 NS
     * @param array<string,string[]> $ipsByHost resolveIpv4 결과 (서버 IP 자동값)
     */
    private function makeGuideService(array $dnsByHost = [], array $nsByDomain = [], array $ipsByHost = []): DomainVerificationService
    {
        return new class ($this->repository, $dnsByHost, $nsByDomain, $ipsByHost) extends DomainVerificationService {
            public function __construct(
                DomainVerificationRepository $repository,
                private array $dnsByHost,
                private array $nsByDomain,
                private array $ipsByHost
            ) {
                parent::__construct($repository);
            }

            protected function lookupDns(string $host): array
            {
                return $this->dnsByHost[$host] ?? ['a' => [], 'aaaa' => [], 'cname' => [], 'error' => ''];
            }

            protected function lookupNs(string $domain): array
            {
                return $this->nsByDomain[$domain] ?? [];
            }

            protected function resolveIpv4(string $host): array
            {
                return $this->ipsByHost[$host] ?? [];
            }
        };
    }

    public function testServerTargetsPreferEnvOverAutoDetection(): void
    {
        Env::set('DOMAIN_DNS_A', '203.0.113.10, not-an-ip');
        Env::set('DOMAIN_DNS_CNAME', 'Sites.Example.NET.');

        $targets = $this->makeGuideService([], [], ['admin.example.com' => ['198.51.100.1']])
            ->serverTargets('admin.example.com');

        $this->assertSame(['203.0.113.10'], $targets['a']);
        $this->assertSame('sites.example.net', $targets['cname']);
        $this->assertSame('env', $targets['source']);
    }

    public function testServerTargetsFallBackToCurrentHostIp(): void
    {
        $targets = $this->makeGuideService([], [], ['admin.example.com' => ['139.162.95.136', '10.0.0.5']])
            ->serverTargets('admin.example.com:443');

        $this->assertSame(['139.162.95.136'], $targets['a'], '사설 IP는 안내하지 않는다');
        $this->assertSame('auto', $targets['source']);
        $this->assertSame('', $targets['warning']);
    }

    public function testAutoDetectedTargetWarnsWhenAdminHostIsBehindCloudflare(): void
    {
        $targets = $this->makeGuideService(
            [],
            ['example.com' => ['ada.ns.cloudflare.com']],
            ['admin.example.com' => ['104.16.1.1']]
        )->serverTargets('admin.example.com');

        $this->assertSame('auto', $targets['source']);
        $this->assertStringContainsString('DOMAIN_DNS_A', $targets['warning']);
    }

    public function testGuideForApexListsRootAndWwwWithStatus(): void
    {
        Env::set('DOMAIN_DNS_A', '203.0.113.10');

        $guide = $this->makeGuideService([
            'www.shop.co.kr' => $this->dns([], [], ['shop.co.kr.']),
        ])->buildGuide('Shop.co.kr', $this->dns(['198.51.100.9']));

        $this->assertSame('shop.co.kr', $guide['apex']);
        $this->assertTrue($guide['is_apex']);
        $this->assertCount(2, $guide['records']);

        [$root, $www] = $guide['records'];
        $this->assertSame(['@', 'A', '203.0.113.10', 'wrong'], [$root['name'], $root['type'], $root['value'], $root['status']]);
        $this->assertSame(['198.51.100.9'], $root['current']);
        $this->assertSame(['www', 'CNAME', 'shop.co.kr', 'ok'], [$www['name'], $www['type'], $www['value'], $www['status']]);
        $this->assertSame('A 203.0.113.10', $www['alt']);
    }

    public function testGuideForSubdomainUsesCnameWhenConfigured(): void
    {
        Env::set('DOMAIN_DNS_A', '203.0.113.10');
        Env::set('DOMAIN_DNS_CNAME', 'sites.example.net');

        $guide = $this->makeGuideService()->buildGuide('shop.example.com', $this->dns());

        $this->assertFalse($guide['is_apex']);
        $this->assertCount(1, $guide['records'], '하위 도메인은 www를 안내하지 않는다');
        $record = $guide['records'][0];
        $this->assertSame(['shop', 'CNAME', 'sites.example.net', 'missing'], [$record['name'], $record['type'], $record['value'], $record['status']]);
    }

    public function testCnameRecordAcceptsDirectARecordToServer(): void
    {
        Env::set('DOMAIN_DNS_A', '203.0.113.10');

        $guide = $this->makeGuideService([
            'www.example.com' => $this->dns(['203.0.113.10']),
        ])->buildGuide('example.com', $this->dns(['203.0.113.10']));

        $this->assertSame(['ok', 'ok'], array_column($guide['records'], 'status'));
    }

    public function testCloudflareProxiedRecordsAreNotReportedAsWrong(): void
    {
        Env::set('DOMAIN_DNS_A', '203.0.113.10');

        $guide = $this->makeGuideService(
            ['www.example.com' => $this->dns(['104.16.1.1'])],
            ['example.com' => ['ada.ns.cloudflare.com.', 'bob.ns.cloudflare.com.']]
        )->buildGuide('example.com', $this->dns(['104.16.1.1', '104.16.1.2']));

        $this->assertSame('cloudflare', $guide['provider']);
        $this->assertSame(['proxied', 'proxied'], array_column($guide['records'], 'status'));
    }

    public function testCloudflareDnsOnlyRecordsAreStillCompared(): void
    {
        Env::set('DOMAIN_DNS_A', '139.162.95.136');

        // 네임서버는 Cloudflare지만 프록시를 끈(회색 구름) 레코드 — 실제 IP가 보인다.
        $guide = $this->makeGuideService(
            ['www.example.com' => $this->dns(['139.162.95.136'])],
            ['example.com' => ['ada.ns.cloudflare.com']]
        )->buildGuide('example.com', $this->dns(['45.33.1.1']));

        $this->assertSame(['wrong', 'ok'], array_column($guide['records'], 'status'));
    }

    public function testAutoDetectedTargetDoesNotWarnForDnsOnlyRecordOnCloudflare(): void
    {
        $targets = $this->makeGuideService(
            [],
            ['example.com' => ['ada.ns.cloudflare.com']],
            ['admin.example.com' => ['139.162.95.136']]
        )->serverTargets('admin.example.com');

        $this->assertSame('', $targets['warning']);
    }

    public function testGuideWithoutKnownServerAddressCannotCompare(): void
    {
        $guide = $this->makeGuideService()->buildGuide('example.com', $this->dns(['198.51.100.9']));

        $this->assertSame('none', $guide['target']['source']);
        $this->assertSame('unknown', $guide['records'][0]['status']);
        $this->assertSame('', $guide['records'][0]['value']);
    }
}
