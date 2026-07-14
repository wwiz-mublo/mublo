<?php

namespace Tests\Manual\Unit\Block;

use Mublo\Contract\Manual\ManualBook;
use Mublo\Contract\Manual\ManualPageDetail;
use Mublo\Contract\Manual\ManualPageNode;
use Mublo\Entity\Block\BlockColumn;
use Mublo\Plugin\Manual\Block\ManualBooksRenderer;
use Mublo\Plugin\Manual\Block\ManualPageItemsProvider;
use Mublo\Plugin\Manual\Block\ManualPageRenderer;
use Mublo\Plugin\Manual\Block\ManualRecentRenderer;
use Mublo\Plugin\Manual\Block\ManualTocRenderer;
use Mublo\Plugin\Manual\Dto\ManualRecentPage;
use Mublo\Plugin\Manual\Service\ManualService;
use PHPUnit\Framework\TestCase;

final class ManualBlockTest extends TestCase
{
    public function testBooksRendererUsesSelectedSlugOrderAndEscapesMetadata(): void
    {
        $service = $this->createMock(ManualService::class);
        $service->method('getActiveBooks')->with(7)->willReturn([
            new ManualBook(1, '첫 번째', 'first', '설명', 0),
            new ManualBook(2, '<두 번째>', 'second', '<설명>', 1),
        ]);

        $html = (new ManualBooksRenderer($service))->render($this->column('manual_books', [
            'content_items' => ['second', 'first'],
            'content_config' => ['layout' => 'list', 'pc_count' => 2, 'mo_count' => 1],
        ]));

        $this->assertStringContainsString('manual-block--list', $html);
        $this->assertLessThan(strpos($html, '첫 번째'), strpos($html, '&lt;두 번째&gt;'));
        $this->assertStringNotContainsString('<두 번째>', $html);
        $this->assertStringContainsString('manual-block-item--mo-hidden', $html);
    }

    public function testTocRendererLimitsDepthAndBuildsDeepLinks(): void
    {
        $service = $this->createMock(ManualService::class);
        $service->method('getBookBySlug')->with(7, 'guide')->willReturn(
            new ManualBook(10, '가이드', 'guide', null, 0)
        );
        $service->method('getPageTree')->with(10)->willReturn([
            new ManualPageNode(1, null, '시작', 'start', 0, 0, null, [
                new ManualPageNode(2, 1, '상세', 'detail', 1, 0, null, []),
            ]),
        ]);

        $html = (new ManualTocRenderer($service))->render($this->column('manual_toc', [
            'content_items' => ['guide'],
            'content_config' => ['max_depth' => 1, 'pc_count' => 10, 'mo_count' => 10],
        ]));

        $this->assertStringContainsString('/manual/guide/start', $html);
        $this->assertStringNotContainsString('/manual/guide/detail', $html);
    }

    public function testPageRendererSupportsExcerptAndFullContentModes(): void
    {
        $service = $this->createMock(ManualService::class);
        $service->method('getBookBySlug')->willReturn(new ManualBook(10, '가이드', 'guide', null, 0));
        $service->method('getPageBySlug')->willReturn(
            new ManualPageDetail(20, 10, '<시작>', 'start', '<p><strong>본문</strong> 내용입니다.</p>')
        );

        $renderer = new ManualPageRenderer($service);
        $excerpt = $renderer->render($this->column('manual_page', [
            'content_items' => ['guide/start'],
            'content_config' => ['display_mode' => 'excerpt', 'excerpt_length' => 40],
        ]));
        $full = $renderer->render($this->column('manual_page', [
            'content_items' => ['guide/start'],
            'content_config' => ['display_mode' => 'full'],
        ]));

        $this->assertStringContainsString('&lt;시작&gt;', $excerpt);
        $this->assertStringContainsString('본문 내용입니다.', $excerpt);
        $this->assertStringNotContainsString('<strong>본문</strong>', $excerpt);
        $this->assertStringContainsString('<strong>본문</strong>', $full);
    }

    public function testRecentRendererPassesNaturalBookFiltersAndBuildsExcerpt(): void
    {
        $service = $this->createMock(ManualService::class);
        $service->expects($this->once())->method('getRecentPages')
            ->with(7, ['guide'], 6)
            ->willReturn([
                new ManualRecentPage(20, '업데이트', 'update', '가이드', 'guide', '<p>바뀐 내용</p>', '2026-07-23 10:00:00'),
            ]);

        $html = (new ManualRecentRenderer($service))->render($this->column('manual_recent', [
            'content_items' => ['guide'],
            'content_config' => ['show_excerpt' => true, 'pc_count' => 6, 'mo_count' => 4],
        ]));

        $this->assertStringContainsString('/manual/guide/update', $html);
        $this->assertStringContainsString('바뀐 내용', $html);
        $this->assertStringContainsString('2026.07.23', $html);
    }

    public function testPageItemsProviderFlattensTreeWithPortableReferences(): void
    {
        $service = $this->createMock(ManualService::class);
        $service->method('getActiveBooks')->willReturn([new ManualBook(10, '가이드', 'guide', null, 0)]);
        $service->method('getPageTree')->willReturn([
            new ManualPageNode(1, null, '시작', 'start', 0, 0, null, [
                new ManualPageNode(2, 1, '상세', 'detail', 1, 0, null, []),
            ]),
        ]);

        $items = (new ManualPageItemsProvider($service))->getItems(7);

        $this->assertSame('guide/start', $items[0]['id']);
        $this->assertSame('가이드 › 시작 › 상세', $items[1]['label']);
    }

    private function column(string $contentType, array $overrides = []): BlockColumn
    {
        return BlockColumn::fromArray(array_replace([
            'column_id' => 9,
            'row_id' => 4,
            'domain_id' => 7,
            'column_index' => 0,
            'content_type' => $contentType,
            'content_kind' => 'PLUGIN',
            'content_skin' => 'basic',
            'content_config' => [],
            'content_items' => [],
            'title_config' => [],
            'is_active' => 1,
        ], $overrides));
    }
}
