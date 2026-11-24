<?php
declare(strict_types=1);

namespace Tests\Faq\Unit\Service;

use Mublo\Core\Event\EventDispatcher;
use Mublo\Plugin\Faq\Event\FaqItemDeletedEvent;
use Mublo\Plugin\Faq\Event\FaqItemFormEvent;
use Mublo\Plugin\Faq\Event\FaqItemSavedEvent;
use Mublo\Plugin\Faq\Event\FaqItemsLoadedEvent;
use Mublo\Plugin\Faq\Repository\FaqRepository;
use Mublo\Plugin\Faq\Service\FaqService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * FAQ 항목 확장 이벤트 4종 — 패키지가 항목에 자기 값(예: 연결 상품)을 붙이는 통로.
 *
 * 플러그인은 붙는 값의 뜻을 모른다. 발행 시점·실어 보내는 값·결과를 붙이는 자리를 고정한다.
 */
class FaqItemExtensionEventsTest extends TestCase
{
    private FaqRepository&MockObject $repository;
    private EventDispatcher $dispatcher;
    private FaqService $service;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(FaqRepository::class);
        $this->dispatcher = new EventDispatcher();
        $this->service = new FaqService($this->repository, $this->dispatcher);
    }

    public function testItemFormSectionsAreCollectedFromListeners(): void
    {
        $this->dispatcher->addListener(FaqItemFormEvent::class, function (FaqItemFormEvent $e): void {
            $this->assertSame(10, $e->getDomainId());
            $e->addSection('<div id="ext-a"></div>');
            $e->addSection('   ');
        });

        $this->assertSame(['<div id="ext-a"></div>'], $this->service->collectItemFormSections(10));
    }

    public function testItemFormSectionsAreEmptyWithoutDispatcher(): void
    {
        $this->assertSame([], (new FaqService($this->repository))->collectItemFormSections(10));
    }

    public function testAdminListGetsExtrasAndBadges(): void
    {
        $this->repository->method('findItems')->willReturn([
            ['faq_id' => 1, 'question' => 'Q1'],
            ['faq_id' => 2, 'question' => 'Q2'],
        ]);
        $this->dispatcher->addListener(FaqItemsLoadedEvent::class, function (FaqItemsLoadedEvent $e): void {
            $this->assertSame(FaqItemsLoadedEvent::SCOPE_ADMIN, $e->getScope());
            $this->assertSame([1, 2], $e->getFaqIds());
            $e->setExtra(1, 'linked_goods', [['goods_id' => 5]]);
            $e->addBadge(1, '연결 상품 1');
        });

        $items = $this->service->getItemsByCategory(10, 3)->get('items');

        $this->assertEquals((object) ['linked_goods' => [['goods_id' => 5]]], $items[0]['ext']);
        $this->assertSame(['연결 상품 1'], $items[0]['badges']);
        $this->assertSame('{}', json_encode($items[1]['ext']), '빈 확장 값도 JSON 객체로 내려가야 한다');
        $this->assertSame([], $items[1]['badges']);
    }

    public function testFrontGroupsGetRelatedLinks(): void
    {
        $this->dispatcher->addListener(FaqItemsLoadedEvent::class, function (FaqItemsLoadedEvent $e): void {
            $this->assertSame(FaqItemsLoadedEvent::SCOPE_FRONT, $e->getScope());
            $e->setRelatedTitle('관련 상품');
            $e->addRelatedLink(7, '정수기', '/goods/5');
            $e->addRelatedLink(7, '', '/ignored');
            $e->addRelatedLink(999, '없는 항목', '/x');
        });

        $grouped = $this->service->withRelatedLinks(10, [
            ['category_name' => 'A', 'category_slug' => 'a', 'items' => [
                ['faq_id' => 7, 'question' => 'Q', 'answer' => 'A'],
                ['faq_id' => 8, 'question' => 'Q2', 'answer' => 'A2'],
            ]],
        ]);

        $this->assertSame([['label' => '정수기', 'url' => '/goods/5']], $grouped[0]['items'][0]['related_links']);
        $this->assertSame('관련 상품', $grouped[0]['items'][0]['related_title']);
        $this->assertSame([], $grouped[0]['items'][1]['related_links']);
    }

    public function testRelatedLinksLeaveGroupsUntouchedWithoutDispatcherOrItems(): void
    {
        $grouped = [['category_name' => 'A', 'category_slug' => 'a', 'items' => [['faq_id' => 7]]]];

        $this->assertSame($grouped, (new FaqService($this->repository))->withRelatedLinks(10, $grouped));
        $this->assertSame([], $this->service->withRelatedLinks(10, []));
    }

    public function testCreateAndUpdateDispatchSavedEventWithRawInput(): void
    {
        $this->repository->method('findCategory')->willReturn(['category_id' => 3]);
        $this->repository->method('insertItem')->willReturn(41);
        $this->repository->method('findItem')->willReturn(['faq_id' => 41, 'sort_order' => 0, 'is_active' => 1]);
        $saved = [];
        $this->dispatcher->addListener(FaqItemSavedEvent::class, function (FaqItemSavedEvent $e) use (&$saved): void {
            $saved[] = [$e->getDomainId(), $e->getFaqId(), $e->isUpdate(), $e->hasInput('linked_goods_ids'), $e->getInput('linked_goods_ids')];
        });

        $input = ['category_id' => 3, 'question' => 'Q', 'answer' => 'A', 'linked_goods_ids' => [5, 6]];
        $this->assertTrue($this->service->createItem(10, $input)->isSuccess());
        $this->assertTrue($this->service->updateItem(10, 41, ['question' => 'Q', 'answer' => 'A'])->isSuccess());

        $this->assertSame([
            [10, 41, false, true, [5, 6]],
            [10, 41, true, false, null],
        ], $saved);
    }

    public function testFailedSaveDoesNotDispatch(): void
    {
        $this->repository->method('findCategory')->willReturn(null);
        $this->dispatcher->addListener(FaqItemSavedEvent::class, fn() => $this->fail('저장 실패에는 발행하지 않는다'));

        $this->assertFalse($this->service->createItem(10, ['category_id' => 3, 'question' => 'Q', 'answer' => 'A'])->isSuccess());
    }

    public function testDeleteItemDispatchesDeletedEvent(): void
    {
        $this->repository->method('findItem')->willReturn(['faq_id' => 41]);
        $deleted = null;
        $this->dispatcher->addListener(FaqItemDeletedEvent::class, function (FaqItemDeletedEvent $e) use (&$deleted): void {
            $deleted = [$e->getDomainId(), $e->getFaqIds()];
        });

        $this->service->deleteItem(10, 41);

        $this->assertSame([10, [41]], $deleted);
    }

    public function testDeleteCategoryReportsCascadedItemsCollectedBeforeDeletion(): void
    {
        $this->repository->method('findCategory')->willReturn(['category_id' => 3]);
        $order = [];
        $this->repository->method('findItems')->willReturnCallback(function (int $domainId, ?int $categoryId) use (&$order): array {
            $order[] = 'find';
            $this->assertSame([10, 3], [$domainId, $categoryId]);
            return [['faq_id' => 41], ['faq_id' => 42]];
        });
        $this->repository->method('deleteCategory')->willReturnCallback(function () use (&$order) {
            $order[] = 'delete';
            return 1;
        });
        $deleted = null;
        $this->dispatcher->addListener(FaqItemDeletedEvent::class, function (FaqItemDeletedEvent $e) use (&$deleted): void {
            $deleted = $e->getFaqIds();
        });

        $this->service->deleteCategory(10, 3);

        $this->assertSame(['find', 'delete'], $order);
        $this->assertSame([41, 42], $deleted);
    }

    public function testDeleteEmptyCategoryDoesNotDispatchDeletedEvent(): void
    {
        $this->repository->method('findCategory')->willReturn(['category_id' => 3]);
        $this->repository->method('findItems')->willReturn([]);
        $this->dispatcher->addListener(FaqItemDeletedEvent::class, fn() => $this->fail('지워진 항목이 없으면 발행하지 않는다'));

        $this->assertTrue($this->service->deleteCategory(10, 3)->isSuccess());
    }
}
