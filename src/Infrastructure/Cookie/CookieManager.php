<?php

namespace Mublo\Infrastructure\Cookie;

use Mublo\Core\Cookie\CookieInterface;

/**
 * CookieManager
 *
 * PHP 쿠키 관리 인프라 클래스 (CookieInterface 구현)
 * - 쿠키 설정/조회/삭제
 * - 보안 설정 (HttpOnly, Secure, SameSite)
 */
class CookieManager implements CookieInterface
{
    private array $config;
    private array $queued = [];

    public function __construct()
    {
        $this->config = $this->loadConfig();
    }

    /**
     * 보안 설정 로드
     */
    private function loadConfig(): array
    {
        $configPath = MUBLO_CONFIG_PATH . '/security.php';

        if (file_exists($configPath)) {
            $config = require $configPath;
            return $config['cookie'] ?? $this->getDefaultConfig();
        }

        return $this->getDefaultConfig();
    }

    /**
     * 기본 설정
     */
    private function getDefaultConfig(): array
    {
        return [
            'prefix' => 'MUBLO_',
            'path' => '/',
            'domain' => '',
            'secure' => false,
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }

    /**
     * 쿠키 설정
     */
    public function set(
        string $name,
        string $value,
        int $minutes = 0,
        string $path = '/',
        string $domain = '',
        bool $secure = false,
        bool $httpOnly = true,
        string $sameSite = 'Lax'
    ): void {
        $prefixedName = $this->getPrefixedName($name);
        $expire = $minutes > 0 ? time() + ($minutes * 60) : 0;

        // 기본값 사용
        $path = $path ?: ($this->config['path'] ?? '/');
        $domain = $domain ?: ($this->config['domain'] ?? '');
        $secure = $secure ?: ($this->config['secure'] ?? false);
        $httpOnly = $httpOnly && ($this->config['httponly'] ?? true);
        $sameSite = $sameSite ?: ($this->config['samesite'] ?? 'Lax');

        setcookie($prefixedName, $value, [
            'expires' => $expire,
            'path' => $path,
            'domain' => $domain,
            'secure' => $secure,
            'httponly' => $httpOnly,
            'samesite' => $sameSite,
        ]);

        // 현재 요청에서도 사용 가능하도록
        $_COOKIE[$prefixedName] = $value;
    }

    /**
     * 쿠키 값 조회
     */
    public function get(string $name, mixed $default = null): mixed
    {
        $prefixedName = $this->getPrefixedName($name);
        return $_COOKIE[$prefixedName] ?? $default;
    }

    /**
     * 쿠키 존재 여부
     */
    public function has(string $name): bool
    {
        $prefixedName = $this->getPrefixedName($name);
        return isset($_COOKIE[$prefixedName]);
    }

    /**
     * 쿠키 삭제
     */
    public function delete(string $name, string $path = '/', string $domain = ''): void
    {
        $prefixedName = $this->getPrefixedName($name);
        $path = $path ?: ($this->config['path'] ?? '/');
        $domain = $domain ?: ($this->config['domain'] ?? '');

        setcookie($prefixedName, '', [
            'expires' => time() - 3600,
            'path' => $path,
            'domain' => $domain,
            'secure' => $this->config['secure'] ?? false,
            'httponly' => $this->config['httponly'] ?? true,
            'samesite' => $this->config['samesite'] ?? 'Lax',
        ]);

        unset($_COOKIE[$prefixedName]);
    }

    /**
     * 영구 쿠키 설정 (1년)
     */
    public function forever(string $name, string $value): void
    {
        $this->set($name, $value, 60 * 24 * 365); // 1년 = 525600분
    }

    /**
     * 모든 쿠키 조회 (prefix 제거)
     */
    public function all(): array
    {
        $prefix = $this->config['prefix'] ?? 'MUBLO_';
        $prefixLength = strlen($prefix);
        $cookies = [];

        foreach ($_COOKIE as $name => $value) {
            if (str_starts_with($name, $prefix)) {
                $unprefixedName = substr($name, $prefixLength);
                $cookies[$unprefixedName] = $value;
            }
        }

        return $cookies;
    }

    /**
     * prefix가 붙은 쿠키 이름 반환
     */
    private function getPrefixedName(string $name): string
    {
        $prefix = $this->config['prefix'] ?? 'MUBLO_';

        // 이미 prefix가 붙어있으면 그대로 반환
        if (str_starts_with($name, $prefix)) {
            return $name;
        }

        return $prefix . $name;
    }

    /**
     * 원본 쿠키 이름 반환 (prefix 제거)
     */
    private function getUnprefixedName(string $name): string
    {
        $prefix = $this->config['prefix'] ?? 'MUBLO_';

        if (str_starts_with($name, $prefix)) {
            return substr($name, strlen($prefix));
        }

        return $name;
    }
}
