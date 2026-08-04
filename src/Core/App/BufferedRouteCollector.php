<?php
declare(strict_types=1);
namespace Mublo\Core\App;

use FastRoute\RouteCollector;

/**
 * 확장 routes.php 한 개의 선언을 실제 FastRoute 수집기와 분리해 임시 보관한다.
 *
 * 확장 콜백이 중간에 실패하거나 기존 라우트와 충돌해도 이 버퍼를 버리면 되므로,
 * 일부 라우트만 남는 상태 없이 확장 단위로 등록하거나 건너뛸 수 있다.
 */
final class BufferedRouteCollector extends RouteCollector
{
    /** @var array<int, array{method: string|string[], route: string, handler: mixed}> */
    private array $routes = [];

    private string $groupPrefix = '';

    /**
     * 부모 수집기의 Parser/DataGenerator는 사용하지 않는다.
     * 선언은 commit 시 실제 RouteCollector가 같은 방식으로 파싱한다.
     */
    public function __construct()
    {
    }

    public function addRoute($httpMethod, $route, $handler): void
    {
        $this->routes[] = [
            'method' => $httpMethod,
            'route' => $this->groupPrefix . (string) $route,
            'handler' => $handler,
        ];
    }

    public function addGroup($prefix, callable $callback): void
    {
        $previous = $this->groupPrefix;
        $this->groupPrefix .= (string) $prefix;

        try {
            $callback($this);
        } finally {
            $this->groupPrefix = $previous;
        }
    }

    /**
     * @return array<int, array{method: string|string[], route: string, handler: mixed}>
     */
    public function routes(): array
    {
        return $this->routes;
    }

    /**
     * 버퍼는 완성된 FastRoute dispatch data를 만들지 않는다.
     */
    public function getData(): array
    {
        throw new \LogicException('BufferedRouteCollector must be committed to a real RouteCollector.');
    }
}
