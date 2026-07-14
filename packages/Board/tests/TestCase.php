<?php
/**
 * packages/Board/tests/TestCase.php
 *
 * Board 패키지 테스트 기본 클래스
 *
 * 외부 개발자가 Board 패키지 테스트를 작성할 때 이 클래스를 상속합니다.
 * 게시글/댓글/반응 등 공통 픽스처 생성 헬퍼를 제공합니다.
 */

namespace Tests\Board;

use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    // =========================================================
    // 픽스처 헬퍼 — Entity::fromArray() 테스트에 사용
    // =========================================================

    /**
     * 기본 게시글 배열 픽스처
     *
     * @param array $overrides 일부 필드만 오버라이드할 때 사용
     */
    protected function makeArticleData(array $overrides = []): array
    {
        return array_merge([
            'article_id'     => 1,
            'domain_id'      => 1,
            'board_id'       => 1,
            'category_id'    => null,
            'member_id'      => 42,
            'author_name'    => '홍길동',
            'author_password'=> null,
            'title'          => '테스트 게시글',
            'slug'           => 'test-article',
            'content'        => '본문 내용',
            'thumbnail'      => null,
            'is_notice'      => false,
            'is_secret'      => false,
            'status'         => 'published',
            'read_level'     => null,
            'download_level' => null,
            'view_count'     => 0,
            'comment_count'  => 0,
            'reaction_count' => 0,
            'location_lat'   => null,
            'location_lng'   => null,
            'ip_address'     => '127.0.0.1',
            'created_at'     => '2026-01-01 00:00:00',
            'updated_at'     => '2026-01-01 00:00:00',
            'published_at'   => '2026-01-01 00:00:00',
        ], $overrides);
    }

    /**
     * 기본 댓글 배열 픽스처
     */
    protected function makeCommentData(array $overrides = []): array
    {
        return array_merge([
            'comment_id'     => 10,
            'domain_id'      => 1,
            'board_id'       => 1,
            'article_id'     => 1,
            'parent_id'      => null,
            'member_id'      => 42,
            'author_name'    => '홍길동',
            'author_password'=> null,
            'content'        => '댓글 내용',
            'is_secret'      => false,
            'status'         => 'published',
            'reaction_count' => 0,
            'depth'          => 0,
            'path'           => '10',
            'ip_address'     => '127.0.0.1',
            'created_at'     => '2026-01-01 00:00:00',
            'updated_at'     => '2026-01-01 00:00:00',
        ], $overrides);
    }

    /**
     * 기본 반응 배열 픽스처
     */
    protected function makeReactionData(array $overrides = []): array
    {
        return array_merge([
            'reaction_id'   => 100,
            'domain_id'     => 1,
            'board_id'      => 1,
            'target_type'   => 'article',
            'target_id'     => 1,
            'member_id'     => 7,
            'reaction_type' => 'like',
            'created_at'    => '2026-01-01 00:00:00',
        ], $overrides);
    }
}
