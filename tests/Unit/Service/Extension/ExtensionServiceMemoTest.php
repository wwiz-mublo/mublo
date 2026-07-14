<?php

namespace Tests\Unit\Service\Extension;

use Mublo\Service\Extension\ExtensionCompatibility;
use Mublo\Service\Extension\ExtensionService;
use Mublo\Repository\Domain\DomainRepository;
use Mublo\Infrastructure\Cache\DomainCache;
use Mublo\Infrastructure\Database\Database;
use PHPUnit\Framework\TestCase;

/**
 * ExtensionService의 요청 스코프 도메인 by-id 메모이즈 검증.
 *
 * getExtensionConfig()/getEnabledPackages()가 한 요청에서 여러 번 호출돼도
 * domain_configs를 by-id로 딱 1회만 조회해야 한다(반복 쿼리 제거).
 */
class ExtensionServiceMemoTest extends TestCase
{
    private function domainStub(): object
    {
        return new class {
            public function getExtensionConfig(): array
            {
                return ['plugins' => ['Faq'], 'packages' => ['Board']];
            }
            public function getDomainGroup(): string
            {
                return '1';
            }
        };
    }

    private function makeService(DomainRepository $repo): ExtensionService
    {
        return new ExtensionService(
            $repo,
            $this->createMock(DomainCache::class),
            $this->createMock(Database::class),
            new ExtensionCompatibility(),
        );
    }

    public function testRepeatedGetExtensionConfigQueriesDomainOnce(): void
    {
        $repo = $this->createMock(DomainRepository::class);
        $repo->expects($this->once())   // 3회 호출해도 find는 1회여야 함
            ->method('find')
            ->with(7)
            ->willReturn($this->domainStub());

        $svc = $this->makeService($repo);

        $a = $svc->getExtensionConfig(7);
        $b = $svc->getExtensionConfig(7);
        $c = $svc->getExtensionConfig(7);

        $this->assertSame($a, $b);
        $this->assertSame($b, $c);
        $this->assertSame(['Faq'], $a['plugins']);
    }

    public function testConfigAndPackagesShareTheSameCachedDomain(): void
    {
        $repo = $this->createMock(DomainRepository::class);
        $repo->expects($this->once())   // getExtensionConfig + getEnabledPackages → find 1회
            ->method('find')
            ->with(3)
            ->willReturn($this->domainStub());

        $svc = $this->makeService($repo);

        $svc->getExtensionConfig(3);
        $packages = $svc->getEnabledPackages(3);

        $this->assertSame(['Board'], $packages);
    }

    public function testDistinctDomainsAreCachedSeparately(): void
    {
        $repo = $this->createMock(DomainRepository::class);
        $repo->expects($this->exactly(2))   // 서로 다른 도메인은 각각 1회
            ->method('find')
            ->willReturn($this->domainStub());

        $svc = $this->makeService($repo);

        $svc->getExtensionConfig(1);
        $svc->getExtensionConfig(1);
        $svc->getExtensionConfig(2);
        $svc->getExtensionConfig(2);
    }

    public function testNestedPluginParentMustBeEnabledInTheSameDomain(): void
    {
        $withParent = new class {
            public function getExtensionConfig(): array
            {
                return ['plugins' => ['Board/BoardReport'], 'packages' => ['Board']];
            }
            public function getDomainGroup(): string
            {
                return '1';
            }
        };
        $withoutParent = new class {
            public function getExtensionConfig(): array
            {
                return ['plugins' => ['Board/BoardReport'], 'packages' => []];
            }
            public function getDomainGroup(): string
            {
                return '2';
            }
        };

        $repo = $this->createMock(DomainRepository::class);
        $repo->method('find')->willReturnMap([
            [1, $withParent],
            [2, $withoutParent],
        ]);
        $svc = $this->makeService($repo);

        $this->assertSame(['Board/BoardReport'], $svc->getEnabledPlugins(1));
        $this->assertSame([], $svc->getEnabledPlugins(2));
    }
}
