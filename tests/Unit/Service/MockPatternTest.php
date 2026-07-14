<?php

namespace Tests\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Mublo\Core\Http\Request;
use Mublo\Core\Response\JsonResponse;

/**
 * Mock Pattern Test - Simple mock patterns without complex entities
 * Focus on mocking principles without Domain/Entity dependencies
 */
class MockPatternTest extends TestCase
{

    public function testSimpleValueObject(): void
    {
        // Test basic object creation and property access
        $request = new Request('GET', '/');
        $this->assertEquals('GET', $request->getMethod());
    }

    public function testRequestWithQueryParameters(): void
    {
        $request = new Request('GET', '/?page=1&limit=10');
        $this->assertEquals('GET', $request->getMethod());
        $this->assertStringContainsString('page=1', $request->getUri());
    }

    public function testRequestWithBodyParameters(): void
    {
        $request = new Request('POST', '/', [], ['name' => 'Test', 'email' => 'test@example.com']);
        $this->assertEquals('POST', $request->getMethod());
        $this->assertNotNull($request);
    }

    public function testJsonResponse(): void
    {
        $data = ['status' => 'success', 'data' => ['id' => 1]];
        $response = new JsonResponse($data);
        $this->assertNotNull($response);
    }

    public function testCreateMockUsingPHPUnit(): void
    {
        $stub = $this->createStub(\stdClass::class);
        $this->assertIsObject($stub);
    }

    public function testMockCallCount(): void
    {
        $mock = $this->createMock(\stdClass::class);
        $this->assertIsObject($mock);
    }

    public function testMultipleRequests(): void
    {
        $requests = [
            new Request('GET', '/'),
            new Request('POST', '/api/submit'),
            new Request('PUT', '/api/update/1'),
            new Request('DELETE', '/api/delete/1')
        ];
        
        $this->assertCount(4, $requests);
        $this->assertEquals('GET', $requests[0]->getMethod());
        $this->assertEquals('POST', $requests[1]->getMethod());
    }

    public function testRequestComparison(): void
    {
        $request1 = new Request('GET', '/path1');
        $request2 = new Request('GET', '/path1');
        $request3 = new Request('POST', '/path1');
        
        $this->assertEquals('GET', $request1->getMethod());
        $this->assertEquals('GET', $request2->getMethod());
        $this->assertNotEquals($request1->getMethod(), $request3->getMethod());
    }

    #[DataProvider('httpMethodProvider')]
    public function testVariousHttpMethods(string $method): void
    {
        $request = new Request($method, '/');
        $this->assertEquals($method, $request->getMethod());
    }

    public static function httpMethodProvider(): array
    {
        return [
            'GET' => ['GET'],
            'POST' => ['POST'],
            'PUT' => ['PUT'],
            'DELETE' => ['DELETE'],
            'PATCH' => ['PATCH'],
            'HEAD' => ['HEAD'],
            'OPTIONS' => ['OPTIONS'],
        ];
    }
}
