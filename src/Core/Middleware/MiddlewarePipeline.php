<?php

namespace Mublo\Core\Middleware;

use Mublo\Core\Http\Request;
use Mublo\Core\Context\Context;
use Mublo\Core\Container\DependencyContainer;
use Mublo\Core\Response\AbstractResponse;

/**
 * Middleware Pipeline
 *
 * 미들웨어 체인 실행 관리
 */
class MiddlewarePipeline
{
    private DependencyContainer $container;
    private array $middlewares = [];

    public function __construct(DependencyContainer $container)
    {
        $this->container = $container;
    }

    /**
     * 미들웨어 추가
     *
     * @param string|MiddlewareInterface $middleware 미들웨어 클래스명 또는 인스턴스
     */
    public function pipe(string|MiddlewareInterface $middleware): self
    {
        $this->middlewares[] = $middleware;
        return $this;
    }

    /**
     * 여러 미들웨어 추가
     */
    public function through(array $middlewares): self
    {
        foreach ($middlewares as $middleware) {
            $this->pipe($middleware);
        }
        return $this;
    }

    /**
     * 파이프라인 실행
     *
     * @param Request $request
     * @param Context $context
     * @param callable $destination 최종 핸들러 (컨트롤러 실행)
     * @return AbstractResponse
     */
    public function run(Request $request, Context $context, callable $destination): AbstractResponse
    {
        $pipeline = array_reduce(
            array_reverse($this->middlewares),
            $this->carry(),
            $destination
        );

        return $pipeline($request, $context);
    }

    /**
     * 미들웨어 체인 클로저 생성
     */
    private function carry(): \Closure
    {
        return function (callable $next, string|MiddlewareInterface $middleware) {
            return function (Request $request, Context $context) use ($next, $middleware) {
                $middlewareInstance = $this->resolveMiddleware($middleware);

                return $middlewareInstance->handle($request, $context, function ($req, $ctx) use ($next) {
                    return $next($req, $ctx);
                });
            };
        };
    }

    /**
     * 미들웨어 인스턴스 해결
     */
    private function resolveMiddleware(string|MiddlewareInterface $middleware): MiddlewareInterface
    {
        if ($middleware instanceof MiddlewareInterface) {
            return $middleware;
        }

        // 컨테이너를 통해 미들웨어 해결 (auto-wiring 지원)
        try {
            return $this->container->get($middleware);
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                "Middleware '{$middleware}' could not be resolved: {$e->getMessage()}",
                0,
                $e
            );
        }
    }
}
