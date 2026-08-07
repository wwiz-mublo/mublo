<?php

namespace Tests\Board\Unit\Helper;

use Mublo\Packages\Board\Helper\ArticlePresenter;
use PHPUnit\Framework\TestCase;

/**
 * 썸네일 폴백 우선순위(resolveThumbnail)와 첨부 파생 필드(buildRelationFields)가
 * 분리되어 동작하는지 검증한다.
 */
class ArticlePresenterTest extends TestCase
{
    private function present(array $overrides): array
    {
        $presenter = new ArticlePresenter();
        $item = array_merge([
            'article_id' => 1,
            'slug'       => 'hello',
            'created_at' => '2026-06-19 10:00:00',
        ], $overrides);

        return $presenter->toView($item, 'notice');
    }

    public function testThumbnailPrefersPrecomputedValue(): void
    {
        $result = $this->present([
            'thumbnail'   => '/precomputed.jpg',
            'attachments' => [
                ['is_image' => true, 'thumb_url' => '/storage/thumb.jpg', 'url' => '/storage/full.jpg'],
            ],
        ]);

        $this->assertSame('/precomputed.jpg', $result['thumbnail']);
    }

    public function testThumbnailFallsBackToFirstImageThumbUrl(): void
    {
        $result = $this->present([
            'thumbnail'   => null,
            'attachments' => [
                ['is_image' => false, 'url' => '/board/1/file/download/9'],
                ['is_image' => true, 'thumb_url' => '/storage/thumb.jpg', 'url' => '/storage/full.jpg'],
            ],
        ]);

        $this->assertSame('/storage/thumb.jpg', $result['thumbnail']);
    }

    public function testThumbnailFallsBackToImageUrlWhenNoThumb(): void
    {
        $result = $this->present([
            'attachments' => [
                ['is_image' => true, 'thumb_url' => null, 'url' => '/storage/full.jpg'],
            ],
        ]);

        $this->assertSame('/storage/full.jpg', $result['thumbnail']);
    }

    public function testNoThumbnailWhenNoImageAttachments(): void
    {
        $result = $this->present([
            'attachments' => [
                ['is_image' => false, 'url' => '/board/1/file/download/9'],
            ],
        ]);

        $this->assertNull($result['thumbnail']);
        $this->assertSame(1, $result['file_count']);
        $this->assertSame(0, $result['image_count']);
        $this->assertTrue($result['has_file']);
        $this->assertFalse($result['has_image']);
    }

    public function testOnlyExplicitPublicFieldsReachTheViewContract(): void
    {
        $result = $this->present([
            'title' => '공개 제목',
            'member_id' => 987654321,
            'author_password' => 'hash',
            'ip_address' => '127.0.0.1',
            'future_internal_column' => 'secret',
            'attachments' => [[
                'attachment_id' => 7,
                'original_name' => 'public.txt',
                'stored_name' => 'secret-hash',
                'file_path' => '/internal/path',
                'future_internal_column' => 'secret',
            ]],
            'links' => [[
                'link_id' => 8,
                'link_url' => 'https://example.test',
                'future_internal_column' => 'secret',
            ]],
        ]);

        $this->assertSame('공개 제목', $result['title']);
        $this->assertTrue($result['is_member']);
        $this->assertArrayNotHasKey('member_id', $result);
        $this->assertArrayNotHasKey('author_password', $result);
        $this->assertArrayNotHasKey('ip_address', $result);
        $this->assertArrayNotHasKey('future_internal_column', $result);
        $this->assertArrayNotHasKey('stored_name', $result['attachments'][0]);
        $this->assertArrayNotHasKey('file_path', $result['attachments'][0]);
        $this->assertArrayNotHasKey('future_internal_column', $result['attachments'][0]);
        $this->assertArrayNotHasKey('future_internal_column', $result['links'][0]);
    }

    public function testUnsafeLegacyLinksDoNotReachViewContract(): void
    {
        $result = $this->present([
            'links' => [
                ['link_id' => 1, 'link_url' => 'javascript://host/%0Aalert(document.domain)'],
                ['link_id' => 2, 'link_url' => 'https://safe.example/path'],
            ],
        ]);

        $this->assertCount(1, $result['links']);
        $this->assertSame('https://safe.example/path', $result['links'][0]['link_url']);
    }
}
