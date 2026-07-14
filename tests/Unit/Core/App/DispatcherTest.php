<?php

namespace Tests\Unit\Core\App;

use Mublo\Core\App\Dispatcher;
use Mublo\Core\Context\Context;
use Mublo\Core\Http\Request;
use Mublo\Core\Response\JsonResponse;
use Mublo\Exception\HttpNotFoundException;
use Tests\TestCase;

class DispatcherTest extends TestCase
{
    public function testDispatchInjectsParamsAndContext(): void
    {
        $dispatcher = new Dispatcher($this->getContainer());
        $context = new Context(new Request('GET', '/health'));

        $route = [
            'controller' => DispatcherFixtureController::class,
            'method' => 'show',
            'params' => ['id' => 7],
            'middleware' => [],
        ];

        $response = $dispatcher->dispatch($route, $context);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $data = $response->getData();

        $this->assertSame('success', $data['result']);
        $this->assertSame(7, $data['data']['id']);
        $this->assertSame('GET', $data['data']['method']);
    }

    public function testDispatchRejectsNonPublicMethod(): void
    {
        $dispatcher = new Dispatcher($this->getContainer());
        $context = new Context(new Request('GET', '/'));

        $route = [
            'controller' => DispatcherNonPublicFixtureController::class,
            'method' => 'hidden',
            'params' => [],
            'middleware' => [],
        ];

        $this->expectException(HttpNotFoundException::class);
        $this->expectExceptionMessage('Method not found');

        $dispatcher->dispatch($route, $context);
    }

    public function testDispatchRequiresResponseObject(): void
    {
        $dispatcher = new Dispatcher($this->getContainer());
        $context = new Context(new Request('GET', '/'));

        $route = [
            'controller' => DispatcherInvalidReturnFixtureController::class,
            'method' => 'index',
            'params' => [],
            'middleware' => [],
        ];

        $this->expectException(\TypeError::class);

        $dispatcher->dispatch($route, $context);
    }
}

class DispatcherFixtureController
{
    public function show(array $params, Context $context): JsonResponse
    {
        return JsonResponse::success([
            'id' => (int) ($params['id'] ?? 0),
            'method' => $context->getRequest()->getMethod(),
        ]);
    }
}

class DispatcherNonPublicFixtureController
{
    private function hidden(): JsonResponse
    {
        return JsonResponse::success();
    }
}

class DispatcherInvalidReturnFixtureController
{
    public function index(): string
    {
        return 'invalid';
    }
}

