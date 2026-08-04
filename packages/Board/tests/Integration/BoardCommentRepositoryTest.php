<?php

namespace Tests\Board\Integration;

use Mublo\Packages\Board\Repository\BoardCommentRepository;
use Tests\Integration\DatabaseTestCase;

/**
 * 댓글 계층 경로 생성을 실 DB 로 검증한다.
 *
 * generatePath() 는 VARCHAR 컬럼에 MAX() 를 쓴다. 즉 다음 순번을 문자열 정렬로
 * 고르므로, 자리수 패딩이 어긋나면 10번째 댓글이 9번보다 앞이라고 판정되어
 * 순번이 되감긴다. 되감기면 서로 다른 댓글이 같은 경로를 갖고 정렬이 깨진다.
 * 정렬 규칙은 DB 가 결정하므로 mock 으로는 확인되지 않는다.
 */
class BoardCommentRepositoryTest extends DatabaseTestCase
{
    private BoardCommentRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTable('board_comments', '
            comment_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            article_id BIGINT UNSIGNED NOT NULL,
            parent_id BIGINT UNSIGNED NULL,
            depth TINYINT UNSIGNED NOT NULL DEFAULT 0,
            path VARCHAR(255) NOT NULL DEFAULT "",
            status VARCHAR(20) NOT NULL DEFAULT "published",
            created_at DATETIME NULL,
            updated_at DATETIME NULL
        ');

        $this->repository = new BoardCommentRepository($this->db);
    }

    public function testFirstRootCommentStartsAtOne(): void
    {
        $this->assertSame('0000000001', $this->repository->generatePath(10));
    }

    public function testRootCommentTakesTheNextSequence(): void
    {
        $this->seed('board_comments', [
            ['article_id' => 10, 'parent_id' => null, 'path' => '0000000001'],
            ['article_id' => 10, 'parent_id' => null, 'path' => '0000000002'],
            // 다른 글의 순번은 영향을 주지 않는다.
            ['article_id' => 11, 'parent_id' => null, 'path' => '0000000009'],
        ]);

        $this->assertSame('0000000003', $this->repository->generatePath(10));
    }

    public function testRootSequencePassesTen(): void
    {
        $rows = [];
        for ($i = 1; $i <= 9; $i++) {
            $rows[] = ['article_id' => 10, 'parent_id' => null, 'path' => str_pad((string) $i, 10, '0', STR_PAD_LEFT)];
        }
        $this->seed('board_comments', $rows);

        $this->assertSame('0000000010', $this->repository->generatePath(10));

        $this->seed('board_comments', [
            ['article_id' => 10, 'parent_id' => null, 'path' => '0000000010'],
        ]);

        // 패딩이 없으면 문자열 MAX 가 '9' 를 고르고 순번이 10 으로 되감긴다.
        $this->assertSame('0000000011', $this->repository->generatePath(10));
    }

    public function testFirstReplyIsNestedUnderTheParentPath(): void
    {
        $this->seed('board_comments', [
            ['comment_id' => 1, 'article_id' => 10, 'parent_id' => null, 'path' => '0000000001'],
        ]);

        $this->assertSame('0000000001/0000000001', $this->repository->generatePath(10, 1));
    }

    public function testReplyTakesTheNextSequenceUnderItsOwnParent(): void
    {
        $this->seed('board_comments', [
            ['comment_id' => 1, 'article_id' => 10, 'parent_id' => null, 'path' => '0000000001'],
            ['comment_id' => 2, 'article_id' => 10, 'parent_id' => 1, 'path' => '0000000001/0000000001'],
            ['comment_id' => 3, 'article_id' => 10, 'parent_id' => 1, 'path' => '0000000001/0000000002'],
            // 다른 부모 아래의 순번은 섞이지 않는다.
            ['comment_id' => 4, 'article_id' => 10, 'parent_id' => null, 'path' => '0000000002'],
            ['comment_id' => 5, 'article_id' => 10, 'parent_id' => 4, 'path' => '0000000002/0000000007'],
        ]);

        $this->assertSame('0000000001/0000000003', $this->repository->generatePath(10, 1));
    }

    public function testDepthFollowsTheParent(): void
    {
        $this->seed('board_comments', [
            ['comment_id' => 1, 'article_id' => 10, 'parent_id' => null, 'depth' => 0, 'path' => '0000000001'],
            ['comment_id' => 2, 'article_id' => 10, 'parent_id' => 1, 'depth' => 1, 'path' => '0000000001/0000000001'],
        ]);

        $this->assertSame(0, $this->repository->calculateDepth(null));
        $this->assertSame(1, $this->repository->calculateDepth(1));
        $this->assertSame(2, $this->repository->calculateDepth(2));
    }
}
