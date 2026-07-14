<?php

namespace Tests\Board\Unit\Entity;

use PHPUnit\Framework\TestCase;
use Mublo\Packages\Board\Entity\BoardArticle;
use Mublo\Packages\Board\Enum\ArticleStatus;

/**
 * BoardArticleTest
 *
 * 게시글 엔티티 테스트
 */
class BoardArticleTest extends TestCase
{
    private function makeData(array $overrides = []): array
    {
        return array_merge([
            'article_id'   => 1,
            'domain_id'    => 10,
            'board_id'     => 2,
            'category_id'  => 3,
            'member_id'    => 100,
            'author_name'  => '홍길동',
            'author_password' => null,
            'title'        => '테스트 게시글',
            'slug'         => 'test-article',
            'content'      => '<p>본문 내용</p>',
            'thumbnail'    => '/storage/board/thumb.jpg',
            'is_notice'    => false,
            'is_secret'    => false,
            'status'       => 'published',
            'read_level'   => null,
            'download_level' => null,
            'view_count'   => 42,
            'comment_count' => 5,
            'reaction_count' => 3,
            'location_lat' => null,
            'location_lng' => null,
            'ip_address'   => '127.0.0.1',
            'created_at'   => '2026-01-01 10:00:00',
            'updated_at'   => '2026-01-02 11:00:00',
            'published_at' => '2026-01-01 10:00:00',
        ], $overrides);
    }

    // ─── 기본 생성 ───

    public function testFromArrayCreatesEntity(): void
    {
        $article = BoardArticle::fromArray($this->makeData());

        $this->assertInstanceOf(BoardArticle::class, $article);
        $this->assertSame(1, $article->getArticleId());
        $this->assertSame(10, $article->getDomainId());
        $this->assertSame(2, $article->getBoardId());
    }

    public function testGettersReturnCorrectValues(): void
    {
        $article = BoardArticle::fromArray($this->makeData());

        $this->assertSame(3, $article->getCategoryId());
        $this->assertSame(100, $article->getMemberId());
        $this->assertSame('홍길동', $article->getAuthorName());
        $this->assertSame('테스트 게시글', $article->getTitle());
        $this->assertSame('test-article', $article->getSlug());
        $this->assertSame('<p>본문 내용</p>', $article->getContent());
        $this->assertSame('/storage/board/thumb.jpg', $article->getThumbnail());
        $this->assertSame(42, $article->getViewCount());
        $this->assertSame(5, $article->getCommentCount());
        $this->assertSame(3, $article->getReactionCount());
        $this->assertSame('127.0.0.1', $article->getIpAddress());
    }

    // ─── 상태 Enum ───

    public function testStatusIsPublishedByDefault(): void
    {
        $article = BoardArticle::fromArray($this->makeData(['status' => 'published']));

        $this->assertSame(ArticleStatus::PUBLISHED, $article->getStatus());
        $this->assertTrue($article->isPublished());
        $this->assertFalse($article->isDraft());
        $this->assertFalse($article->isDeleted());
    }

    public function testDraftStatus(): void
    {
        $article = BoardArticle::fromArray($this->makeData(['status' => 'draft']));

        $this->assertTrue($article->isDraft());
        $this->assertFalse($article->isPublished());
    }

    public function testDeletedStatus(): void
    {
        $article = BoardArticle::fromArray($this->makeData(['status' => 'deleted']));

        $this->assertTrue($article->isDeleted());
        $this->assertFalse($article->isPublished());
    }

    public function testInvalidStatusFallsBackToPublished(): void
    {
        $article = BoardArticle::fromArray($this->makeData(['status' => 'invalid_value']));

        $this->assertSame(ArticleStatus::PUBLISHED, $article->getStatus());
    }

    // ─── 불리언 필드 ───

    public function testIsNoticeConvertsToBool(): void
    {
        $notice = BoardArticle::fromArray($this->makeData(['is_notice' => true]));
        $normal = BoardArticle::fromArray($this->makeData(['is_notice' => false]));

        $this->assertTrue($notice->isNotice());
        $this->assertFalse($normal->isNotice());
    }

    public function testIsSecretConvertsToBool(): void
    {
        $secret = BoardArticle::fromArray($this->makeData(['is_secret' => 1]));
        $public = BoardArticle::fromArray($this->makeData(['is_secret' => 0]));

        $this->assertTrue($secret->isSecret());
        $this->assertFalse($public->isSecret());
    }

    // ─── Nullable 필드 ───

    public function testNullableFieldsAcceptNull(): void
    {
        $article = BoardArticle::fromArray($this->makeData([
            'category_id'    => null,
            'slug'           => null,
            'thumbnail'      => null,
            'read_level'     => null,
            'download_level' => null,
            'location_lat'   => null,
            'location_lng'   => null,
            'ip_address'     => null,
            'published_at'   => null,
        ]));

        $this->assertNull($article->getCategoryId());
        $this->assertNull($article->getSlug());
        $this->assertNull($article->getThumbnail());
        $this->assertNull($article->getReadLevel());
        $this->assertNull($article->getDownloadLevel());
        $this->assertNull($article->getLocationLat());
        $this->assertNull($article->getLocationLng());
        $this->assertNull($article->getIpAddress());
        $this->assertNull($article->getPublishedAt());
    }

    // ─── 날짜 필드 ───

    public function testDateFieldsConvertToDateTimeImmutable(): void
    {
        $article = BoardArticle::fromArray($this->makeData([
            'created_at'  => '2026-01-01 10:00:00',
            'updated_at'  => '2026-01-02 11:30:00',
            'published_at' => '2026-01-01 10:05:00',
        ]));

        $this->assertInstanceOf(\DateTimeImmutable::class, $article->getCreatedAt());
        $this->assertInstanceOf(\DateTimeImmutable::class, $article->getUpdatedAt());
        $this->assertInstanceOf(\DateTimeImmutable::class, $article->getPublishedAt());

        $this->assertSame('2026-01-01', $article->getCreatedAt()->format('Y-m-d'));
        $this->assertSame('2026-01-02', $article->getUpdatedAt()->format('Y-m-d'));
    }

    // ─── 작성자 판별 ───

    public function testIsMemberArticleWhenMemberIdSet(): void
    {
        $article = BoardArticle::fromArray($this->makeData(['member_id' => 100]));

        $this->assertTrue($article->isMemberArticle());
        $this->assertFalse($article->isGuestArticle());
    }

    public function testIsGuestArticleWhenMemberIdNull(): void
    {
        $article = BoardArticle::fromArray($this->makeData(['member_id' => null]));

        $this->assertFalse($article->isMemberArticle());
        $this->assertTrue($article->isGuestArticle());
    }

    public function testIsAuthorReturnsTrueForMatchingMemberId(): void
    {
        $article = BoardArticle::fromArray($this->makeData(['member_id' => 100]));

        $this->assertTrue($article->isAuthor(100));
        $this->assertFalse($article->isAuthor(999));
        $this->assertFalse($article->isAuthor(null));
    }

    public function testAuthorDisplayNameReturnsAuthorName(): void
    {
        $article = BoardArticle::fromArray($this->makeData(['author_name' => '홍길동']));
        $this->assertSame('홍길동', $article->getAuthorDisplayName());
    }

    public function testAuthorDisplayNameFallsBackToAnonymous(): void
    {
        $article = BoardArticle::fromArray($this->makeData(['author_name' => null]));
        $this->assertSame('익명', $article->getAuthorDisplayName());
    }

    // ─── 위치 정보 ───

    public function testHasLocationWhenBothCoordinatesSet(): void
    {
        $article = BoardArticle::fromArray($this->makeData([
            'location_lat' => 37.5665,
            'location_lng' => 126.9780,
        ]));

        $this->assertTrue($article->hasLocation());
        $this->assertEqualsWithDelta(37.5665, $article->getLocationLat(), 0.0001);
    }

    public function testHasNoLocationWhenNullCoordinates(): void
    {
        $article = BoardArticle::fromArray($this->makeData([
            'location_lat' => null,
            'location_lng' => null,
        ]));

        $this->assertFalse($article->hasLocation());
    }

    // ─── toArray ───

    public function testToArrayContainsAllKeys(): void
    {
        $article = BoardArticle::fromArray($this->makeData());
        $array = $article->toArray();

        $this->assertArrayHasKey('article_id', $array);
        $this->assertArrayHasKey('domain_id', $array);
        $this->assertArrayHasKey('board_id', $array);
        $this->assertArrayHasKey('title', $array);
        $this->assertArrayHasKey('status', $array);
        $this->assertArrayHasKey('view_count', $array);
        $this->assertArrayHasKey('created_at', $array);
    }

    public function testToArrayStatusIsString(): void
    {
        $article = BoardArticle::fromArray($this->makeData(['status' => 'published']));
        $array = $article->toArray();

        $this->assertSame('published', $array['status']);
    }

    public function testToArrayRoundTrip(): void
    {
        $data = $this->makeData();
        $article = BoardArticle::fromArray($data);
        $array = $article->toArray();

        $this->assertSame($data['article_id'], $array['article_id']);
        $this->assertSame($data['title'], $array['title']);
        $this->assertSame($data['view_count'], $array['view_count']);
    }
}
