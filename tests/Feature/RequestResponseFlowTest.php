<?php
/**
 * tests/Feature/RequestResponseFlowTest.php
 *
 * Feature 테스트 예제
 *
 * Feature 테스트는 실제 요청-응답 흐름을 테스트합니다.
 * 여러 계층(Controller, Service, Repository)이 함께 작동하는지 확인합니다.
 */

namespace Tests\Feature;

use Tests\TestCase;
use Mublo\Core\Http\Request;
use Mublo\Core\Response\JsonResponse;

class RequestResponseFlowTest extends TestCase
{
    /**
     * 기본 Request 객체 생성 테스트
     */
    public function testCanCreateBasicRequest(): void
    {
        $request = new Request('GET', '/');

        $this->assertNotNull($request);
        $this->assertEquals('GET', $request->getMethod());
        $this->assertEquals('/', $request->getUri());
    }

    /**
     * Request에 Query 파라미터 포함 테스트
     */
    public function testRequestWithQueryParameters(): void
    {
        $query = [
            'page' => 1,
            'limit' => 10,
            'search' => 'keyword',
        ];

        $request = new Request('GET', '/board/list', $query);

        // 전체 쿼리
        $this->assertEquals($query, $request->getQuery());

        // 개별 파라미터
        $this->assertEquals(1, $request->get('page'));
        $this->assertEquals(10, $request->get('limit'));
        $this->assertEquals('keyword', $request->get('search'));
    }

    /**
     * POST 요청 Body 테스트
     */
    public function testPostRequestWithBody(): void
    {
        $body = [
            'title' => '게시글 제목',
            'content' => '게시글 내용',
            'category' => 'notice',
        ];

        $request = new Request('POST', '/board/write', [], $body);

        $this->assertEquals('게시글 제목', $request->input('title'));
        $this->assertEquals('게시글 내용', $request->input('content'));
        $this->assertEquals('notice', $request->input('category'));
    }

    /**
     * HTTP Method 확인 테스트
     */
    public function testHttpMethodDetection(): void
    {
        $getRequest = new Request('GET', '/');
        $postRequest = new Request('POST', '/');
        $putRequest = new Request('PUT', '/');
        $deleteRequest = new Request('DELETE', '/');

        $this->assertEquals('GET', $getRequest->getMethod());
        $this->assertEquals('POST', $postRequest->getMethod());
        $this->assertEquals('PUT', $putRequest->getMethod());
        $this->assertEquals('DELETE', $deleteRequest->getMethod());

        $this->assertNotEquals('POST', $getRequest->getMethod());
        $this->assertNotEquals('GET', $postRequest->getMethod());
    }

    /**
     * Server 정보 포함 요청 테스트
     */
    public function testRequestWithServerInfo(): void
    {
        $server = [
            'HTTP_HOST' => 'example.com',
            'HTTP_USER_AGENT' => 'Mozilla/5.0',
            'REMOTE_ADDR' => '127.0.0.1',
        ];

        $request = new Request('GET', '/', [], [], $server);

        $this->assertEquals('example.com', $request->server('HTTP_HOST'));
        $this->assertEquals('Mozilla/5.0', $request->server('HTTP_USER_AGENT'));
        $this->assertEquals('127.0.0.1', $request->server('REMOTE_ADDR'));
    }

    /**
     * JsonResponse 생성 테스트
     */
    public function testJsonResponseCreation(): void
    {
        $data = ['id' => 1, 'name' => 'Test'];
        $response = JsonResponse::success($data, 'Success message');

        $this->assertNotNull($response);
        // JsonResponse 구조 확인
        $this->assertIsArray($response->getData());
    }

    /**
     * 유효성 검증 테스트 시뮬레이션
     */
    public function testValidationLogic(): void
    {
        $request = new Request('POST', '/member/register', [], [
            'username' => 'john_doe',
            'email' => 'john@example.com',
            'password' => 'secure_password_123',
        ]);

        // 검증 로직
        $errors = [];

        if (empty($request->input('username'))) {
            $errors['username'] = '사용자명 필수';
        }

        if (empty($request->input('email'))) {
            $errors['email'] = '이메일 필수';
        }

        if (empty($request->input('password'))) {
            $errors['password'] = '비밀번호 필수';
        }

        // 검증 결과
        $this->assertEmpty($errors);
    }

    /**
     * 검증 실패 시뮬레이션
     */
    public function testValidationFailure(): void
    {
        $request = new Request('POST', '/member/register', [], [
            'username' => '',  // 빈 값
            'email' => 'invalid-email',  // 유효하지 않은 형식
            'password' => '123',  // 너무 짧음
        ]);

        $errors = [];

        if (empty($request->input('username'))) {
            $errors['username'] = '사용자명 필수';
        }

        if (strlen($request->input('password')) < 8) {
            $errors['password'] = '비밀번호는 8자 이상이어야 합니다';
        }

        // 검증 결과: 오류 있음
        $this->assertNotEmpty($errors);
        $this->assertArrayHasKey('username', $errors);
        $this->assertArrayHasKey('password', $errors);
    }

    /**
     * 복잡한 요청 흐름 테스트
     *
     * 실제 게시판 글 작성 요청을 시뮬레이션합니다.
     */
    public function testBoardWriteRequestFlow(): void
    {
        // 1단계: 클라이언트가 POST 요청 전송
        $request = new Request('POST', '/board/write', [], [
            'board_id' => 1,
            'title' => '새로운 게시글',
            'content' => '게시글 본문 내용',
            'category' => 'free',
            'tags' => 'php,framework',
        ]);

        // 2단계: 요청 검증
        $errors = [];

        if (empty($request->input('title'))) {
            $errors['title'] = '제목 필수';
        }

        if (strlen($request->input('content')) < 10) {
            $errors['content'] = '본문은 10자 이상이어야 합니다';
        }

        // 검증 성공
        $this->assertEmpty($errors);

        // 3단계: 데이터 확인
        $this->assertEquals(1, $request->input('board_id'));
        $this->assertEquals('새로운 게시글', $request->input('title'));
        $this->assertEquals('게시글 본문 내용', $request->input('content'));
        $this->assertEquals('free', $request->input('category'));

        // 4단계: 응답 생성 (성공 시나리오)
        $postId = 123;
        $response = JsonResponse::success([
            'post_id' => $postId,
            'message' => '게시글이 작성되었습니다.',
        ]);

        $this->assertNotNull($response);
    }

    /**
     * 요청 메타데이터 테스트
     */
    public function testRequestMetadata(): void
    {
        $request = new Request(
            'POST',
            '/api/member/profile',
            ['token' => 'abc123'],
            ['name' => 'John', 'age' => 30],
            ['HTTP_ACCEPT' => 'application/json']
        );

        // HTTP Method
        $this->assertEquals('POST', $request->getMethod());

        // URI
        $this->assertEquals('/api/member/profile', $request->getUri());

        // Query
        $this->assertEquals('abc123', $request->get('token'));

        // Body
        $this->assertEquals('John', $request->input('name'));
        $this->assertEquals(30, $request->input('age'));

        // Server Info
        $this->assertEquals('application/json', $request->server('HTTP_ACCEPT'));
    }
}
