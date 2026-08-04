<?php

namespace Tests\Faq\Unit\Service;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Mublo\Plugin\Faq\Service\FaqService;
use Mublo\Plugin\Faq\Repository\FaqRepository;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Plugin\Faq\Event\FaqContentChangedEvent;

/**
 * FaqServiceTest
 *
 * FAQ 카테고리/항목 CRUD 서비스 테스트
 */
class FaqServiceTest extends TestCase
{
    private MockObject $repositoryMock;
    private FaqService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repositoryMock = $this->createMock(FaqRepository::class);
        $this->service = new FaqService($this->repositoryMock);
    }

    // ─── 카테고리 생성 ───

    public function testCreateCategorySucceeds(): void
    {
        $this->repositoryMock->method('existsSlug')->willReturn(false);
        $this->repositoryMock->expects($this->once())
            ->method('insertCategory')
            ->willReturn(7);

        $result = $this->service->createCategory(10, ['category_name' => '자주묻는질문']);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(7, $result->get('category_id'));
        $this->assertStringContainsString('생성', $result->getMessage());
    }

    public function testCreateCategoryDispatchesDomainScopedContentChangedEvent(): void
    {
        $this->repositoryMock->method('existsSlug')->willReturn(false);
        $this->repositoryMock->method('insertCategory')->willReturn(7);
        $dispatcher = new EventDispatcher();
        $domainId = null;
        $dispatcher->addListener(FaqContentChangedEvent::class, function ($event) use (&$domainId): void {
            $domainId = $event->getDomainId();
        });

        $result = (new FaqService($this->repositoryMock, $dispatcher))
            ->createCategory(10, ['category_name' => '자주묻는질문']);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(10, $domainId);
    }

    public function testCreateCategoryFailsWhenNameEmpty(): void
    {
        $this->repositoryMock->expects($this->never())->method('insertCategory');

        $result = $this->service->createCategory(10, ['category_name' => '']);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('카테고리명', $result->getMessage());
    }

    public function testCreateCategoryFailsWhenNameWhitespaceOnly(): void
    {
        $result = $this->service->createCategory(10, ['category_name' => '   ']);

        $this->assertTrue($result->isFailure());
    }

    public function testCreateCategoryFailsWhenInsertReturnsNull(): void
    {
        $this->repositoryMock->method('existsSlug')->willReturn(false);
        $this->repositoryMock->method('insertCategory')->willReturn(null);

        $result = $this->service->createCategory(10, ['category_name' => '카테고리']);

        $this->assertTrue($result->isFailure());
    }

    // ─── 카테고리 수정 ───

    public function testUpdateCategorySucceeds(): void
    {
        $this->repositoryMock->method('findCategory')
            ->willReturn(['category_id' => 1, 'category_name' => '기존명', 'sort_order' => 0, 'is_active' => 1]);
        $this->repositoryMock->expects($this->once())->method('updateCategory');

        $result = $this->service->updateCategory(10, 1, ['category_name' => '수정된명']);

        $this->assertTrue($result->isSuccess());
    }

    public function testUpdateCategoryFailsWhenNotFound(): void
    {
        $this->repositoryMock->method('findCategory')->willReturn(null);

        $result = $this->service->updateCategory(10, 999, ['category_name' => '수정']);

        $this->assertTrue($result->isFailure());
    }

    public function testUpdateCategoryFailsWhenNameEmpty(): void
    {
        $this->repositoryMock->method('findCategory')
            ->willReturn(['category_id' => 1, 'category_name' => '기존명', 'sort_order' => 0, 'is_active' => 1]);

        $result = $this->service->updateCategory(10, 1, ['category_name' => '']);

        $this->assertTrue($result->isFailure());
    }

    // ─── 카테고리 삭제 ───

    public function testDeleteCategorySucceeds(): void
    {
        $this->repositoryMock->method('findCategory')
            ->willReturn(['category_id' => 1, 'category_name' => '카테고리']);
        $this->repositoryMock->expects($this->once())->method('deleteCategory');

        $result = $this->service->deleteCategory(10, 1);

        $this->assertTrue($result->isSuccess());
        $this->assertStringContainsString('삭제', $result->getMessage());
    }

    public function testDeleteCategoryFailsWhenNotFound(): void
    {
        $this->repositoryMock->method('findCategory')->willReturn(null);
        $this->repositoryMock->expects($this->never())->method('deleteCategory');

        $result = $this->service->deleteCategory(10, 999);

        $this->assertTrue($result->isFailure());
    }

    // ─── FAQ 항목 생성 ───

    public function testCreateItemSucceeds(): void
    {
        $this->repositoryMock->method('findCategory')
            ->willReturn(['category_id' => 3, 'domain_id' => 10]);
        $this->repositoryMock->method('insertItem')->willReturn(15);

        $result = $this->service->createItem(10, [
            'category_id' => 3,
            'question'    => 'Q: 어떻게 하나요?',
            'answer'      => 'A: 이렇게 합니다.',
        ]);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(15, $result->get('faq_id'));
    }

    public function testCreateItemFailsWhenCategoryIdZero(): void
    {
        $result = $this->service->createItem(10, [
            'category_id' => 0,
            'question'    => '질문',
            'answer'      => '답변',
        ]);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('카테고리', $result->getMessage());
    }

    public function testCreateItemFailsWhenCategoryNotFound(): void
    {
        $this->repositoryMock->method('findCategory')->willReturn(null);

        $result = $this->service->createItem(10, [
            'category_id' => 999,
            'question'    => '질문',
            'answer'      => '답변',
        ]);

        $this->assertTrue($result->isFailure());
    }

    public function testCreateItemFailsWhenQuestionEmpty(): void
    {
        $this->repositoryMock->method('findCategory')
            ->willReturn(['category_id' => 3]);

        $result = $this->service->createItem(10, [
            'category_id' => 3,
            'question'    => '',
            'answer'      => '답변',
        ]);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('질문', $result->getMessage());
    }

    public function testCreateItemFailsWhenAnswerEmpty(): void
    {
        $this->repositoryMock->method('findCategory')
            ->willReturn(['category_id' => 3]);

        $result = $this->service->createItem(10, [
            'category_id' => 3,
            'question'    => '질문',
            'answer'      => '',
        ]);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('답변', $result->getMessage());
    }

    // ─── FAQ 항목 수정 ───

    public function testUpdateItemSucceeds(): void
    {
        $this->repositoryMock->method('findItem')
            ->willReturn(['faq_id' => 1, 'domain_id' => 10, 'question' => 'Q', 'answer' => 'A', 'sort_order' => 0, 'is_active' => 1]);
        $this->repositoryMock->expects($this->once())->method('updateItem');

        $result = $this->service->updateItem(10, 1, [
            'question' => '수정된 질문',
            'answer'   => '수정된 답변',
        ]);

        $this->assertTrue($result->isSuccess());
    }

    public function testUpdateItemFailsWhenNotFound(): void
    {
        $this->repositoryMock->method('findItem')->willReturn(null);

        $result = $this->service->updateItem(10, 999, [
            'question' => '질문',
            'answer'   => '답변',
        ]);

        $this->assertTrue($result->isFailure());
    }

    // ─── FAQ 항목 삭제 ───

    public function testDeleteItemSucceeds(): void
    {
        $this->repositoryMock->method('findItem')
            ->willReturn(['faq_id' => 1]);
        $this->repositoryMock->expects($this->once())->method('deleteItem');

        $result = $this->service->deleteItem(10, 1);

        $this->assertTrue($result->isSuccess());
    }

    public function testDeleteItemFailsWhenNotFound(): void
    {
        $this->repositoryMock->method('findItem')->willReturn(null);
        $this->repositoryMock->expects($this->never())->method('deleteItem');

        $result = $this->service->deleteItem(10, 999);

        $this->assertTrue($result->isFailure());
    }

    // ─── updateSortOrder ───

    public function testUpdateSortOrderCallsRepositoryForValidItems(): void
    {
        $this->repositoryMock->expects($this->exactly(2))
            ->method('updateItemSortOrder');

        $result = $this->service->updateSortOrder(10, [
            ['faq_id' => 1, 'sort_order' => 0],
            ['faq_id' => 2, 'sort_order' => 1],
            ['faq_id' => 0, 'sort_order' => 2], // faq_id=0 스킵
        ]);

        $this->assertTrue($result->isSuccess());
    }

    // ─── 카테고리 목록 ───

    public function testGetCategoryListReturnsSuccessWithCategories(): void
    {
        $categories = [
            ['category_id' => 1, 'category_name' => '일반'],
            ['category_id' => 2, 'category_name' => '기술'],
        ];
        $this->repositoryMock->method('findCategories')->willReturn($categories);

        $result = $this->service->getCategoryList(10);

        $this->assertTrue($result->isSuccess());
        $this->assertSame($categories, $result->get('categories'));
    }

    // ─── getGroupedAll ───

    public function testGetGroupedAllReturnsCategoryGroupedData(): void
    {
        $this->repositoryMock->method('findGroupedAll')
            ->willReturn([
                ['category_id' => 1, 'category_name' => '일반', 'category_slug' => 'general', 'faq_id' => 1, 'question' => 'Q1', 'answer' => 'A1'],
                ['category_id' => 1, 'category_name' => '일반', 'category_slug' => 'general', 'faq_id' => 2, 'question' => 'Q2', 'answer' => 'A2'],
                ['category_id' => 2, 'category_name' => '기술', 'category_slug' => 'tech', 'faq_id' => 3, 'question' => 'Q3', 'answer' => 'A3'],
            ]);

        $grouped = $this->service->getGroupedAll(10);

        $this->assertCount(2, $grouped);
        $this->assertSame('일반', $grouped[0]['category_name']);
        $this->assertCount(2, $grouped[0]['items']);
        $this->assertSame('기술', $grouped[1]['category_name']);
        $this->assertCount(1, $grouped[1]['items']);
    }
}
