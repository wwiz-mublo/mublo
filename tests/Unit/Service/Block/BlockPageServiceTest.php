<?php

namespace Tests\Unit\Service\Block;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Mublo\Service\Block\BlockPageService;
use Mublo\Repository\Block\BlockPageRepository;
use Mublo\Repository\Block\BlockRowRepository;
use Mublo\Entity\Block\BlockPage;
use Mublo\Core\Event\Block\BlockPageUpdatedEvent;
use Mublo\Core\Event\EventDispatcher;

/**
 * BlockPageServiceTest
 *
 * BlockPageService 단위 테스트
 * - createPage: 필수 필드 누락, 예약어 코드, 코드 중복, 성공
 * - updatePage: 없는 페이지, 코드 변경 유효성, 성공
 * - deletePage: 없는 페이지, 연결 행 있음, 성공
 * - validateCode: 길이, 형식, 하이픈, 예약어
 * - getPage / getPageByCode
 */
class BlockPageServiceTest extends TestCase
{
    private BlockPageService $service;
    private MockObject $repositoryMock;
    private MockObject $rowRepositoryMock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repositoryMock = $this->createMock(BlockPageRepository::class);
        $this->rowRepositoryMock = $this->createMock(BlockRowRepository::class);

        $this->service = new BlockPageService(
            $this->repositoryMock,
            $this->rowRepositoryMock
        );
    }

    // =========================================================================
    // createPage
    // =========================================================================

    public function testCreatePageFailsWhenPageCodeMissing(): void
    {
        $result = $this->service->createPage(1, [
            'page_title' => '테스트 페이지',
            // page_code 누락
        ]);

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('필수 필드', $result->getMessage());
    }

    public function testCreatePageFailsWhenPageTitleMissing(): void
    {
        $result = $this->service->createPage(1, [
            'page_code' => 'test-page',
            // page_title 누락
        ]);

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('필수 필드', $result->getMessage());
    }

    public function testCreatePageFailsWithReservedCode(): void
    {
        // 예약어 코드들
        $reservedCodes = ['admin', 'api', 'auth', 'login', 'logout', 'register', 'board'];

        foreach ($reservedCodes as $code) {
            $result = $this->service->createPage(1, [
                'page_code' => $code,
                'page_title' => '테스트',
            ]);

            $this->assertFalse($result->isSuccess(), "코드 '{$code}'은 예약어여야 합니다");
            $this->assertStringContainsString('예약', $result->getMessage());
        }
    }

    public function testCreatePageFailsWithDuplicateCode(): void
    {
        // Given: 코드 중복
        $this->repositoryMock->expects($this->once())
            ->method('existsByCode')
            ->with(1, 'existing-page')
            ->willReturn(true);

        $result = $this->service->createPage(1, [
            'page_code' => 'existing-page',
            'page_title' => '페이지',
        ]);

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('사용중인 코드', $result->getMessage());
    }

    public function testCreatePageSucceeds(): void
    {
        // Given
        $this->repositoryMock->expects($this->once())
            ->method('existsByCode')
            ->with(1, 'new-page')
            ->willReturn(false);

        $this->repositoryMock->expects($this->once())
            ->method('create')
            ->willReturn(10);

        $result = $this->service->createPage(1, [
            'page_code' => 'new-page',
            'page_title' => '새 페이지',
        ]);

        $this->assertTrue($result->isSuccess());
        $this->assertEquals('페이지가 생성되었습니다.', $result->getMessage());
        $this->assertEquals(10, $result->get('page_id'));
    }

    public function testCreatePageFailsWhenRepositoryReturnsZero(): void
    {
        // Given: Repository create 실패
        $this->repositoryMock->expects($this->once())
            ->method('existsByCode')
            ->willReturn(false);

        $this->repositoryMock->expects($this->once())
            ->method('create')
            ->willReturn(0);

        $result = $this->service->createPage(1, [
            'page_code' => 'new-page',
            'page_title' => '새 페이지',
        ]);

        $this->assertFalse($result->isSuccess());
    }

    // =========================================================================
    // validateCode
    // =========================================================================

    public function testValidateCodeRejectsEmpty(): void
    {
        $result = $this->service->validateCode('');
        $this->assertFalse($result['valid']);
    }

    public function testValidateCodeRejectsTooShort(): void
    {
        // 1자 (최소 2자)
        $result = $this->service->validateCode('a');
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('2~50자', $result['message']);
    }

    public function testValidateCodeRejectsTooLong(): void
    {
        // 51자 (최대 50자)
        $result = $this->service->validateCode(str_repeat('a', 51));
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('2~50자', $result['message']);
    }

    public function testValidateCodeRejectsUppercase(): void
    {
        $result = $this->service->validateCode('MyPage');
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('영문 소문자', $result['message']);
    }

    public function testValidateCodeRejectsLeadingHyphen(): void
    {
        $result = $this->service->validateCode('-mypage');
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('하이픈으로 시작', $result['message']);
    }

    public function testValidateCodeRejectsTrailingHyphen(): void
    {
        $result = $this->service->validateCode('mypage-');
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('하이픈으로 시작하거나 끝날 수 없습니다', $result['message']);
    }

    public function testValidateCodeRejectsReservedWord(): void
    {
        $result = $this->service->validateCode('admin');
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('예약된', $result['message']);
    }

    public function testValidateCodeAcceptsValidCode(): void
    {
        $validCodes = ['my-page', 'about', 'contact-us', 'news2024', 'page-01'];

        foreach ($validCodes as $code) {
            $result = $this->service->validateCode($code);
            $this->assertTrue($result['valid'], "코드 '{$code}'은 유효해야 합니다");
            $this->assertNull($result['message']);
        }
    }

    // =========================================================================
    // updatePage
    // =========================================================================

    public function testUpdatePageFailsWhenPageNotFound(): void
    {
        $this->repositoryMock->expects($this->once())
            ->method('find')
            ->with(999)
            ->willReturn(null);

        $result = $this->service->updatePage(999, ['page_title' => '새 제목']);

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('찾을 수 없습니다', $result->getMessage());
    }

    public function testUpdatePageSucceeds(): void
    {
        // Given
        $page = $this->createMock(BlockPage::class);
        $page->expects($this->any())->method('getPageCode')->willReturn('my-page');
        $page->expects($this->any())->method('getDomainId')->willReturn(1);

        $this->repositoryMock->expects($this->once())
            ->method('find')
            ->with(5)
            ->willReturn($page);

        $this->repositoryMock->expects($this->once())
            ->method('update')
            ->willReturn(1);

        $result = $this->service->updatePage(5, ['page_title' => '수정된 제목']);

        $this->assertTrue($result->isSuccess());
    }

    public function testUpdatePagePublishesOldAndNewMenuIdentity(): void
    {
        $page = BlockPage::fromArray([
            'page_id' => 5,
            'domain_id' => 7,
            'page_code' => 'old-page',
            'page_title' => '기존 제목',
        ]);
        $this->repositoryMock->method('find')->with(5)->willReturn($page);
        $this->repositoryMock->method('existsByCode')->with(7, 'new-page', 5)->willReturn(false);
        $this->repositoryMock->method('update')->willReturn(1);
        $dispatcher = new EventDispatcher();
        $captured = null;
        $dispatcher->addListener(BlockPageUpdatedEvent::class, function ($event) use (&$captured): void {
            $captured = $event;
        });
        $service = new BlockPageService($this->repositoryMock, $this->rowRepositoryMock, $dispatcher);

        $result = $service->updatePage(5, [
            'page_code' => 'new-page',
            'page_title' => '새 제목',
        ], 7);

        $this->assertTrue($result->isSuccess());
        $this->assertInstanceOf(BlockPageUpdatedEvent::class, $captured);
        $this->assertSame('old-page', $captured->getOldPageCode());
        $this->assertSame('new-page', $captured->getPageCode());
        $this->assertSame('새 제목', $captured->getPageTitle());
    }

    // =========================================================================
    // deletePage
    // =========================================================================

    public function testDeletePageFailsWhenPageNotFound(): void
    {
        $this->repositoryMock->expects($this->once())
            ->method('find')
            ->with(999)
            ->willReturn(null);

        $result = $this->service->deletePage(999);

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('찾을 수 없습니다', $result->getMessage());
    }

    public function testDeletePageFailsWhenRowsExist(): void
    {
        // Given: 연결된 행이 있음
        $page = $this->createMock(BlockPage::class);

        $this->repositoryMock->expects($this->once())
            ->method('find')
            ->with(5)
            ->willReturn($page);

        $this->rowRepositoryMock->expects($this->once())
            ->method('countByPage')
            ->with(5)
            ->willReturn(3);

        $result = $this->service->deletePage(5);

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('행', $result->getMessage());
    }

    public function testDeletePageSucceeds(): void
    {
        // Given: 행이 없고 삭제 성공
        $page = $this->createMock(BlockPage::class);
        $page->expects($this->any())->method('getDomainId')->willReturn(1);
        $page->expects($this->any())->method('getPageCode')->willReturn('my-page');

        $this->repositoryMock->expects($this->once())
            ->method('find')
            ->with(5)
            ->willReturn($page);

        $this->rowRepositoryMock->expects($this->once())
            ->method('countByPage')
            ->with(5)
            ->willReturn(0);

        $this->repositoryMock->expects($this->once())
            ->method('delete')
            ->with(5)
            ->willReturn(1);

        $result = $this->service->deletePage(5);

        $this->assertTrue($result->isSuccess());
        $this->assertEquals('페이지가 삭제되었습니다.', $result->getMessage());
    }

    // =========================================================================
    // getPage / getPageByCode
    // =========================================================================

    public function testGetPageReturnsNullWhenNotFound(): void
    {
        $this->repositoryMock->expects($this->once())
            ->method('find')
            ->with(99)
            ->willReturn(null);

        $this->assertNull($this->service->getPage(99));
    }

    public function testGetPageReturnsPage(): void
    {
        $page = $this->createMock(BlockPage::class);

        $this->repositoryMock->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($page);

        $this->assertSame($page, $this->service->getPage(1));
    }

    public function testGetPageByCodeReturnsPage(): void
    {
        $page = $this->createMock(BlockPage::class);

        $this->repositoryMock->expects($this->once())
            ->method('findByCode')
            ->with(1, 'about')
            ->willReturn($page);

        $this->assertSame($page, $this->service->getPageByCode(1, 'about'));
    }

    public function testGetPageByCodeReturnsNullWhenNotFound(): void
    {
        $this->repositoryMock->expects($this->once())
            ->method('findByCode')
            ->with(1, 'nonexistent')
            ->willReturn(null);

        $this->assertNull($this->service->getPageByCode(1, 'nonexistent'));
    }
}
