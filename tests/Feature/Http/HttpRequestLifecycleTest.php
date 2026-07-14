<?php

namespace Tests\Feature\Http;

use Tests\TestCase;
use Mublo\Core\Http\Request;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Result\Result;
use Mublo\Core\Context\Context;

/**
 * HttpRequestLifecycleTest
 *
 * HTTP 요청 생명주기 Feature 테스트
 * - Request 생성 및 다양한 속성 조회
 * - AJAX / JSON 요청 판별
 * - HTTP 헤더 처리
 * - Bearer 토큰 추출
 * - PayloadType 판별
 * - JsonResponse 생성
 * - Result 패턴 통합
 * - Context와 Request 통합
 */
class HttpRequestLifecycleTest extends TestCase
{
    // =========================================================================
    // AJAX 요청 판별
    // =========================================================================

    public function testIsAjaxReturnsTrueWithXRequestedWithHeader(): void
    {
        $request = new Request('POST', '/api/data', [], [], [
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ]);

        $this->assertTrue($request->isAjax());
    }

    public function testIsAjaxReturnsFalseWithoutHeader(): void
    {
        $request = new Request('POST', '/api/data');
        $this->assertFalse($request->isAjax());
    }

    public function testIsAjaxIsCaseInsensitive(): void
    {
        $request = new Request('POST', '/', [], [], [
            'HTTP_X_REQUESTED_WITH' => 'xmlhttprequest', // 소문자
        ]);

        $this->assertTrue($request->isAjax());
    }

    // =========================================================================
    // JSON 요청 판별
    // =========================================================================

    public function testIsJsonReturnsTrueForJsonContentType(): void
    {
        $request = new Request('POST', '/api/create', [], ['key' => 'val'], [
            'CONTENT_TYPE' => 'application/json',
        ]);

        $this->assertTrue($request->isJson());
        $this->assertEquals(Request::PAYLOAD_JSON, $request->getPayloadType());
    }

    public function testIsJsonReturnsFalseForFormPost(): void
    {
        $request = new Request('POST', '/form', [], ['name' => 'test']);
        $this->assertFalse($request->isJson());
    }

    public function testGetPayloadTypeForGetRequest(): void
    {
        $request = new Request('GET', '/list', ['page' => 1]);
        $this->assertEquals(Request::PAYLOAD_QUERY, $request->getPayloadType());
    }

    public function testGetPayloadTypeForPostRequest(): void
    {
        $request = new Request('POST', '/submit', [], ['field' => 'value']);
        $this->assertEquals(Request::PAYLOAD_FORM, $request->getPayloadType());
    }

    // =========================================================================
    // JSON 입력
    // =========================================================================

    public function testSetAndGetJsonInput(): void
    {
        $request = new Request('POST', '/api/create');
        $jsonData = ['title' => '테스트', 'content' => '내용'];

        $request->setJsonInput($jsonData);

        $this->assertEquals($jsonData, $request->getJsonInput());
        $this->assertEquals($jsonData, $request->json());
        $this->assertEquals('테스트', $request->json('title'));
        $this->assertNull($request->json('nonexistent'));
        $this->assertEquals('default', $request->json('nonexistent', 'default'));
    }

    // =========================================================================
    // HTTP 헤더
    // =========================================================================

    public function testHeaderRetrieval(): void
    {
        $request = new Request('GET', '/', [], [], [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer mytoken123',
        ]);

        $this->assertEquals('application/json', $request->header('Accept'));
        $this->assertEquals('Bearer mytoken123', $request->header('Authorization'));
    }

    public function testHeaderReturnsDefaultForMissingHeader(): void
    {
        $request = new Request('GET', '/');
        $this->assertNull($request->header('X-Custom-Header'));
        $this->assertEquals('default', $request->header('X-Custom-Header', 'default'));
    }

    // =========================================================================
    // Bearer 토큰
    // =========================================================================

    public function testBearerTokenExtraction(): void
    {
        $request = new Request('GET', '/api/resource', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer secret_token_123',
        ]);

        $this->assertEquals('secret_token_123', $request->bearerToken());
    }

    public function testBearerTokenReturnsNullWhenNoAuth(): void
    {
        $request = new Request('GET', '/api/resource');
        $this->assertNull($request->bearerToken());
    }

    public function testBearerTokenReturnsNullForNonBearerAuth(): void
    {
        $request = new Request('GET', '/', [], [], [
            'HTTP_AUTHORIZATION' => 'Basic dXNlcjpwYXNz',
        ]);

        $this->assertNull($request->bearerToken());
    }

    // =========================================================================
    // Host / Scheme
    // =========================================================================

    public function testGetHostFromServerVariable(): void
    {
        $request = new Request('GET', '/page', [], [], [
            'HTTP_HOST' => 'shop.example.com',
        ]);

        $this->assertEquals('shop.example.com', $request->getHost());
    }

    public function testGetHostReturnsNullWhenNotSet(): void
    {
        $request = new Request('GET', '/page');
        $this->assertNull($request->getHost());
    }

    public function testIsHttpsWithHttpsFlag(): void
    {
        $request = new Request('GET', '/', [], [], ['HTTPS' => 'on']);
        $this->assertTrue($request->isHttps());
        $this->assertEquals('https', $request->getScheme());
    }

    public function testIsHttpsWithPort443(): void
    {
        $request = new Request('GET', '/', [], [], ['SERVER_PORT' => 443]);
        $this->assertTrue($request->isHttps());
    }

    public function testIsNotHttpsByDefault(): void
    {
        $request = new Request('GET', '/');
        $this->assertFalse($request->isHttps());
        $this->assertEquals('http', $request->getScheme());
    }

    public function testGetPath(): void
    {
        $request = new Request('GET', '/board/list');
        $this->assertEquals('/board/list', $request->getPath());
    }

    public function testGetPathForRoot(): void
    {
        $request = new Request('GET', '/');
        $this->assertEquals('/', $request->getPath());
    }

    // =========================================================================
    // Cookie
    // =========================================================================

    public function testCookieRetrieval(): void
    {
        $request = new Request('GET', '/', [], [], [], [], [
            'user_pref' => 'dark_mode',
        ]);

        $this->assertEquals('dark_mode', $request->cookie('user_pref'));
        $this->assertNull($request->cookie('nonexistent'));
    }

    // =========================================================================
    // all() 통합 입력
    // =========================================================================

    public function testAllReturnsQueryForGetRequest(): void
    {
        $request = new Request('GET', '/search', ['q' => 'keyword', 'page' => 2]);
        $all = $request->all();

        $this->assertArrayHasKey('q', $all);
        $this->assertEquals('keyword', $all['q']);
    }

    public function testAllReturnsBodyForPostRequest(): void
    {
        $request = new Request('POST', '/submit', [], ['name' => 'John', 'age' => 25]);
        $all = $request->all();

        $this->assertArrayHasKey('name', $all);
        $this->assertEquals('John', $all['name']);
    }

    // =========================================================================
    // Result 패턴과 JsonResponse 통합
    // =========================================================================

    public function testResultPatternWithJsonResponse(): void
    {
        // Given: Service에서 Result 반환
        $result = Result::success('처리 성공', ['id' => 1, 'name' => 'test']);

        // When: Controller에서 JsonResponse 생성
        if ($result->isSuccess()) {
            $response = JsonResponse::success($result->getData(), $result->getMessage());
        } else {
            $response = JsonResponse::error($result->getMessage());
        }

        // Then
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(200, $response->getStatusCode());

        $body = json_decode($response->toJson(), true);
        $this->assertEquals('success', $body['result']);
        $this->assertEquals('처리 성공', $body['message']);
        $this->assertEquals(1, $body['data']['id']);
    }

    public function testResultPatternWithFailureAndJsonResponse(): void
    {
        // Given
        $result = Result::failure('유효하지 않은 입력입니다.');

        // When
        $response = $result->isSuccess()
            ? JsonResponse::success($result->getData(), $result->getMessage())
            : JsonResponse::error($result->getMessage());

        // Then
        $this->assertInstanceOf(JsonResponse::class, $response);

        $body = json_decode($response->toJson(), true);
        $this->assertEquals('error', $body['result']);
        $this->assertStringContainsString('유효하지 않은', $body['message']);
    }

    // =========================================================================
    // Context + Request 통합
    // =========================================================================

    public function testContextPreservesRequest(): void
    {
        // Given
        $request = new Request('POST', '/board/write', [], ['title' => '테스트 글']);
        $context = new Context($request);

        // When
        $context->setAdmin(false);
        $context->setDomain('example.com');

        // Then: Context에서 원본 Request에 접근 가능
        $this->assertSame($request, $context->getRequest());
        $this->assertEquals('테스트 글', $context->getRequest()->input('title'));
        $this->assertEquals('example.com', $context->getDomain());
        $this->assertTrue($context->isFront());
    }

    public function testAdminRequestContext(): void
    {
        // Given: 관리자 요청
        $request = new Request('GET', '/admin/dashboard', [], [], [
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ]);
        $context = new Context($request);
        $context->setAdmin(true);

        // Then
        $this->assertTrue($context->isAdmin());
        $this->assertFalse($context->isFront());
        $this->assertTrue($context->getRequest()->isAjax());
    }

    // =========================================================================
    // server() 메서드
    // =========================================================================

    public function testServerVariableRetrieval(): void
    {
        $request = new Request('GET', '/', [], [], [
            'REMOTE_ADDR' => '192.168.1.1',
            'SERVER_NAME' => 'example.com',
        ]);

        $this->assertEquals('192.168.1.1', $request->server('REMOTE_ADDR'));
        $this->assertEquals('example.com', $request->server('SERVER_NAME'));
        $this->assertNull($request->server('NONEXISTENT_VAR'));
    }

    // =========================================================================
    // post() 메서드 (input 별칭)
    // =========================================================================

    public function testPostMethodIsAliasForInput(): void
    {
        $request = new Request('POST', '/submit', [], ['name' => 'Alice', 'email' => 'alice@example.com']);

        $this->assertEquals('Alice', $request->post('name'));
        $this->assertEquals('alice@example.com', $request->input('email'));
        $this->assertEquals($request->post('name'), $request->input('name'));
    }

    // =========================================================================
    // query() 메서드 (get 별칭)
    // =========================================================================

    public function testQueryMethodIsAliasForGet(): void
    {
        $request = new Request('GET', '/list', ['page' => 2, 'limit' => 10]);

        $this->assertEquals(2, $request->query('page'));
        $this->assertEquals(2, $request->get('page'));
    }
}
