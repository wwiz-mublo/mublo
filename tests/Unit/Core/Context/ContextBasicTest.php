<?php
/**
 * tests/Unit/Core/Context/ContextBasicTest.php
 *
 * 매우 간단한 Context 테스트
 * 실제 Domain/Request 객체 생성을 최소화
 */

namespace Tests\Unit\Core\Context;

use Tests\TestCase;
use Mublo\Core\Http\Request;

class ContextBasicTest extends TestCase
{
    /**
     * Request 객체 생성 테스트
     */
    public function testCanCreateRequest(): void
    {
        $request = new Request('GET', '/');

        $this->assertEquals('GET', $request->getMethod());
        $this->assertEquals('/', $request->getUri());
    }

    /**
     * Request Query 파라미터 테스트
     */
    public function testRequestQueryParameters(): void
    {
        $request = new Request('GET', '/test', ['page' => 1, 'limit' => 10]);

        $this->assertEquals(1, $request->get('page'));
        $this->assertEquals(10, $request->get('limit'));
    }

    /**
     * Request Body 테스트
     */
    public function testRequestBody(): void
    {
        $request = new Request('POST', '/test', [], ['name' => 'John', 'age' => 30]);

        $this->assertEquals('John', $request->input('name'));
        $this->assertEquals(30, $request->input('age'));
    }

    /**
     * Request HTTP Method 테스트
     */
    public function testHttpMethods(): void
    {
        $get = new Request('GET', '/');
        $post = new Request('POST', '/');
        $put = new Request('PUT', '/');
        $delete = new Request('DELETE', '/');

        $this->assertEquals('GET', $get->getMethod());
        $this->assertEquals('POST', $post->getMethod());
        $this->assertEquals('PUT', $put->getMethod());
        $this->assertEquals('DELETE', $delete->getMethod());
    }
}
