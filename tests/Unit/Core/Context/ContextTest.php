<?php
/**
 * tests/Unit/Core/Context/ContextTest.php
 *
 * Context 클래스 단위 테스트
 *
 * Context는 요청 단위로 공유되는 상태 객체입니다.
 * Domain, Request, Area flags, Skin 정보를 포함합니다.
 */

namespace Tests\Unit\Core\Context;

use Tests\TestCase;
use Mublo\Core\Context\Context;
use Mublo\Core\Http\Request;
use Mublo\Entity\Domain\Domain;

class ContextTest extends TestCase
{
    private Context $context;
    private Request $request;
    private Domain $domain;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = new Request('GET', '/');

        // Mock Domain 객체
        $this->domain = $this->createMockDomain();

        // Context 생성 (Request만 받음)
        $this->context = new Context($this->request);

        // ContextBuilder가 설정하는 값들을 수동 설정
        $this->context->setDomain('example.com');
        $this->context->setDomainInfo($this->domain);
    }

    /**
     * Mock Domain 객체 생성
     */
    private function createMockDomain(): Domain
    {
        $domain = new Domain(
            domainId: 1,
            domain: 'example.com',
            domainGroup: '1'
        );

        return $domain;
    }

    /**
     * Context 기본 정보 조회 테스트
     */
    public function testContextBasicInfo(): void
    {
        $this->assertEquals(1, $this->context->getDomainId());
        $this->assertInstanceOf(Domain::class, $this->context->getDomainInfo());
        $this->assertInstanceOf(Request::class, $this->context->getRequest());
    }

    /**
     * Domain 정보 조회 테스트
     */
    public function testContextDomainInfo(): void
    {
        $domainInfo = $this->context->getDomainInfo();

        $this->assertEquals(1, $domainInfo->getDomainId());
        $this->assertEquals('example.com', $domainInfo->getDomain());
        $this->assertTrue($domainInfo->isActive());
    }

    /**
     * Request 정보 조회 테스트
     */
    public function testContextRequestInfo(): void
    {
        $request = $this->context->getRequest();

        $this->assertEquals('GET', $request->getMethod());
        $this->assertEquals('/', $request->getUri());
    }

    /**
     * Area flags 기본값 테스트
     */
    public function testContextAreaFlagsDefault(): void
    {
        // 초기 상태: Front 요청
        $this->assertFalse($this->context->isAdmin());
        $this->assertFalse($this->context->isApi());
        $this->assertTrue($this->context->isFront());
    }

    /**
     * Admin 영역 설정 테스트
     */
    public function testContextAdminArea(): void
    {
        // Admin 영역으로 설정
        $this->context->setAdmin(true);

        $this->assertTrue($this->context->isAdmin());
        $this->assertFalse($this->context->isApi());
        $this->assertFalse($this->context->isFront());
    }

    /**
     * API 영역 설정 테스트
     */
    public function testContextApiArea(): void
    {
        // API 영역으로 설정
        $this->context->setApi(true);

        $this->assertFalse($this->context->isAdmin());
        $this->assertTrue($this->context->isApi());
        $this->assertFalse($this->context->isFront());
    }

    /**
     * 도메인 접근 가능 여부 테스트
     */
    public function testContextDomainAccessible(): void
    {
        // 도메인 정보가 있고 활성 상태
        $this->assertTrue($this->context->hasDomainInfo());
        $this->assertTrue($this->context->isDomainAccessible());
    }

    /**
     * 도메인 정보 없을 때 테스트
     */
    public function testContextWithoutDomainInfo(): void
    {
        $context = new Context($this->request);

        $this->assertFalse($context->hasDomainInfo());
        $this->assertNull($context->getDomainId());
        $this->assertNull($context->getDomainGroup());
    }

    /**
     * 도메인 그룹 조회 테스트
     */
    public function testContextDomainGroup(): void
    {
        $this->assertEquals('1', $this->context->getDomainGroup());
    }

    /**
     * 프론트 스킨 설정 테스트
     */
    public function testContextFrontSkins(): void
    {
        // 초기 상태: 스킨 없음
        $this->assertNull($this->context->getFrontSkin('Layout'));

        // 스킨 설정
        $this->context->setFrontSkin('Layout', 'modern');
        $this->context->setFrontSkin('Header', 'premium');

        // 스킨 확인
        $this->assertEquals('modern', $this->context->getFrontSkin('Layout'));
        $this->assertEquals('premium', $this->context->getFrontSkin('Header'));
    }

    /**
     * 관리자 스킨 설정 테스트
     */
    public function testContextAdminSkins(): void
    {
        // 초기 상태: 기본값 'basic'
        $this->assertEquals('basic', $this->context->getAdminSkin());

        // 스킨 변경
        $this->context->setAdminSkin('dark');

        // 스킨 확인
        $this->assertEquals('dark', $this->context->getAdminSkin());
    }

    /**
     * 템플릿 스킨 설정 테스트
     */
    public function testContextTemplateSkins(): void
    {
        // 초기 상태: 스킨 없음
        $this->assertNull($this->context->getTemplateSkin('latest'));

        // 스킨 설정
        $this->context->setTemplateSkin('latest', 'card');
        $this->context->setTemplateSkin('image', 'gallery');

        // 스킨 확인
        $this->assertEquals('card', $this->context->getTemplateSkin('latest'));
        $this->assertEquals('gallery', $this->context->getTemplateSkin('image'));
    }

    /**
     * 프레임 스킨 설정 테스트
     */
    public function testContextFrameSkin(): void
    {
        // 초기 상태: 기본값 'basic'
        $this->assertEquals('basic', $this->context->getFrameSkin());

        // 스킨 변경
        $this->context->setFrameSkin('modern');

        // 스킨 확인
        $this->assertEquals('modern', $this->context->getFrameSkin());
    }

    /**
     * 복합 Context 사용 시뮬레이션
     *
     * 실제 요청 처리 중 Context가 어떻게 사용되는지 테스트
     */
    public function testComplexContextFlow(): void
    {
        // 1. 요청 처리 시작
        $this->assertEquals('GET', $this->context->getRequest()->getMethod());

        // 2. 도메인 확인
        $this->assertEquals(1, $this->context->getDomainId());
        $this->assertTrue($this->context->getDomainInfo()->isActive());

        // 3. Admin 영역 설정
        $this->context->setAdmin(true);
        $this->assertTrue($this->context->isAdmin());
        $this->assertFalse($this->context->isFront());

        // 4. 스킨 설정
        $this->context->setAdminSkin('dark');

        // 5. 최종 확인
        $this->assertEquals('dark', $this->context->getAdminSkin());
        $this->assertEquals('example.com', $this->context->getDomain());
    }

    /**
     * POST 요청 Context 테스트
     */
    public function testContextWithPostRequest(): void
    {
        $postRequest = new Request('POST', '/board/write', [], ['title' => 'Test']);
        $context = new Context($postRequest);

        $this->assertEquals('POST', $context->getRequest()->getMethod());
        $this->assertEquals('/board/write', $context->getRequest()->getUri());
        $this->assertEquals('Test', $context->getRequest()->input('title'));
    }

    /**
     * 쿼리 파라미터가 있는 요청 테스트
     */
    public function testContextWithQueryParameters(): void
    {
        $request = new Request('GET', '/board/list', ['page' => 2, 'limit' => 20]);
        $context = new Context($request);

        $this->assertEquals(2, $context->getRequest()->get('page'));
        $this->assertEquals(20, $context->getRequest()->get('limit'));
    }
}
