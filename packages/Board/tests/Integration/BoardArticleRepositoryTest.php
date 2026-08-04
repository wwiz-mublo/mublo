<?php

namespace Tests\Board\Integration;

use Mublo\Packages\Board\Repository\BoardArticleRepository;
use Tests\Integration\DatabaseTestCase;

/**
 * 집계 동기화와 일자별 통계를 실 DB 로 검증한다.
 *
 * 카운트 동기화는 다른 테이블을 세어 자기 컬럼에 쓰는 구조라, 세는 범위가
 * 어긋나도 예외가 나지 않고 숫자만 조용히 틀린다. 일자별 집계는 DATE() 로
 * 묶으므로 시간대·경계값을 실제로 실행해야 확인된다.
 */
class BoardArticleRepositoryTest extends DatabaseTestCase
{
    private BoardArticleRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTable('board_articles', '
            article_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            board_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
            domain_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
            member_id BIGINT UNSIGNED NULL,
            subject VARCHAR(200) NOT NULL DEFAULT "",
            status VARCHAR(20) NOT NULL DEFAULT "published",
            view_count INT UNSIGNED NOT NULL DEFAULT 0,
            comment_count INT UNSIGNED NOT NULL DEFAULT 0,
            reaction_count INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NULL,
            updated_at DATETIME NULL
        ');

        $this->createTable('board_comments', '
            comment_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            article_id BIGINT UNSIGNED NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT "published"
        ');

        $this->createTable('board_reactions', '
            reaction_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            target_type VARCHAR(20) NOT NULL,
            target_id BIGINT UNSIGNED NOT NULL
        ');

        $this->repository = new BoardArticleRepository($this->db);
    }

    private function columnOf(int $articleId, string $column): int
    {
        $rows = $this->fetchAll("SELECT {$column} AS v FROM board_articles WHERE article_id = ?", [$articleId]);

        return (int) $rows[0]['v'];
    }

    public function testViewCountIncrementsOnlyTheTargetArticle(): void
    {
        $this->seed('board_articles', [
            ['article_id' => 1, 'view_count' => 5],
            ['article_id' => 2, 'view_count' => 5],
        ]);

        $this->repository->incrementViewCount(1);

        $this->assertSame(6, $this->columnOf(1, 'view_count'));
        $this->assertSame(5, $this->columnOf(2, 'view_count'));
    }

    public function testCommentCountSyncsOnlyPublishedCommentsOfThatArticle(): void
    {
        $this->seed('board_articles', [['article_id' => 1, 'comment_count' => 99]]);
        $this->seed('board_comments', [
            ['article_id' => 1, 'status' => 'published'],
            ['article_id' => 1, 'status' => 'published'],
            // 삭제된 댓글과 다른 글의 댓글은 세지 않는다.
            ['article_id' => 1, 'status' => 'deleted'],
            ['article_id' => 2, 'status' => 'published'],
        ]);

        $this->repository->syncCommentCount(1);

        $this->assertSame(2, $this->columnOf(1, 'comment_count'));
    }

    public function testCommentCountSyncsToZeroWhenAllCommentsAreGone(): void
    {
        $this->seed('board_articles', [['article_id' => 1, 'comment_count' => 7]]);

        $this->repository->syncCommentCount(1);

        $this->assertSame(0, $this->columnOf(1, 'comment_count'));
    }

    public function testReactionCountCountsOnlyArticleTargets(): void
    {
        $this->seed('board_articles', [['article_id' => 1, 'reaction_count' => 0]]);
        $this->seed('board_reactions', [
            ['target_type' => 'article', 'target_id' => 1],
            ['target_type' => 'article', 'target_id' => 1],
            // 같은 ID 라도 댓글 반응은 게시글 반응이 아니다.
            ['target_type' => 'comment', 'target_id' => 1],
            ['target_type' => 'article', 'target_id' => 2],
        ]);

        $this->repository->syncReactionCount(1);

        $this->assertSame(2, $this->columnOf(1, 'reaction_count'));
    }

    public function testDailyCountsGroupByDateAndRespectTheSinceBoundary(): void
    {
        $this->seed('board_articles', [
            ['article_id' => 1, 'domain_id' => 1, 'created_at' => '2026-07-30 09:00:00'],
            ['article_id' => 2, 'domain_id' => 1, 'created_at' => '2026-07-30 23:59:59'],
            ['article_id' => 3, 'domain_id' => 1, 'created_at' => '2026-07-31 00:00:00'],
            // 경계 이전은 빠진다.
            ['article_id' => 4, 'domain_id' => 1, 'created_at' => '2026-07-29 23:59:59'],
            // 미게시와 다른 도메인도 빠진다.
            ['article_id' => 5, 'domain_id' => 1, 'created_at' => '2026-07-30 10:00:00', 'status' => 'draft'],
            ['article_id' => 6, 'domain_id' => 2, 'created_at' => '2026-07-30 10:00:00'],
        ]);

        $this->assertSame(
            ['2026-07-30' => 2, '2026-07-31' => 1],
            $this->repository->getDailyCountsByDomain(1, '2026-07-30 00:00:00')
        );
    }
}
