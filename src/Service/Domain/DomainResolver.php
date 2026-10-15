<?php
declare(strict_types=1);
namespace Mublo\Service\Domain;

use Mublo\Entity\Domain\Domain;
use Mublo\Infrastructure\Cache\DomainCache;
use Mublo\Repository\Domain\DomainAliasRepository;
use Mublo\Repository\Domain\DomainRepository;

/**
 * Class DomainResolver
 *
 * 도메인 기반 멀티테넌시 해결 서비스
 *
 * 책임:
 * - 도메인명으로부터 Domain 객체 획득
 * - 캐시 우선 조회 → DB 폴백
 * - 도메인 유효성 검증
 *
 * 조회 흐름 (이름 하나당):
 * 1. 메모리 캐시 (static, 같은 요청 내)
 * 2. 파일 캐시 — 대표 주소, 그다음 별칭 (DomainCache, 요청 간)
 * 3. DB 조회 — 대표 주소(domain_configs), 없으면 별칭(domain_aliases)
 *
 * 별칭으로 찾아도 돌려주는 Domain은 그 사이트 자체다(getDomain()은 대표 주소).
 * 요청이 들어온 호스트는 Context::getDomain()에 그대로 남는다.
 */
class DomainResolver
{
    protected DomainCache $cache;
    protected DomainRepository $repository;
    protected ?DomainAliasRepository $aliasRepository;

    /**
     * 메모리 캐시 (같은 요청 내 재사용)
     */
    protected static array $memoryCache = [];

    /**
     * 해결 실패 시 예외 발생 여부
     */
    protected bool $throwOnNotFound = false;

    public function __construct(
        DomainCache $cache,
        DomainRepository $repository,
        ?DomainAliasRepository $aliasRepository = null
    ) {
        $this->cache = $cache;
        $this->repository = $repository;
        $this->aliasRepository = $aliasRepository;
    }

    /**
     * 도메인명으로 Domain 해결
     *
     * @param string $domainName 도메인명 (예: shop1.com, localhost:8080)
     * @return Domain|null
     * @throws DomainNotFoundException 설정에 따라 발생
     */
    public function resolve(string $domainName): ?Domain
    {
        // 도메인 정규화 (소문자, 공백 제거 — 포트 유지)
        $fullDomain = strtolower(trim($domainName));

        // www. 제거 (www는 항상 동일 도메인 취급)
        $fullDomain = $this->stripWww($fullDomain);

        // 포트 제거 버전
        $hostOnly = $this->stripPort($fullDomain);

        // 포트가 있으면 포트 포함 도메인 먼저 시도, 없으면 바로 호스트만
        $candidates = ($fullDomain !== $hostOnly)
            ? [$fullDomain, $hostOnly]
            : [$hostOnly];

        foreach ($candidates as $candidate) {
            $domain = $this->resolveByName($candidate);
            if ($domain !== null) {
                return $domain;
            }
        }

        // 서브도메인 폴백: demo.example.co.kr → example.co.kr 재시도
        $parentDomain = $this->stripFirstSubdomain($hostOnly);
        if ($parentDomain !== null) {
            // 포트 포함 버전 먼저 시도 (개발환경: localhost:8080)
            $port = $this->extractPort($fullDomain);
            if ($port !== null) {
                $domain = $this->resolveByName($parentDomain . ':' . $port);
                if ($domain !== null) {
                    return $domain;
                }
            }
            $domain = $this->resolveByName($parentDomain);
            if ($domain !== null) {
                return $domain;
            }
        }

        // 찾지 못함
        if ($this->throwOnNotFound) {
            throw new DomainNotFoundException("Domain not found: {$fullDomain}");
        }

        return null;
    }

    /**
     * 단일 도메인명으로 해결 시도 (캐시 → DB, 각각 대표 주소 → 별칭 순)
     *
     * 별칭 캐시를 대표 주소 DB 조회보다 먼저 보는 이유: 별칭으로 들어오는 요청마다
     * domain_configs 를 한 번씩 헛짚지 않기 위함이다. 호스트 하나는 대표 주소와 별칭 중
     * 한 곳에만 있으므로(DomainService::isDomainTaken) 순서가 결과를 바꾸지 않는다.
     * 별칭이 대표 주소로 올라갈 때는 DomainService가 그 호스트의 캐시를 지운다.
     */
    private function resolveByName(string $normalizedDomain): ?Domain
    {
        // 1. 메모리 캐시 확인
        if (isset(self::$memoryCache[$normalizedDomain])) {
            return self::$memoryCache[$normalizedDomain];
        }

        $domain = $this->cache->get($normalizedDomain)
            ?? $this->findCachedAlias($normalizedDomain)
            ?? $this->findCanonicalInDb($normalizedDomain)
            ?? $this->findAliasInDb($normalizedDomain);

        if ($domain !== null) {
            self::$memoryCache[$normalizedDomain] = $domain;
        }

        return $domain;
    }

    /**
     * 대표 주소로 찾기 (캐시 → DB). 별칭은 보지 않는다.
     */
    private function findCanonical(string $domainName): ?Domain
    {
        return $this->cache->get($domainName) ?? $this->findCanonicalInDb($domainName);
    }

    private function findCanonicalInDb(string $domainName): ?Domain
    {
        $domain = $this->repository->findByDomain($domainName);

        if ($domain !== null) {
            $this->cache->set($domain);
        }

        return $domain;
    }

    /**
     * 캐시된 별칭으로 찾기
     *
     * 캐시에는 가리키는 사이트의 ID·대표 주소만 있다. 그 대표 주소로 다시 찾은 사이트의
     * ID가 다르거나 없으면(그사이 주소 변경·사이트 삭제) 캐시를 버리고 DB에서 다시 찾게 한다.
     */
    private function findCachedAlias(string $host): ?Domain
    {
        if ($this->aliasRepository === null) {
            return null;
        }

        $cached = $this->cache->getAlias($host);
        if ($cached === null) {
            return null;
        }

        $domain = $this->findCanonical($cached['domain']);
        if ($domain !== null && $domain->getDomainId() === $cached['domain_id']) {
            return $domain;
        }

        $this->cache->deleteAlias($host);

        return null;
    }

    private function findAliasInDb(string $host): ?Domain
    {
        if ($this->aliasRepository === null) {
            return null;
        }

        $domainId = $this->aliasRepository->findDomainIdByHost($host);
        if ($domainId === null) {
            return null;
        }

        $domain = $this->repository->findById($domainId);
        if ($domain === null) {
            return null;
        }

        $this->cache->set($domain);
        $this->cache->setAlias($host, $domain);

        return $domain;
    }

    /**
     * 접근 가능한 Domain 해결 (활성 상태)
     *
     * @param string $domainName 도메인명
     * @return Domain|null
     */
    public function resolveAccessible(string $domainName): ?Domain
    {
        $domain = $this->resolve($domainName);

        if ($domain === null) {
            return null;
        }

        if (!$domain->isAccessible()) {
            return null;
        }

        return $domain;
    }

    /**
     * Domain 캐시 무효화
     *
     * 그 이름의 대표 주소 캐시와 별칭 캐시를 함께 지운다 — 호스트가 별칭에서 대표 주소로,
     * 또는 그 반대로 옮겨 갈 수 있어 호출자가 어느 쪽인지 따지지 않게 한다.
     *
     * @param string $domainName 도메인명 (대표 주소 또는 별칭 호스트)
     */
    public function invalidate(string $domainName): void
    {
        $normalizedDomain = $this->normalizeDomain($domainName);

        // 메모리 캐시 삭제
        unset(self::$memoryCache[$normalizedDomain]);

        // 파일 캐시 삭제
        $this->cache->delete($normalizedDomain);
        $this->cache->deleteAlias($normalizedDomain);
    }

    /**
     * Domain 캐시 갱신
     *
     * @param Domain $domain Domain 객체
     */
    public function refresh(Domain $domain): void
    {
        $normalizedDomain = $this->normalizeDomain($domain->getDomain());

        // 캐시 업데이트
        $this->cache->set($domain);
        self::$memoryCache[$normalizedDomain] = $domain;
    }

    /**
     * 전체 캐시 초기화
     */
    public function flushAll(): int
    {
        self::$memoryCache = [];
        return $this->cache->flush();
    }

    /**
     * 예외 발생 모드 설정
     */
    public function setThrowOnNotFound(bool $throw): self
    {
        $this->throwOnNotFound = $throw;
        return $this;
    }

    /**
     * 도메인 정규화 (소문자, 공백 제거 — 포트 유지)
     */
    protected function normalizeDomain(string $domain): string
    {
        return strtolower(trim($domain));
    }

    /**
     * 포트 번호 제거 (localhost:8080 → localhost)
     */
    private function stripPort(string $domain): string
    {
        if (($pos = strpos($domain, ':')) !== false) {
            return substr($domain, 0, $pos);
        }

        return $domain;
    }

    /**
     * 첫 번째 서브도메인 세그먼트 제거
     *
     * demo.example.co.kr → example.co.kr
     * example.co.kr → null (2차 도메인 이하는 폴백 불가)
     * coway.localhost → localhost (localhost 예외 — 개발환경)
     */
    private function stripFirstSubdomain(string $domain): ?string
    {
        $parts = explode('.', $domain);

        // localhost 예외: coway.localhost → localhost
        if (count($parts) === 2 && $parts[1] === 'localhost') {
            return 'localhost';
        }

        // 최소 3세그먼트여야 서브도메인 제거 가능 (sub.domain.tld)
        if (count($parts) < 3) {
            return null;
        }

        return implode('.', array_slice($parts, 1));
    }

    /**
     * 포트 번호 추출 (localhost:8080 → 8080, localhost → null)
     */
    private function extractPort(string $domain): ?string
    {
        if (($pos = strpos($domain, ':')) !== false) {
            return substr($domain, $pos + 1);
        }

        return null;
    }

    /**
     * www. 접두사 제거 (www.mublo.kr → mublo.kr)
     */
    private function stripWww(string $domain): string
    {
        if (str_starts_with($domain, 'www.')) {
            return substr($domain, 4);
        }

        return $domain;
    }

    /**
     * 메모리 캐시 초기화 (테스트용)
     */
    public static function clearMemoryCache(): void
    {
        self::$memoryCache = [];
    }
}
