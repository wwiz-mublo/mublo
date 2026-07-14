<?php
namespace Mublo\Service\Domain;

use Mublo\Entity\Domain\Domain;
use Mublo\Infrastructure\Cache\DomainCache;
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
 * 조회 흐름:
 * 1. 메모리 캐시 (static, 같은 요청 내)
 * 2. 파일 캐시 (DomainCache, 요청 간)
 * 3. DB 조회 (DomainRepository, 캐시 미스 시)
 */
class DomainResolver
{
    protected DomainCache $cache;
    protected DomainRepository $repository;

    /**
     * 메모리 캐시 (같은 요청 내 재사용)
     */
    protected static array $memoryCache = [];

    /**
     * 해결 실패 시 예외 발생 여부
     */
    protected bool $throwOnNotFound = false;

    public function __construct(DomainCache $cache, DomainRepository $repository)
    {
        $this->cache = $cache;
        $this->repository = $repository;
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
     * 단일 도메인명으로 해결 시도 (캐시 → DB)
     */
    private function resolveByName(string $normalizedDomain): ?Domain
    {
        // 1. 메모리 캐시 확인
        if (isset(self::$memoryCache[$normalizedDomain])) {
            return self::$memoryCache[$normalizedDomain];
        }

        // 2. 파일 캐시 확인
        $domain = $this->cache->get($normalizedDomain);

        if ($domain !== null) {
            self::$memoryCache[$normalizedDomain] = $domain;
            return $domain;
        }

        // 3. DB 조회
        $domain = $this->repository->findByDomain($normalizedDomain);

        if ($domain !== null) {
            $this->cache->set($domain);
            self::$memoryCache[$normalizedDomain] = $domain;
            return $domain;
        }

        return null;
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
     * @param string $domainName 도메인명
     */
    public function invalidate(string $domainName): void
    {
        $normalizedDomain = $this->normalizeDomain($domainName);

        // 메모리 캐시 삭제
        unset(self::$memoryCache[$normalizedDomain]);

        // 파일 캐시 삭제
        $this->cache->delete($normalizedDomain);
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
