<?php
/**
 * tests/Unit/Core/Http/RequestTest.php
 *
 * Request 클래스 단위 테스트
 */

namespace Tests\Unit\Core\Http;

use Tests\TestCase;
use Mublo\Core\Http\Request;

class RequestTest extends TestCase
{
    protected function tearDown(): void
    {
        Request::setTrustedProxies([]);
        parent::tearDown();
    }

    public function testRequestMethodDetection(): void
    {
        $request = new Request('GET', '/test/path');
        $this->assertEquals('GET', $request->getMethod());

        $postRequest = new Request('POST', '/submit');
        $this->assertEquals('POST', $postRequest->getMethod());
    }

    public function testUriExtraction(): void
    {
        $request = new Request('GET', '/test/path');
        $this->assertEquals('/test/path', $request->getUri());
        $this->assertEquals('/test/path', $request->getPath());
    }

    public function testIsJsonRequest(): void
    {
        // JSON 요청이 아님
        $request = new Request('GET', '/');
        $this->assertFalse($request->isJson());

        // JSON 요청
        $jsonRequest = new Request('POST', '/api/data', [], [], [
            'CONTENT_TYPE' => 'application/json'
        ]);
        $this->assertTrue($jsonRequest->isJson());
    }

    public function testJsonParseErrorState(): void
    {
        $request = new Request('POST', '/api/data', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ]);

        $request->setJsonParseError('Syntax error');

        $this->assertTrue($request->hasJsonParseError());
        $this->assertSame('Syntax error', $request->getJsonParseError());
        $this->assertNull($request->getJsonInput());
        $this->assertSame([], $request->all());

        $request->setJsonInput(['ok' => true]);

        $this->assertFalse($request->hasJsonParseError());
        $this->assertNull($request->getJsonParseError());
        $this->assertSame(['ok' => true], $request->all());
    }

    public function testIsAjaxRequest(): void
    {
        // AJAX 요청이 아님
        $request = new Request('GET', '/');
        $this->assertFalse($request->isAjax());

        // AJAX 요청
        $ajaxRequest = new Request('GET', '/data', [], [], [
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'
        ]);
        $this->assertTrue($ajaxRequest->isAjax());
    }

    public function testQueryParameters(): void
    {
        $request = new Request('GET', '/search', ['q' => 'test', 'page' => 1]);

        $this->assertEquals('test', $request->get('q'));
        $this->assertEquals(1, $request->get('page'));
        $this->assertNull($request->get('missing'));
        $this->assertEquals('default', $request->get('missing', 'default'));
    }

    public function testBodyParameters(): void
    {
        $request = new Request('POST', '/submit', [], [
            'name' => 'John',
            'email' => 'john@example.com'
        ]);

        $this->assertEquals('John', $request->input('name'));
        $this->assertEquals('john@example.com', $request->input('email'));
    }

    public function testServerInfo(): void
    {
        $request = new Request('GET', '/', [], [], [
            'HTTP_HOST' => 'example.com',
            'HTTP_USER_AGENT' => 'Mozilla/5.0'
        ]);

        $this->assertEquals('example.com', $request->server('HTTP_HOST'));
        $this->assertEquals('Mozilla/5.0', $request->server('HTTP_USER_AGENT'));
    }

    public function testHostIsNormalizedForUrlGeneration(): void
    {
        $request = new Request('GET', '/', [], [], [
            'HTTP_HOST' => 'Example.COM:8080',
        ]);

        $this->assertSame('example.com:8080', $request->getHost());
        $this->assertSame('http://example.com:8080', $request->getSchemeAndHost());
    }

    public function testInvalidHostIsRejected(): void
    {
        $request = new Request('GET', '/', [], [], [
            'HTTP_HOST' => "example.com\r\nX-Injected: yes",
        ]);

        $this->assertNull($request->getHost());
        $this->assertSame('http://localhost', $request->getSchemeAndHost());
    }

    public function testHeader(): void
    {
        $request = new Request('GET', '/', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer token123',
            'HTTP_ACCEPT' => 'application/json'
        ]);

        $this->assertEquals('Bearer token123', $request->header('Authorization'));
        $this->assertEquals('application/json', $request->header('Accept'));
    }

    public function testBearerToken(): void
    {
        $request = new Request('GET', '/', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer my_secret_token'
        ]);

        $this->assertEquals('my_secret_token', $request->bearerToken());
    }

    public function testCloudflareConnectingIpIsIgnoredWithoutTrustedProxy(): void
    {
        $request = new Request('GET', '/', [], [], [
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_CF_CONNECTING_IP' => '198.51.100.55',
        ]);

        $this->assertSame('203.0.113.10', $request->getClientIp());
    }

    public function testCloudflareConnectingIpIsUsedFromTrustedProxy(): void
    {
        Request::setTrustedProxies(['203.0.113.10']);

        $request = new Request('GET', '/', [], [], [
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_CF_CONNECTING_IP' => '198.51.100.55',
        ]);

        $this->assertSame('198.51.100.55', $request->getClientIp());
    }

    public function testXffAdoptsRightmostNonTrustedIpBehindProxy(): void
    {
        Request::setTrustedProxies(['10.0.0.0/8']);

        $request = new Request('GET', '/', [], [], [
            'REMOTE_ADDR' => '10.0.0.9', // 신뢰 프록시
            // 클라이언트가 위조한 최좌측 + 실제 클라이언트 + 신뢰 프록시
            'HTTP_X_FORWARDED_FOR' => '1.2.3.4, 203.0.113.7, 10.0.0.9',
        ]);

        // 오른쪽부터 신뢰 프록시(10.0.0.9)를 벗기고 첫 비신뢰(203.0.113.7)를 채택.
        // 위조된 최좌측 1.2.3.4 는 채택되지 않는다.
        $this->assertSame('203.0.113.7', $request->getClientIp());
    }

    public function testXffIgnoredWhenRemoteAddrNotTrusted(): void
    {
        Request::setTrustedProxies(['10.0.0.0/8']);

        $request = new Request('GET', '/', [], [], [
            'REMOTE_ADDR' => '203.0.113.1', // 비신뢰 직결
            'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
        ]);

        // 직전 홉이 신뢰 프록시가 아니면 XFF 를 신뢰하지 않고 REMOTE_ADDR 채택
        $this->assertSame('203.0.113.1', $request->getClientIp());
    }

    public function testTrustedProxyMatchesIpv6Cidr(): void
    {
        // IPv6 CIDR 프록시가 신뢰돼 CF-Connecting-IP 를 채택(기존 ip2long 은 IPv6 미지원이라 실패했음)
        Request::setTrustedProxies(['2400:cb00::/32']);

        $request = new Request('GET', '/', [], [], [
            'REMOTE_ADDR' => '2400:cb00:1234::5', // 2400:cb00::/32 내부
            'HTTP_CF_CONNECTING_IP' => '203.0.113.9',
        ]);

        $this->assertSame('203.0.113.9', $request->getClientIp());
    }

    public function testIpv6OutsideCidrIsNotTrusted(): void
    {
        Request::setTrustedProxies(['2400:cb00::/32']);

        $request = new Request('GET', '/', [], [], [
            'REMOTE_ADDR' => '2a00:1450::1', // CIDR 밖
            'HTTP_CF_CONNECTING_IP' => '203.0.113.9',
        ]);

        // 신뢰 프록시가 아니므로 CF-IP 무시, REMOTE_ADDR 채택
        $this->assertSame('2a00:1450::1', $request->getClientIp());
    }

    public function testMalformedCidrPrefixDoesNotMatchAll(): void
    {
        // 프리픽스가 숫자가 아니면(설정 오타) fail-closed 여야 한다.
        // (int)'abc'→0 으로 삼키면 /0 == match-all 이 돼 '모든 IP를 신뢰 프록시로'
        // 오판, XFF/CF-IP 스푸핑을 허용한다.
        Request::setTrustedProxies(['10.0.0.0/abc']);

        $request = new Request('GET', '/', [], [], [
            'REMOTE_ADDR' => '203.0.113.10', // 서브넷 밖 — match-all 버그일 때만 신뢰됨
            'HTTP_CF_CONNECTING_IP' => '198.51.100.55',
        ]);

        // 신뢰되지 않아 CF-IP 무시, REMOTE_ADDR 채택
        $this->assertSame('203.0.113.10', $request->getClientIp());
    }

    public function testXffStripsPortFromEntries(): void
    {
        Request::setTrustedProxies(['10.0.0.0/8']);

        $request = new Request('GET', '/', [], [], [
            'REMOTE_ADDR' => '10.0.0.9',
            // 프록시가 각 XFF 항목에 포트를 붙인 경우
            'HTTP_X_FORWARDED_FOR' => '203.0.113.7:5678, 10.0.0.9:443',
        ]);

        // 신뢰 프록시 10.0.0.9:443 은 포트를 벗겨 인식되어야 하고(그래야 스킵),
        // 채택되는 클라이언트 IP 도 포트 없이 반환되어야 한다.
        $this->assertSame('203.0.113.7', $request->getClientIp());
    }

    public function testAllTrustedXffWithEmptyLeftmostFallsBackToRemoteAddr(): void
    {
        Request::setTrustedProxies(['10.0.0.0/8']);

        $request = new Request('GET', '/', [], [], [
            'REMOTE_ADDR' => '10.0.0.9',
            // 최좌측이 비어 있고 나머지는 전부 신뢰 프록시
            'HTTP_X_FORWARDED_FOR' => ', 10.0.0.8, 10.0.0.9',
        ]);

        // 빈 IP 가 새지 않도록 REMOTE_ADDR 로 폴백
        $this->assertSame('10.0.0.9', $request->getClientIp());
    }

    public function testMalformedForwardedHeadersFallBackToRemoteAddr(): void
    {
        Request::setTrustedProxies(['10.0.0.0/8']);

        foreach ([
            ['HTTP_CF_CONNECTING_IP' => 'not-an-ip'],
            ['HTTP_X_FORWARDED_FOR' => 'garbage, 10.0.0.8'],
            ['HTTP_X_REAL_IP' => 'bad-real-ip'],
            ['HTTP_CLIENT_IP' => 'bad-client-ip'],
        ] as $forwardedHeader) {
            $request = new Request('GET', '/', [], [], [
                'REMOTE_ADDR' => '10.0.0.9',
                ...$forwardedHeader,
            ]);

            $this->assertSame('10.0.0.9', $request->getClientIp());
        }
    }

    public function testMalformedXffSegmentCannotExposeSpoofedLeftEntry(): void
    {
        Request::setTrustedProxies(['10.0.0.0/8']);

        $request = new Request('GET', '/', [], [], [
            'REMOTE_ADDR' => '10.0.0.9',
            // 오른쪽 신뢰 프록시 다음 항목이 깨졌다면 그 왼쪽 값을 채택하지 않는다.
            'HTTP_X_FORWARDED_FOR' => '198.51.100.77, malformed, 10.0.0.8',
        ]);

        $this->assertSame('10.0.0.9', $request->getClientIp());
    }

    public function testXffStripsBracketedIpv6Port(): void
    {
        Request::setTrustedProxies(['2400:cb00::/32']);

        $request = new Request('GET', '/', [], [], [
            'REMOTE_ADDR' => '2400:cb00::9',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.7, [2400:cb00::9]:443',
        ]);

        // 브래킷 IPv6+포트 신뢰 프록시를 벗겨 인식 → 첫 비신뢰 203.0.113.7 채택
        $this->assertSame('203.0.113.7', $request->getClientIp());
    }

    public function testTrustedProxyMatchesIpv6RegardlessOfNotation(): void
    {
        // 단일 IPv6 프록시 항목이 대문자·비압축 표기라도, 동일 주소면 신뢰돼야 한다.
        // 문자열 === 만으로는 표기 차이로 불일치해 신뢰 프록시 판정이 새어나간다.
        Request::setTrustedProxies(['2400:CB00:0:0:0:0:0:1']);

        $request = new Request('GET', '/', [], [], [
            'REMOTE_ADDR' => '2400:cb00::1', // 같은 주소, 압축·소문자 표기
            'HTTP_CF_CONNECTING_IP' => '203.0.113.9',
        ]);

        $this->assertSame('203.0.113.9', $request->getClientIp());
    }

    /**
     * all() 메서드는 PayloadType에 따라 반환:
     * - FORM (POST): body 반환
     * - GET: query 반환
     */
    public function testAllData(): void
    {
        // POST 요청: body 반환
        $postRequest = new Request('POST', '/data', [], [
            'name' => 'Test',
            'email' => 'test@example.com'
        ]);
        $postAll = $postRequest->all();
        $this->assertEquals('Test', $postAll['name']);
        $this->assertEquals('test@example.com', $postAll['email']);

        // GET 요청: query 반환
        $getRequest = new Request('GET', '/search', [
            'page' => 1,
            'limit' => 10
        ]);
        $getAll = $getRequest->all();
        $this->assertEquals(1, $getAll['page']);
        $this->assertEquals(10, $getAll['limit']);
    }

    public function testInvalidFlagDefaultsFalse(): void
    {
        $request = new Request('GET', '/normal/path');
        $this->assertFalse($request->isInvalid());
        $this->assertNull($request->getInvalidReason());
    }

    public function testSetInvalidMarksRequestWithReason(): void
    {
        $request = new Request('GET', '/x');
        $request->setInvalid('Malformed request path');

        $this->assertTrue($request->isInvalid());
        $this->assertSame('Malformed request path', $request->getInvalidReason());
    }
}
