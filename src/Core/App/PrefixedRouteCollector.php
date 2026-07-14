<?php
namespace Mublo\Core\App;

use FastRoute\RouteCollector;

/**
 * Class PrefixedRouteCollector
 *
 * Plugin/Package 라우트에 자동으로 접두사를 붙여주는 래퍼
 *
 * 규칙:
 * - Front 라우트: /{prefix}/...
 * - Admin 라우트: /admin/{prefix}/...
 *
 * 예시 (Shop 패키지):
 * - /goods → /shop/goods
 * - /admin/goods → /admin/shop/goods
 */
class PrefixedRouteCollector
{
    private const ADMIN_PREFIX = '/admin';

    private RouteCollector $collector;
    private string $prefix;
    private string $type; // 'plugin' or 'package'

    public function __construct(RouteCollector $collector, string $prefix, string $type)
    {
        $this->collector = $collector;
        $this->prefix = $prefix;
        $this->type = $type;
    }

    /**
     * 라우트 추가 (접두사 자동 적용)
     *
     * @param string|string[] $httpMethod HTTP 메서드
     * @param string $route URL 패턴
     * @param mixed $handler 핸들러
     */
    public function addRoute($httpMethod, string $route, $handler): void
    {
        $prefixedRoute = $this->applyPrefix($route);
        $this->collector->addRoute($httpMethod, $prefixedRoute, $handler);
    }

    /**
     * 접두사 없이 원본 경로 그대로 라우트 등록
     *
     * 패키지의 일부 라우트가 prefix 없는 루트 경로를 사용해야 할 때
     * 예: Board 패키지의 /community (→ /board/community 대신 /community)
     */
    public function addRawRoute($httpMethod, string $route, $handler): void
    {
        $this->collector->addRoute($httpMethod, $route, $handler);
    }

    /**
     * URL에 접두사 적용
     *
     * @param string $route 원본 URL
     * @return string 접두사가 적용된 URL
     */
    private function applyPrefix(string $route): string
    {
        // 빈 라우트 또는 "/" → "/{prefix}"
        if ($route === '' || $route === '/') {
            return '/' . $this->prefix;
        }

        // /admin/... → /admin/{prefix}/...
        if (str_starts_with($route, self::ADMIN_PREFIX)) {
            $adminPath = substr($route, strlen(self::ADMIN_PREFIX));

            if ($adminPath === '' || $adminPath === '/') {
                return self::ADMIN_PREFIX . '/' . $this->prefix;
            }

            return self::ADMIN_PREFIX . '/' . $this->prefix . $adminPath;
        }

        // 일반 라우트 → /{prefix}/...
        return '/' . $this->prefix . $route;
    }

    /**
     * 라우트 그룹 (FastRoute 호환)
     *
     * @param string $prefix 그룹 접두사
     * @param callable $callback 콜백
     */
    public function addGroup(string $prefix, callable $callback): void
    {
        // 그룹 내에서도 접두사 적용
        $groupPrefix = $this->applyPrefix($prefix);
        $this->collector->addGroup($groupPrefix, $callback);
    }

    /**
     * 현재 접두사 반환
     */
    public function getPrefix(): string
    {
        return $this->prefix;
    }

    /**
     * 타입 반환 (plugin/package)
     */
    public function getType(): string
    {
        return $this->type;
    }
}
