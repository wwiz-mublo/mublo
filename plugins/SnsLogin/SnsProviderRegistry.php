<?php
namespace Mublo\Plugin\SnsLogin;

use Mublo\Plugin\SnsLogin\Contract\SnsProviderInterface;

/**
 * SNS 제공자 레지스트리
 *
 * 등록된 제공자 관리 + 활성화 여부 필터링
 */
class SnsProviderRegistry
{
    /** @var SnsProviderInterface[] */
    private array $providers = [];

    /** @var array 활성화 설정 ['naver' => true, 'kakao' => false, ...] */
    private array $enabled = [];

    public function register(SnsProviderInterface $provider): void
    {
        $this->providers[$provider->getName()] = $provider;
    }

    public function setEnabled(array $enabledMap): void
    {
        $this->enabled = $enabledMap;
    }

    public function get(string $name): ?SnsProviderInterface
    {
        return $this->providers[$name] ?? null;
    }

    /** 활성화된 제공자만 반환 (로그인 버튼 렌더링용) */
    public function getActiveProviders(): array
    {
        if (empty($this->enabled)) {
            return [];
        }
        return array_filter(
            $this->providers,
            fn($p) => !empty($this->enabled[$p->getName()])
        );
    }

    public function has(string $name): bool
    {
        return isset($this->providers[$name]);
    }
}
