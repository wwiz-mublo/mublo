<?php
declare(strict_types=1);

namespace Mublo\Core\Registry;

use Closure;
use Mublo\Contract\Category\CategoryProviderInterface;
use Mublo\Core\Extension\ExtensionRegistrationScope;

/**
 * 카테고리 Provider 레지스트리
 *
 * Package가 boot()에서 Provider(또는 Closure 팩토리)를 등록하면,
 * ViewContext의 category() 메서드로 스킨에서 트리를 조회할 수 있다.
 *
 * - Lazy 지원: Closure를 등록하면 최초 getTree() 호출 시에만 인스턴스 생성
 * - 요청 내 캐싱: (key + domainId + depth) 조합당 1회만 Provider 호출
 *
 * 사용 예:
 * ```php
 * // Package boot()에서 lazy 등록
 * $registry->register('shop', fn() => new ShopCategoryProvider($categoryService));
 *
 * // 스킨에서 조회
 * $tree = $this->category('shop');           // 전체 depth
 * $tree = $this->category('shop', 2);        // 2 depth까지
 * ```
 */
class CategoryProviderRegistry
{
    /** @var array<string, CategoryProviderInterface|Closure> */
    private array $providers = [];

    /** @var array<string, string> */
    private array $providerOwners = [];

    /** @var array<string, array> 요청 내 캐시 ["{key}:{domainId}:{depth}" => tree] */
    private array $cache = [];

    /**
     * Provider 등록 (인스턴스 또는 Closure 팩토리)
     *
     * @param string $key 고유 키 (예: 'shop', 'rental')
     * @param CategoryProviderInterface|Closure $provider 인스턴스 또는 팩토리
     * @throws \InvalidArgumentException 키 중복 또는 타입 불일치 시
     */
    public function register(string $key, CategoryProviderInterface|Closure $provider): void
    {
        if (isset($this->providers[$key])) {
            throw new \InvalidArgumentException(
                "CategoryProvider '{$key}' is already registered"
            );
        }

        $this->providers[$key] = $provider;

        $owner = ExtensionRegistrationScope::currentOwner();
        if ($owner !== null) {
            $this->providerOwners[$key] = $owner;
            ExtensionRegistrationScope::recordCommit(function () use ($key, $owner): void {
                if (($this->providerOwners[$key] ?? null) === $owner) {
                    unset($this->providerOwners[$key]);
                }
            });
            ExtensionRegistrationScope::recordUndo(function () use ($key, $owner): void {
                if (($this->providerOwners[$key] ?? null) !== $owner) {
                    return;
                }

                unset($this->providers[$key], $this->providerOwners[$key]);
                foreach (array_keys($this->cache) as $cacheKey) {
                    if (str_starts_with($cacheKey, $key . ':')) {
                        unset($this->cache[$cacheKey]);
                    }
                }
            });
        }
    }

    /**
     * 카테고리 트리 조회
     *
     * @param string $key Provider 키
     * @param int $domainId 도메인 ID
     * @param int|null $depth 최대 depth (null = 전체)
     * @return array 규격화된 카테고리 트리 (미등록 시 빈 배열)
     */
    public function getTree(string $key, int $domainId, ?int $depth = null): array
    {
        if (!isset($this->providers[$key])) {
            return [];
        }

        $cacheKey = "{$key}:{$domainId}:" . ($depth ?? 'all');

        if (!array_key_exists($cacheKey, $this->cache)) {
            $provider = $this->resolveProvider($key);
            $this->cache[$cacheKey] = $provider->getTree($domainId, $depth);
        }

        return $this->cache[$cacheKey];
    }

    /**
     * 등록된 Provider 키 목록
     *
     * @return string[]
     */
    public function keys(): array
    {
        return array_keys($this->providers);
    }

    /**
     * Provider 등록 여부
     */
    public function has(string $key): bool
    {
        return isset($this->providers[$key]);
    }

    /**
     * Closure → 인스턴스 resolve (lazy)
     */
    private function resolveProvider(string $key): CategoryProviderInterface
    {
        if ($this->providers[$key] instanceof Closure) {
            $instance = ($this->providers[$key])();

            if (!($instance instanceof CategoryProviderInterface)) {
                throw new \InvalidArgumentException(
                    "CategoryProvider factory for '{$key}' must return " . CategoryProviderInterface::class
                );
            }

            $this->providers[$key] = $instance;
        }

        return $this->providers[$key];
    }
}
