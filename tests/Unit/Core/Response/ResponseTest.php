<?php
/**
 * tests/Unit/Core/Response/ResponseTest.php
 *
 * Response 클래스들의 단위 테스트
 */

namespace Tests\Unit\Core\Response;

use Tests\TestCase;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Response\ViewResponse;

class ResponseTest extends TestCase
{
    public function testJsonSuccessResponse(): void
    {
        $response = JsonResponse::success(['id' => 1, 'name' => 'Test'], '성공');
        
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertIsArray($response->getHeaders());
    }

    public function testJsonErrorResponse(): void
    {
        $response = JsonResponse::error('에러 발생');
        
        $this->assertNotNull($response->getStatusCode());
    }

    public function testViewResponseWithData(): void
    {
        $response = ViewResponse::view('Test/Index')->withData(['title' => '테스트']);
        
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testViewResponseFullPage(): void
    {
        $response = ViewResponse::view('Test/Index')->fullPage();

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertTrue($response->isFullPageHint());
    }

    public function testViewResponsePartial(): void
    {
        $response = ViewResponse::view('Test/Index')->partial();

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertFalse($response->isFullPageHint());
    }
}
