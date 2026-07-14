<?php

namespace Tests\Integration;

use Mublo\Repository\Block\BlockImageReferenceRepository;

/**
 * 블록 업로드 이미지 참조 판정을 실 DB 로 검증한다.
 *
 * 핵심은 스택 자식(block_column_contents)이다: 미러(칸 scalar)에 없는
 * 자식 콘텐츠의 이미지는 이 테이블에만 존재하므로, 조회에서 빠지면
 * 고아 정리 과정에서 실사용 파일이 삭제된다.
 */
class BlockImageReferenceRepositoryTest extends DatabaseTestCase
{
    private BlockImageReferenceRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTable('block_rows', '
            row_id BIGINT AUTO_INCREMENT PRIMARY KEY,
            background_config TEXT NULL
        ');
        $this->createTable('block_columns', '
            column_id BIGINT AUTO_INCREMENT PRIMARY KEY,
            background_config TEXT NULL,
            title_config TEXT NULL,
            content_config TEXT NULL,
            content_items TEXT NULL
        ');
        $this->createTable('block_column_contents', '
            content_id BIGINT AUTO_INCREMENT PRIMARY KEY,
            column_id BIGINT NOT NULL,
            domain_id BIGINT NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            title_config TEXT NULL,
            content_type VARCHAR(50) NULL,
            content_kind VARCHAR(20) NOT NULL DEFAULT "CORE",
            content_skin VARCHAR(50) NULL,
            content_config TEXT NULL,
            content_items TEXT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1
        ');
        $this->createTable('block_row_revisions', '
            revision_id BIGINT AUTO_INCREMENT PRIMARY KEY,
            snapshot_json LONGTEXT NULL
        ');

        $this->repository = new BlockImageReferenceRepository($this->db);
    }

    public function testStackChildContentItemsReferenceIsDetected(): void
    {
        // 미러가 아닌 두 번째 자식에만 있는 이미지 — 칸 scalar 에는 없다.
        $this->seed('block_column_contents', [
            [
                'column_id' => 12,
                'domain_id' => 1,
                'sort_order' => 1,
                'content_type' => 'image',
                'content_items' => '["\/storage\/block\/2026\/stack-child.png"]',
            ],
        ]);

        $this->assertTrue($this->repository->isReferenced('/storage/block/2026/stack-child.png'));
    }

    public function testStackChildContentConfigReferenceIsDetected(): void
    {
        $this->seed('block_column_contents', [
            [
                'column_id' => 12,
                'domain_id' => 1,
                'content_type' => 'html',
                'content_config' => '{"html":"<img src=\"\/storage\/block\/2026\/inline.png\">"}',
            ],
        ]);

        $this->assertTrue($this->repository->isReferenced('/storage/block/2026/inline.png'));
    }

    public function testUnreferencedImageIsNotDetected(): void
    {
        $this->seed('block_column_contents', [
            ['column_id' => 12, 'domain_id' => 1, 'content_items' => '["\/storage\/block\/other.png"]'],
        ]);

        $this->assertFalse($this->repository->isReferenced('/storage/block/2026/ghost.png'));
    }

    public function testWorksWithoutContentsTableBeforeMigration(): void
    {
        // 마이그레이션(021) 전 설치 — 테이블이 없어도 기존 판정 경로가 동작해야 한다.
        self::$pdo->exec('DROP TABLE IF EXISTS `block_column_contents`');

        $this->seed('block_row_revisions', [
            ['snapshot_json' => '{"rows":[{"bg":"\/storage\/block\/rev.png"}]}'],
        ]);

        $this->assertTrue($this->repository->isReferenced('/storage/block/rev.png'));
        $this->assertFalse($this->repository->isReferenced('/storage/block/none.png'));
    }
}
