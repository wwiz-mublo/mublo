<?php

namespace Tests\Unit\Service\Domain;

use Mublo\Entity\Domain\Domain;
use Mublo\Infrastructure\Cache\CacheInterface;
use Mublo\Infrastructure\Cache\DomainCache;
use Mublo\Repository\Domain\DomainAliasRepository;
use Mublo\Repository\Domain\DomainRepository;
use Mublo\Service\Domain\DomainResolver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * 별칭 호스트로 들어온 요청도 그 사이트로 해석돼야 한다.
 *
 * 핵심 계약:
 * - 대표 주소가 우선이다 (대표 주소로 찾으면 별칭은 보지 않는다).
 * - 별칭으로 찾아도 돌려주는 것은 사이트 자체다 (getDomain()은 대표 주소).
 * - 별칭 캐시가 낡으면(대표 주소 변경·사이트 삭제) 스스로 버리고 DB에서 다시 찾는다.
 */
class DomainResolverAliasTest extends TestCase
{
    /** @var array<string,Domain> 대표 주소 → 사이트 */
    private array $sites = [];

    /** @var array<string,int> 별칭 → domain_id */
    private array $aliases = [];

    private DomainRepository&MockObject $repository;
    private DomainAliasRepository&MockObject $aliasRepository;
    private DomainCache $cache;

    protected function setUp(): void
    {
        parent::setUp();
        DomainResolver::clearMemoryCache();

        $this->sites = [
            'wizcoin.kr' => $this->site(1, 'wizcoin.kr'),
            'raaaa.kr' => $this->site(2, 'raaaa.kr'),
        ];
        $this->aliases = [
            'rankcash.kr' => 1,
            'rbbbb.kr' => 2,
        ];

        $this->repository = $this->createMock(DomainRepository::class);
        $this->repository->method('findByDomain')
            ->willReturnCallback(fn(string $name) => $this->sites[$name] ?? null);
        $this->repository->method('findById')
            ->willReturnCallback(function (int $id): ?Domain {
                foreach ($this->sites as $site) {
                    if ($site->getDomainId() === $id) {
                        return $site;
                    }
                }
                return null;
            });

        $this->aliasRepository = $this->createMock(DomainAliasRepository::class);
        $this->cache = new DomainCache($this->memoryCache());
    }

    protected function tearDown(): void
    {
        DomainResolver::clearMemoryCache();
        parent::tearDown();
    }

    private function site(int $id, string $domain): Domain
    {
        return Domain::fromArray(['domain_id' => $id, 'domain' => $domain, 'domain_group' => (string) $id]);
    }

    private function resolver(): DomainResolver
    {
        return new DomainResolver($this->cache, $this->repository, $this->aliasRepository);
    }

    private function useAliasTable(): void
    {
        $this->aliasRepository->method('findDomainIdByHost')
            ->willReturnCallback(fn(string $host) => $this->aliases[$host] ?? null);
    }

    public function testCanonicalHostDoesNotConsultAliases(): void
    {
        $this->aliasRepository->expects($this->never())->method('findDomainIdByHost');

        $domain = $this->resolver()->resolve('wizcoin.kr');

        $this->assertSame(1, $domain?->getDomainId());
    }

    public function testAliasHostResolvesToItsSite(): void
    {
        $this->useAliasTable();

        $domain = $this->resolver()->resolve('rankcash.kr');

        $this->assertSame(1, $domain?->getDomainId());
        // 사이트 자체를 돌려준다 — 대표 주소는 그대로
        $this->assertSame('wizcoin.kr', $domain?->getDomain());
    }

    public function testAliasesBelongToTheirOwnSite(): void
    {
        $this->useAliasTable();

        $this->assertSame(2, $this->resolver()->resolve('rbbbb.kr')?->getDomainId());
        $this->assertSame(1, $this->resolver()->resolve('rankcash.kr')?->getDomainId());
    }

    public function testWwwAndPortVariantsOfAliasResolve(): void
    {
        $this->useAliasTable();

        $this->assertSame(1, $this->resolver()->resolve('www.rankcash.kr')?->getDomainId());
        $this->assertSame(1, $this->resolver()->resolve('RANKCASH.kr:443')?->getDomainId());
    }

    public function testSubdomainOfAliasFallsBackToAlias(): void
    {
        $this->useAliasTable();

        $this->assertSame(1, $this->resolver()->resolve('api.rankcash.kr')?->getDomainId());
    }

    public function testUnknownHostIsNotResolved(): void
    {
        $this->useAliasTable();

        $this->assertNull($this->resolver()->resolve('unknown.kr'));
    }

    public function testWithoutAliasRepositoryAliasesAreIgnored(): void
    {
        $resolver = new DomainResolver($this->cache, $this->repository);

        $this->assertNull($resolver->resolve('rankcash.kr'));
        $this->assertSame(1, $resolver->resolve('wizcoin.kr')?->getDomainId());
    }

    public function testCachedAliasSkipsAliasTable(): void
    {
        $this->aliasRepository->expects($this->once())
            ->method('findDomainIdByHost')
            ->willReturnCallback(fn(string $host) => $this->aliases[$host] ?? null);

        $this->resolver()->resolve('rankcash.kr');
        DomainResolver::clearMemoryCache(); // 다음 요청

        $this->assertSame(1, $this->resolver()->resolve('rankcash.kr')?->getDomainId());
    }

    public function testStaleAliasCacheAfterRenameIsRefreshed(): void
    {
        $this->useAliasTable();
        $this->resolver()->resolve('rankcash.kr'); // 별칭 캐시: rankcash.kr → (1, wizcoin.kr)
        DomainResolver::clearMemoryCache();

        // 사이트 1의 대표 주소가 바뀌었다 (캐시도 지워졌다)
        $renamed = $this->site(1, 'wizcoin2.kr');
        $this->sites = ['wizcoin2.kr' => $renamed, 'raaaa.kr' => $this->sites['raaaa.kr']];
        $this->cache->delete('wizcoin.kr');

        $domain = $this->resolver()->resolve('rankcash.kr');

        $this->assertSame(1, $domain?->getDomainId());
        $this->assertSame('wizcoin2.kr', $domain?->getDomain());
    }

    public function testStaleAliasCachePointingToAnotherSiteIsDiscarded(): void
    {
        // 별칭 캐시는 사이트 1(wizcoin.kr)을 가리키는데, 그 주소를 이제 다른 사이트(9)가 쓴다
        $this->cache->setAlias('rankcash.kr', $this->site(1, 'wizcoin.kr'));
        $this->sites['wizcoin.kr'] = $this->site(9, 'wizcoin.kr');
        $this->sites['moved.kr'] = $this->site(1, 'moved.kr');
        $this->useAliasTable();

        $domain = $this->resolver()->resolve('rankcash.kr');

        $this->assertSame(1, $domain?->getDomainId());
        $this->assertSame('moved.kr', $domain?->getDomain());
    }

    public function testRemovedAliasStopsResolvingAfterInvalidate(): void
    {
        $this->useAliasTable();
        $resolver = $this->resolver();
        $resolver->resolve('rankcash.kr');

        unset($this->aliases['rankcash.kr']);
        $resolver->invalidate('rankcash.kr');

        $this->assertNull($resolver->resolve('rankcash.kr'));
    }

    private function memoryCache(): CacheInterface
    {
        return new class implements CacheInterface {
            private array $store = [];
            public function get(string $key, mixed $default = null): mixed { return $this->store[$key] ?? $default; }
            public function set(string $key, mixed $value, ?int $ttl = null): bool { $this->store[$key] = $value; return true; }
            public function has(string $key): bool { return isset($this->store[$key]); }
            public function delete(string $key): bool { unset($this->store[$key]); return true; }
            public function flush(): int { $n = count($this->store); $this->store = []; return $n; }
            public function remember(string $key, int $ttl, callable $callback): mixed {
                if (!isset($this->store[$key])) { $this->store[$key] = $callback(); }
                return $this->store[$key];
            }
            public function increment(string $key, int $value = 1, ?int $ttl = null): int|false {
                $new = (int) ($this->store[$key] ?? 0) + $value;
                $this->store[$key] = $new;
                return $new;
            }
            public function setDomainId(?int $domainId): self { return $this; }
            public function getDomainId(): ?int { return null; }
        };
    }
}
