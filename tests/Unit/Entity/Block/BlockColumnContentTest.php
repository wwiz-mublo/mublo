<?php

namespace Tests\Unit\Entity\Block;

use Mublo\Entity\Block\BlockColumn;
use Mublo\Entity\Block\BlockColumnContent;
use Mublo\Enum\Block\BlockContentKind;
use PHPUnit\Framework\TestCase;

/**
 * 콘텐츠 스택 엔티티 계약 (계획 단계 1).
 */
class BlockColumnContentTest extends TestCase
{
    public function testFromArrayParsesJsonFieldsAndKind(): void
    {
        $content = BlockColumnContent::fromArray([
            'content_id' => 31,
            'column_id' => 12,
            'domain_id' => 1,
            'sort_order' => 2,
            'content_type' => 'faq',
            'content_kind' => 'PLUGIN',
            'content_skin' => 'basic',
            'title_config' => '{"show":true,"text":"FAQ"}',
            'content_config' => ['pc_count' => 5],
            'content_items' => '[3,5]',
            'is_active' => 1,
        ]);

        $this->assertSame(31, $content->getContentId());
        $this->assertSame('faq', $content->getContentTypeString());
        $this->assertSame(BlockContentKind::PLUGIN, $content->getContentKind());
        $this->assertSame(['show' => true, 'text' => 'FAQ'], $content->getTitleConfig());
        $this->assertSame(['pc_count' => 5], $content->getContentConfig());
        $this->assertSame([3, 5], $content->getContentItems());
        $this->assertTrue($content->isActive());
    }

    public function testColumnDefaultsToSingleModeWithoutStackFields(): void
    {
        // migration 이전 로우·기존 데이터 — 스택 필드가 없어도 single 로 동작
        $column = BlockColumn::fromArray([
            'column_id' => 1,
            'row_id' => 1,
            'domain_id' => 1,
        ]);

        $this->assertSame('single', $column->getContentMode());
        $this->assertFalse($column->isStack());
        $this->assertSame(0, $column->getPcContentGap());
        $this->assertSame([], $column->getContents());
    }

    public function testColumnTreatsUnknownModeAsSingle(): void
    {
        // 알 수 없는 content_mode 는 읽기 시 single 로 안전 처리 (계획 4.1)
        $column = BlockColumn::fromArray([
            'column_id' => 1,
            'row_id' => 1,
            'domain_id' => 1,
            'content_mode' => 'weird_future_mode',
        ]);

        $this->assertSame('single', $column->getContentMode());
    }

    public function testColumnStackModeAndContentsInjection(): void
    {
        $column = BlockColumn::fromArray([
            'column_id' => 12,
            'row_id' => 1,
            'domain_id' => 1,
            'content_mode' => 'stack',
            'pc_content_gap' => 16,
            'mobile_content_gap' => 8,
        ]);

        $this->assertTrue($column->isStack());
        $this->assertSame(16, $column->getPcContentGap());
        $this->assertSame(8, $column->getMobileContentGap());

        $column->setContents([
            BlockColumnContent::fromArray(['content_id' => 31, 'column_id' => 12, 'domain_id' => 1, 'content_type' => 'html']),
            BlockColumnContent::fromArray(['content_id' => 32, 'column_id' => 12, 'domain_id' => 1, 'content_type' => 'board']),
        ]);

        $this->assertCount(2, $column->getContents());
        $this->assertSame('html', $column->getContents()[0]->getContentTypeString());
    }

    public function testColumnToArrayRoundTripsStackFields(): void
    {
        $column = BlockColumn::fromArray([
            'column_id' => 12,
            'row_id' => 1,
            'domain_id' => 1,
            'content_mode' => 'stack',
            'pc_content_gap' => 16,
            'mobile_content_gap' => 8,
        ]);

        $data = $column->toArray();

        $this->assertSame('stack', $data['content_mode']);
        $this->assertSame(16, $data['pc_content_gap']);
        $this->assertSame(8, $data['mobile_content_gap']);
    }

    public function testNegativeGapIsClampedToZero(): void
    {
        $column = BlockColumn::fromArray([
            'column_id' => 12,
            'row_id' => 1,
            'domain_id' => 1,
            'pc_content_gap' => -5,
        ]);

        $this->assertSame(0, $column->getPcContentGap());
    }
}
