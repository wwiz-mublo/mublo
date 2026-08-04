<?php

namespace Tests\Board\Integration;

use Mublo\Packages\Board\Repository\BoardConfigRepository;
use Tests\Integration\DatabaseTestCase;

/**
 * 카테고리로 게시판을 찾는 조회를 실 DB 로 검증한다.
 *
 * findByCategoryId() 는 매핑 테이블과 JOIN 한다. JOIN 대상·ON 조건·정렬은 SQL 이
 * 결정하므로 mock 으로는 검증되지 않는다.
 */
class BoardConfigRepositoryTest extends DatabaseTestCase
{
    private BoardConfigRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTable('board_configs', '
            board_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            domain_id BIGINT UNSIGNED NOT NULL,
            group_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            board_slug VARCHAR(50) NOT NULL,
            board_name VARCHAR(100) NOT NULL,
            sort_order INT UNSIGNED NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NULL,
            updated_at DATETIME NULL
        ');

        $this->createTable('board_category_mapping', '
            mapping_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            board_id BIGINT UNSIGNED NOT NULL,
            category_id BIGINT UNSIGNED NOT NULL
        ');

        $this->repository = new BoardConfigRepository($this->db);
    }

    private function seedBoards(): void
    {
        $this->seed('board_configs', [
            ['board_id' => 1, 'domain_id' => 1, 'board_slug' => 'notice', 'board_name' => '공지사항',     'sort_order' => 30],
            ['board_id' => 2, 'domain_id' => 1, 'board_slug' => 'faq',    'board_name' => '자주묻는질문', 'sort_order' => 10],
            ['board_id' => 3, 'domain_id' => 1, 'board_slug' => 'free',   'board_name' => '자유게시판',   'sort_order' => 20],
        ]);

        $this->seed('board_category_mapping', [
            ['board_id' => 1, 'category_id' => 100],
            ['board_id' => 2, 'category_id' => 100],
            ['board_id' => 3, 'category_id' => 200],
        ]);
    }

    public function testReturnsOnlyBoardsMappedToTheCategory(): void
    {
        $this->seedBoards();

        $slugs = array_map(
            fn ($board) => $board->getBoardSlug(),
            $this->repository->findByCategoryId(100)
        );

        // sort_order 오름차순 — faq(10) 가 notice(30) 보다 앞이다.
        $this->assertSame(['faq', 'notice'], $slugs);
    }

    public function testReturnsEmptyWhenNoBoardIsMapped(): void
    {
        $this->seedBoards();

        $this->assertSame([], $this->repository->findByCategoryId(999));
    }

    public function testReturnsOneRowPerMappingWhenABoardIsMappedTwice(): void
    {
        $this->seedBoards();
        // 같은 게시판이 같은 카테고리에 두 번 매핑되면 JOIN 이 행을 늘린다.
        $this->seed('board_category_mapping', [
            ['board_id' => 1, 'category_id' => 300],
            ['board_id' => 1, 'category_id' => 300],
        ]);

        $this->assertCount(2, $this->repository->findByCategoryId(300));
    }
}
