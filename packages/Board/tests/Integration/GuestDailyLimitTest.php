<?php

namespace Tests\Board\Integration;

use Mublo\Packages\Board\Repository\BoardArticleRepository;
use Mublo\Packages\Board\Repository\BoardCommentRepository;
use Tests\Integration\DatabaseTestCase;

/**
 * 비회원 1일 작성 집계를 실 DB 로 검증한다.
 *
 * 비회원은 식별자가 IP 뿐이라 집계 범위가 어긋나기 쉽다 — 회원 글까지 세면
 * 한도가 조기에 걸리고, 다른 게시판이나 어제 글을 세면 숫자가 틀린다. 범위는
 * WHERE 절이 결정하므로 mock 으로는 확인되지 않는다.
 */
class GuestDailyLimitTest extends DatabaseTestCase
{
    private BoardArticleRepository $articles;
    private BoardCommentRepository $comments;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTable('board_articles', '
            article_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            board_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
            domain_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
            member_id BIGINT UNSIGNED NULL,
            ip_address VARCHAR(45) NULL,
            subject VARCHAR(200) NOT NULL DEFAULT "",
            status VARCHAR(20) NOT NULL DEFAULT "published",
            created_at DATETIME NULL,
            updated_at DATETIME NULL
        ');

        $this->createTable('board_comments', '
            comment_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            board_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
            article_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
            member_id BIGINT UNSIGNED NULL,
            ip_address VARCHAR(45) NULL,
            status VARCHAR(20) NOT NULL DEFAULT "published",
            created_at DATETIME NULL,
            updated_at DATETIME NULL
        ');

        $this->articles = new BoardArticleRepository($this->db);
        $this->comments = new BoardCommentRepository($this->db);
    }

    private function today(string $time = '10:00:00'): string
    {
        return date('Y-m-d') . ' ' . $time;
    }

    private function yesterday(): string
    {
        return date('Y-m-d', strtotime('-1 day')) . ' 23:59:59';
    }

    public function testCountsOnlyTodaysGuestArticlesFromTheSameIp(): void
    {
        $this->seed('board_articles', [
            ['board_id' => 1, 'member_id' => null, 'ip_address' => '203.0.113.9', 'created_at' => $this->today()],
            ['board_id' => 1, 'member_id' => null, 'ip_address' => '203.0.113.9', 'created_at' => $this->today('11:00:00')],

            // 다른 IP · 회원 글 · 다른 게시판 · 어제 · 삭제됨 — 전부 제외
            ['board_id' => 1, 'member_id' => null, 'ip_address' => '198.51.100.7', 'created_at' => $this->today()],
            ['board_id' => 1, 'member_id' => 5,    'ip_address' => '203.0.113.9',  'created_at' => $this->today()],
            ['board_id' => 2, 'member_id' => null, 'ip_address' => '203.0.113.9',  'created_at' => $this->today()],
            ['board_id' => 1, 'member_id' => null, 'ip_address' => '203.0.113.9',  'created_at' => $this->yesterday()],
            ['board_id' => 1, 'member_id' => null, 'ip_address' => '203.0.113.9',  'created_at' => $this->today(), 'status' => 'deleted'],
        ]);

        $this->assertSame(2, $this->articles->countTodayByIp(1, '203.0.113.9'));
    }

    public function testMemberCountIsNotAffectedByGuestRows(): void
    {
        $this->seed('board_articles', [
            ['board_id' => 1, 'member_id' => 5,    'ip_address' => '203.0.113.9', 'created_at' => $this->today()],
            ['board_id' => 1, 'member_id' => null, 'ip_address' => '203.0.113.9', 'created_at' => $this->today()],
        ]);

        $this->assertSame(1, $this->articles->countTodayByMember(1, 5));
        $this->assertSame(1, $this->articles->countTodayByIp(1, '203.0.113.9'));
    }

    public function testEmptyIpCountsAsZeroSoTheLimitNeverBlocksOnUnknownClients(): void
    {
        $this->seed('board_articles', [
            ['board_id' => 1, 'member_id' => null, 'ip_address' => null, 'created_at' => $this->today()],
            ['board_id' => 1, 'member_id' => null, 'ip_address' => '',   'created_at' => $this->today()],
        ]);

        $this->assertSame(0, $this->articles->countTodayByIp(1, ''));
    }

    public function testCountsOnlyTodaysGuestCommentsFromTheSameIp(): void
    {
        $this->seed('board_comments', [
            ['board_id' => 1, 'member_id' => null, 'ip_address' => '203.0.113.9', 'created_at' => $this->today()],
            ['board_id' => 1, 'member_id' => null, 'ip_address' => '203.0.113.9', 'created_at' => $this->today('11:00:00')],
            ['board_id' => 1, 'member_id' => 5,    'ip_address' => '203.0.113.9', 'created_at' => $this->today()],
            ['board_id' => 2, 'member_id' => null, 'ip_address' => '203.0.113.9', 'created_at' => $this->today()],
            ['board_id' => 1, 'member_id' => null, 'ip_address' => '203.0.113.9', 'created_at' => $this->yesterday()],
        ]);

        $this->assertSame(2, $this->comments->countTodayByIp(1, '203.0.113.9'));
    }
}
