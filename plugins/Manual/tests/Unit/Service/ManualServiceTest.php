<?php

namespace Tests\Manual\Unit\Service;

use Mublo\Contract\Manual\ManualBook;
use Mublo\Contract\Manual\ManualPageNode;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Plugin\Manual\Dto\ManualRecentPage;
use Mublo\Plugin\Manual\Event\ManualContentChangedEvent;
use Mublo\Plugin\Manual\Repository\ManualRepository;
use Mublo\Plugin\Manual\Service\ManualService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ManualServiceTest extends TestCase
{
    private string $storagePath;

    protected function setUp(): void
    {
        $this->storagePath = sys_get_temp_dir() . '/mublo-manual-' . bin2hex(random_bytes(6));
        mkdir($this->storagePath, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->storagePath);
    }

    public function testSetBookActiveUpdatesOnlyFrontVisibility(): void
    {
        $repository = $this->repository();
        $repository->method('findBook')->with(10, 7)->willReturn([
            'book_id' => 10,
            'domain_id' => 7,
            'title' => '운영 매뉴얼',
            'is_active' => 1,
        ]);
        $repository->expects($this->once())->method('updateBook')
            ->with(10, 7, ['is_active' => 0])
            ->willReturn(1);

        $result = (new ManualService($repository, $this->storagePath))->setBookActive(7, 10, false);

        $this->assertTrue($result->isSuccess(), $result->getMessage());
        $this->assertSame(10, $result->get('book_id'));
        $this->assertSame(0, $result->get('is_active'));
        $this->assertStringContainsString('숨겼습니다', $result->getMessage());
    }

    public function testSuccessfulWriteDispatchesManualContentChangedEvent(): void
    {
        $repository = $this->repository();
        $repository->method('findBook')->willReturn([
            'book_id' => 10,
            'domain_id' => 7,
            'is_active' => 1,
        ]);
        $repository->method('updateBook')->willReturn(1);

        $events = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(
            ManualContentChangedEvent::class,
            static function (ManualContentChangedEvent $event) use (&$events): void {
                $events[] = [$event->getDomainId(), $event->getChangeType()];
            }
        );

        $result = (new ManualService($repository, $this->storagePath, $dispatcher))
            ->setBookActive(7, 10, false);

        $this->assertTrue($result->isSuccess());
        $this->assertSame([[7, 'book_visibility_changed']], $events);
    }

    public function testUpdatePagePersistsParentAndRecalculatesDescendantDepths(): void
    {
        $repository = $this->repository();
        $repository->method('transaction')->willReturnCallback(static fn (callable $callback): mixed => $callback());
        $pages = [
            ['page_id' => 1, 'book_id' => 10, 'parent_id' => null, 'depth' => 0],
            ['page_id' => 2, 'book_id' => 10, 'parent_id' => null, 'depth' => 0, 'sort_order' => 0, 'is_active' => 1],
            ['page_id' => 3, 'book_id' => 10, 'parent_id' => 2, 'depth' => 1],
        ];

        $repository->method('findPage')->willReturnCallback(
            static fn (int $pageId, ?int $bookId = null): ?array => match ($pageId) {
                1 => $pages[0],
                2 => $pages[1],
                3 => $pages[2],
                default => null,
            }
        );
        $repository->method('findBook')->willReturn(['book_id' => 10, 'domain_id' => 7]);
        $repository->method('findPages')->willReturn($pages);
        $repository->method('existsPageSlug')->willReturn(false);

        $updatedPage = null;
        $repository->expects($this->once())->method('updatePage')
            ->willReturnCallback(function (int $pageId, int $bookId, array $data) use (&$updatedPage): int {
                $updatedPage = $data;
                return 1;
            });

        $depthUpdates = [];
        $repository->expects($this->once())->method('updatePageDepth')
            ->willReturnCallback(function (int $pageId, int $bookId, int $depth) use (&$depthUpdates): int {
                $depthUpdates[$pageId] = $depth;
                return 1;
            });

        $result = (new ManualService($repository, $this->storagePath))->updatePage(7, 2, [
            'title' => 'Moved',
            'slug' => 'moved',
            'parent_id' => 1,
            'content' => '',
        ]);

        $this->assertTrue($result->isSuccess(), $result->getMessage());
        $this->assertSame(1, $updatedPage['parent_id']);
        $this->assertSame(1, $updatedPage['depth']);
        $this->assertSame([3 => 2], $depthUpdates);
    }

    public function testUpdatePageRejectsSelfParentWithoutWriting(): void
    {
        $repository = $this->repository();
        $page = [
            'page_id' => 2,
            'book_id' => 10,
            'parent_id' => null,
            'depth' => 0,
            'sort_order' => 0,
            'is_active' => 1,
        ];
        $repository->method('findPage')->willReturn($page);
        $repository->method('findBook')->willReturn(['book_id' => 10, 'domain_id' => 7]);
        $repository->method('findPages')->willReturn([$page]);
        $repository->expects($this->never())->method('updatePage');
        $repository->expects($this->never())->method('transaction');

        $result = (new ManualService($repository, $this->storagePath))->updatePage(7, 2, [
            'title' => 'Self cycle',
            'slug' => 'self-cycle',
            'parent_id' => 2,
        ]);

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('순환', $result->getMessage());
    }

    public function testSaveTreeRejectsCycleWithoutPartialUpdates(): void
    {
        $repository = $this->repository();
        $repository->method('findBook')->willReturn(['book_id' => 10, 'domain_id' => 7]);
        $repository->method('findPages')->willReturn([
            ['page_id' => 1],
            ['page_id' => 2],
        ]);
        $repository->expects($this->never())->method('transaction');
        $repository->expects($this->never())->method('updatePageTreeNode');

        $result = (new ManualService($repository, $this->storagePath))->saveTree(7, 10, [
            ['page_id' => 1, 'parent_id' => 2, 'sort_order' => 0],
            ['page_id' => 2, 'parent_id' => 1, 'sort_order' => 0],
        ]);

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('순환', $result->getMessage());
    }

    public function testDeletePageRemovesSubtreeFilesOnlyAfterDatabaseSuccess(): void
    {
        foreach ([2, 3] as $pageId) {
            $directory = $this->storagePath . '/D7/manual/' . $pageId;
            mkdir($directory, 0777, true);
            file_put_contents($directory . '/image.jpg', 'image');
        }

        $repository = $this->repository();
        $repository->method('transaction')->willReturnCallback(static fn (callable $callback): mixed => $callback());
        $repository->method('findPage')->willReturn([
            'page_id' => 2,
            'book_id' => 10,
        ]);
        $repository->method('findBook')->willReturn(['book_id' => 10, 'domain_id' => 7]);
        $repository->method('findPages')->willReturn([
            ['page_id' => 2, 'parent_id' => null],
            ['page_id' => 3, 'parent_id' => 2],
        ]);
        $repository->expects($this->exactly(2))->method('deletePage')->willReturn(1);

        $result = (new ManualService($repository, $this->storagePath))->deletePage(7, 2);

        $this->assertTrue($result->isSuccess(), $result->getMessage());
        $this->assertDirectoryDoesNotExist($this->storagePath . '/D7/manual/2');
        $this->assertDirectoryDoesNotExist($this->storagePath . '/D7/manual/3');
    }

    public function testImportSkinTutorialDoesNotOverwriteExistingBook(): void
    {
        $repository = $this->repository();
        $repository->method('findBookBySlug')->willReturn([
            'book_id' => 42,
            'domain_id' => 7,
            'slug' => ManualService::SKIN_TUTORIAL_SLUG,
        ]);
        $repository->expects($this->never())->method('transaction');
        $repository->expects($this->never())->method('insertBook');

        $result = (new ManualService($repository, $this->storagePath))
            ->importSkinDevelopmentTutorial(7);

        $this->assertTrue($result->isSuccess(), $result->getMessage());
        $this->assertTrue($result->get('already_exists'));
        $this->assertSame(42, $result->get('book_id'));
    }

    public function testImportSkinTutorialCreatesEditableBookAndPages(): void
    {
        $repository = $this->repository();
        $repository->method('findBookBySlug')->willReturn(null);
        $repository->method('transaction')->willReturnCallback(static fn (callable $callback): mixed => $callback());
        $repository->expects($this->once())->method('insertBook')
            ->with($this->callback(static fn (array $book): bool =>
                $book['domain_id'] === 7
                && $book['slug'] === ManualService::SKIN_TUTORIAL_SLUG
                && $book['is_active'] === 1
            ))
            ->willReturn(51);

        $pages = [];
        $repository->expects($this->exactly(8))->method('insertPage')
            ->willReturnCallback(static function (array $page) use (&$pages): int {
                $pages[] = $page;
                return 100 + count($pages);
            });

        $result = (new ManualService($repository, $this->storagePath))
            ->importSkinDevelopmentTutorial(7);

        $this->assertTrue($result->isSuccess(), $result->getMessage());
        $this->assertFalse($result->get('already_exists'));
        $this->assertSame(51, $result->get('book_id'));
        $this->assertCount(8, $pages);
        $this->assertSame('overview', $pages[0]['slug']);
        $this->assertSame('checklist', $pages[7]['slug']);
        $this->assertSame(51, $pages[0]['book_id']);
        $this->assertNull($pages[0]['parent_id']);
        $this->assertStringContainsString('$mublo', $pages[2]['content']);
    }

    public function testImportBoardManualCreatesEditableBookAndNestedPages(): void
    {
        $this->assertBundledManualImport(
            method: 'importBoardManual',
            expectedSlug: ManualService::BOARD_MANUAL_SLUG,
            expectedPageCount: 12,
            expectedFirstPage: 'start',
            expectedLastPage: 'release-checklist',
        );
    }

    public function testImportShopManualCreatesEditableBookAndNestedPages(): void
    {
        $this->assertBundledManualImport(
            method: 'importShopManual',
            expectedSlug: ManualService::SHOP_MANUAL_SLUG,
            expectedPageCount: 30,
            expectedFirstPage: 'start',
            expectedLastPage: 'release-checklist',
        );
    }

    public function testEnsureDefaultManualsPreservesExistingBooks(): void
    {
        $repository = $this->repository();
        $repository->method('findBookBySlug')->willReturnCallback(
            static fn (int $domainId, string $slug): ?array => [
                'book_id' => $slug === ManualService::BOARD_MANUAL_SLUG ? 81 : 82,
                'domain_id' => $domainId,
                'slug' => $slug,
            ]
        );
        $repository->method('findPageBySlug')->willReturnCallback(
            static fn (int $bookId): array => [
                'page_id' => $bookId * 10,
                'book_id' => $bookId,
                'slug' => 'start',
                'content' => $bookId === 81
                    ? '<!-- mublo-bundle:board:v2 -->'
                    : '<!-- mublo-bundle:shop:v3 -->',
            ]
        );
        $repository->expects($this->never())->method('transaction');
        $repository->expects($this->never())->method('insertBook');
        $repository->expects($this->never())->method('insertPage');

        $results = (new ManualService($repository, $this->storagePath))->ensureDefaultManuals(7);

        $this->assertTrue($results['board']->isSuccess());
        $this->assertTrue($results['board']->get('already_exists'));
        $this->assertSame(81, $results['board']->get('book_id'));
        $this->assertTrue($results['shop']->isSuccess());
        $this->assertTrue($results['shop']->get('already_exists'));
        $this->assertSame(82, $results['shop']->get('book_id'));
    }

    public function testEnsureDefaultManualsRefreshesOutdatedBundledPages(): void
    {
        $repository = $this->repository();
        $repository->method('findBookBySlug')->willReturnCallback(
            static fn (int $domainId, string $slug): array => [
                'book_id' => $slug === ManualService::BOARD_MANUAL_SLUG ? 81 : 82,
                'domain_id' => $domainId,
                'slug' => $slug,
            ]
        );
        $repository->method('findPageBySlug')->willReturn(null);
        $repository->method('transaction')->willReturnCallback(static fn (callable $callback): mixed => $callback());
        $repository->method('findPages')->willReturnCallback(static function (int $bookId): array {
            $file = $bookId === 81 ? 'board-manual.php' : 'shop-manual.php';
            $template = require dirname(__DIR__, 3) . '/resources/manuals/' . $file;

            return array_map(
                static fn (array $page, int $index): array => [
                    'page_id' => ($bookId * 100) + $index + 1,
                    'book_id' => $bookId,
                    'slug' => $page['slug'],
                ],
                $template['pages'],
                array_keys($template['pages'])
            );
        });
        $repository->expects($this->exactly(42))->method('updatePage')->willReturn(1);
        $repository->expects($this->never())->method('insertPage');

        $results = (new ManualService($repository, $this->storagePath))->ensureDefaultManuals(7);

        $this->assertTrue($results['board']->get('refreshed'));
        $this->assertTrue($results['shop']->get('refreshed'));
    }

    public function testGetActiveBooksReturnsBookDtos(): void
    {
        $repository = $this->repository();
        $repository->method('findBooks')->willReturn([
            ['book_id' => 3, 'title' => '시작하기', 'slug' => 'start', 'description' => '', 'sort_order' => 1],
        ]);

        $books = (new ManualService($repository, $this->storagePath))->getActiveBooks(7);

        $this->assertCount(1, $books);
        $this->assertInstanceOf(ManualBook::class, $books[0]);
        $this->assertSame(3, $books[0]->bookId);
        $this->assertSame('start', $books[0]->slug);
        // 빈 문자열 설명은 null 로 정규화된다.
        $this->assertNull($books[0]->description);
    }

    public function testGetPageTreeReturnsNodeDtosWithNestedChildrenAndContent(): void
    {
        $repository = $this->repository();
        $repository->method('findPages')->willReturn([
            ['page_id' => 1, 'book_id' => 10, 'parent_id' => null, 'title' => '루트', 'slug' => 'root',
             'depth' => 0, 'sort_order' => 0, 'content' => '<p>본문</p>'],
            ['page_id' => 2, 'book_id' => 10, 'parent_id' => 1, 'title' => '자식', 'slug' => 'child',
             'depth' => 1, 'sort_order' => 0, 'content' => ''],
        ]);

        $tree = (new ManualService($repository, $this->storagePath))->getPageTree(10);

        $this->assertCount(1, $tree);
        $this->assertInstanceOf(ManualPageNode::class, $tree[0]);
        $this->assertSame(1, $tree[0]->pageId);
        $this->assertSame('<p>본문</p>', $tree[0]->content);
        $this->assertCount(1, $tree[0]->children);
        $this->assertInstanceOf(ManualPageNode::class, $tree[0]->children[0]);
        $this->assertSame(2, $tree[0]->children[0]->pageId);
        $this->assertSame(1, $tree[0]->children[0]->parentId);
    }

    public function testGetPageBySlugReturnsNullWhenMissing(): void
    {
        $repository = $this->repository();
        $repository->method('findPageBySlug')->willReturn(null);

        $page = (new ManualService($repository, $this->storagePath))->getPageBySlug(10, 'nope');

        $this->assertNull($page);
    }

    public function testGetRecentPagesFiltersInvalidBookReferencesAndReturnsDtos(): void
    {
        $repository = $this->repository();
        $repository->expects($this->once())->method('findRecentPages')
            ->with(7, ['guide', 'shop'], 100)
            ->willReturn([[
                'page_id' => 20,
                'page_title' => '새 문서',
                'page_slug' => 'new-page',
                'book_title' => '가이드',
                'book_slug' => 'guide',
                'content' => '<p>본문</p>',
                'updated_at' => '2026-07-23 10:00:00',
            ]]);

        $items = (new ManualService($repository, $this->storagePath))->getRecentPages(
            7,
            ['guide', '../bad', 'shop', 'guide'],
            500
        );

        $this->assertCount(1, $items);
        $this->assertInstanceOf(ManualRecentPage::class, $items[0]);
        $this->assertSame('new-page', $items[0]->pageSlug);
    }

    /** @return ManualRepository&MockObject */
    private function repository(): ManualRepository
    {
        return $this->createMock(ManualRepository::class);
    }

    private function assertBundledManualImport(
        string $method,
        string $expectedSlug,
        int $expectedPageCount,
        string $expectedFirstPage,
        string $expectedLastPage,
    ): void {
        $repository = $this->repository();
        $repository->method('findBookBySlug')->willReturn(null);
        $repository->method('transaction')->willReturnCallback(static fn (callable $callback): mixed => $callback());
        $repository->expects($this->once())->method('insertBook')
            ->with($this->callback(static fn (array $book): bool =>
                $book['domain_id'] === 7
                && $book['slug'] === $expectedSlug
                && $book['is_active'] === 1
            ))
            ->willReturn(71);

        $pages = [];
        $repository->expects($this->exactly($expectedPageCount))->method('insertPage')
            ->willReturnCallback(static function (array $page) use (&$pages): int {
                $pages[] = $page;
                return 200 + count($pages);
            });

        $service = new ManualService($repository, $this->storagePath);
        $result = $service->{$method}(7);

        $this->assertTrue($result->isSuccess(), $result->getMessage());
        $this->assertFalse($result->get('already_exists'));
        $this->assertSame(71, $result->get('book_id'));
        $this->assertCount($expectedPageCount, $pages);
        $this->assertSame($expectedFirstPage, $pages[0]['slug']);
        $this->assertSame($expectedLastPage, $pages[$expectedPageCount - 1]['slug']);
        $this->assertNull($pages[0]['parent_id']);
        $this->assertSame(0, $pages[0]['depth']);
        $this->assertSame(201, $pages[1]['parent_id']);
        $this->assertSame(1, $pages[1]['depth']);
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() && !$item->isLink()
                ? @rmdir($item->getPathname())
                : @unlink($item->getPathname());
        }
        @rmdir($directory);
    }
}
