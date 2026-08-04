<?php

namespace Tests\Unit\Core\Middleware;

use PHPUnit\Framework\TestCase;
use Mublo\Core\Middleware\MiddlewarePipeline;
use Mublo\Core\Middleware\MiddlewareInterface;
use Mublo\Core\Http\Request;
use Mublo\Core\Context\Context;
use Mublo\Core\Container\DependencyContainer;
use Mublo\Core\Response\AbstractResponse;
use Mublo\Core\Response\JsonResponse;

/**
 * MiddlewarePipelineTest
 *
 * 미들웨어 파이프라인 실행 테스트
 */
class MiddlewarePipelineTest extends TestCase
{
    private DependencyContainer $container;
    private Request $request;
    private Context $context;

    protected function setUp(): void
    {
        parent::setUp();
        DependencyContainer::resetInstance();
        $this->container = DependencyContainer::getInstance();
        $this->request = new Request('GET', '/test', [], [], ['HTTP_HOST' => 'example.com']);
        $this->context = $this->createMock(Context::class);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        DependencyContainer::resetInstance();
    }

    // ─── 기본 실행 ───

    public function testPipelineRunsDestinationWithNoMiddleware(): void
    {
        $pipeline = new MiddlewarePipeline($this->container);

        $called = false;
        $destination = function (Request $req, Context $ctx) use (&$called): AbstractResponse {
            $called = true;
            return JsonResponse::success([]);
        };

        $result = $pipeline->run($this->request, $this->context, $destination);

        $this->assertTrue($called);
        $this->assertInstanceOf(AbstractResponse::class, $result);
    }

    public function testPipeCallsHandleOnMiddleware(): void
    {
        $pipeline = new MiddlewarePipeline($this->container);

        $executed = false;
        $middleware = new class($executed) implements MiddlewareInterface {
            public function __construct(private bool &$executed) {}
            public function handle(Request $request, Context $context, callable $next): AbstractResponse
            {
                $this->executed = true;
                return $next($request, $context);
            }
        };

        $pipeline->pipe($middleware);

        $pipeline->run($this->request, $this->context, function () {
            return JsonResponse::success([]);
        });

        $this->assertTrue($executed);
    }

    public function testMiddlewareCanShortCircuit(): void
    {
        $pipeline = new MiddlewarePipeline($this->container);
        $destinationCalled = false;

        $blockingMiddleware = new class implements MiddlewareInterface {
            public function handle(Request $request, Context $context, callable $next): AbstractResponse
            {
                return JsonResponse::error('접근 거부');
            }
        };

        $pipeline->pipe($blockingMiddleware);

        $pipeline->run($this->request, $this->context, function () use (&$destinationCalled) {
            $destinationCalled = true;
            return JsonResponse::success([]);
        });

        $this->assertFalse($destinationCalled);
    }

    // ─── 실행 순서 ───

    public function testMiddlewareExecutesInRegistrationOrder(): void
    {
        $pipeline = new MiddlewarePipeline($this->container);
        $order = [];

        $first = new class($order, 'first') implements MiddlewareInterface {
            public function __construct(private array &$order, private string $name) {}
            public function handle(Request $request, Context $context, callable $next): AbstractResponse
            {
                $this->order[] = $this->name;
                return $next($request, $context);
            }
        };

        $second = new class($order, 'second') implements MiddlewareInterface {
            public function __construct(private array &$order, private string $name) {}
            public function handle(Request $request, Context $context, callable $next): AbstractResponse
            {
                $this->order[] = $this->name;
                return $next($request, $context);
            }
        };

        $pipeline->pipe($first)->pipe($second);

        $pipeline->run($this->request, $this->context, function () {
            return JsonResponse::success([]);
        });

        $this->assertSame(['first', 'second'], $order);
    }

    public function testThroughAddsMultipleMiddlewares(): void
    {
        $pipeline = new MiddlewarePipeline($this->container);
        $count = 0;

        $middlewareFactory = function () use (&$count): MiddlewareInterface {
            return new class($count) implements MiddlewareInterface {
                public function __construct(private int &$count) {}
                public function handle(Request $request, Context $context, callable $next): AbstractResponse
                {
                    $this->count++;
                    return $next($request, $context);
                }
            };
        };

        $pipeline->through([$middlewareFactory(), $middlewareFactory(), $middlewareFactory()]);

        $pipeline->run($this->request, $this->context, function () {
            return JsonResponse::success([]);
        });

        $this->assertSame(3, $count);
    }

    // ─── 클래스명으로 등록 ───

    public function testPipeAcceptsClassNameAndResolvesViaContainer(): void
    {
        $this->container->set(PassthroughMiddleware::class, new PassthroughMiddleware());

        $pipeline = new MiddlewarePipeline($this->container);
        $pipeline->pipe(PassthroughMiddleware::class);

        $called = false;
        $pipeline->run($this->request, $this->context, function () use (&$called) {
            $called = true;
            return JsonResponse::success([]);
        });

        $this->assertTrue($called);
    }

    public function testPipeThrowsForUnresolvableClassName(): void
    {
        $pipeline = new MiddlewarePipeline($this->container);
        $pipeline->pipe('NonExistentMiddlewareClass');

        $this->expectException(\RuntimeException::class);

        $pipeline->run($this->request, $this->context, function () {
            return JsonResponse::success([]);
        });
    }

    // ─── 미들웨어 체이닝 반환 ───

    public function testPipeReturnsSelfForChaining(): void
    {
        $pipeline = new MiddlewarePipeline($this->container);
        $middleware = new PassthroughMiddleware();

        $result = $pipeline->pipe($middleware);

        $this->assertSame($pipeline, $result);
    }

    public function testThroughReturnsSelfForChaining(): void
    {
        $pipeline = new MiddlewarePipeline($this->container);

        $result = $pipeline->through([new PassthroughMiddleware()]);

        $this->assertSame($pipeline, $result);
    }
}

/**
 * 테스트용 통과 미들웨어
 */
class PassthroughMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, Context $context, callable $next): AbstractResponse
    {
        return $next($request, $context);
    }
}
