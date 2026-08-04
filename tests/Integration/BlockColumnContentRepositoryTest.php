<?php

namespace Tests\Integration;

use Mublo\Repository\Block\BlockColumnContentRepository;

/**
 * 콘텐츠 스택 하위 Repository 를 실 DB 로 검증한다 (계획 13.1).
 *
 * 핵심은 stable-ID 동기화다: 정렬·설정 변경이 content_id 를 재발급하면
 * 렌더 키·이미지 식별자·revision 참조가 전부 흔들린다. UPDATE/INSERT/DELETE
 * 판정과 소유권 방어는 SQL WHERE 절이 결정하므로 mock 으로 검증되지 않는다.
 */
class BlockColumnContentRepositoryTest extends DatabaseTestCase
{
    private BlockColumnContentRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

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
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NULL,
            updated_at DATETIME NULL
        ');

        $this->repository = new BlockColumnContentRepository($this->db);
    }

    public function testSyncInsertsNewContentsInArrayOrder(): void
    {
        $ids = $this->repository->syncForColumn(12, 1, [
            ['content_type' => 'html', 'content_kind' => 'CORE', 'content_config' => ['html' => '<p>a</p>']],
            ['content_type' => 'board', 'content_kind' => 'PACKAGE', 'content_items' => [3, 5]],
        ]);

        $this->assertCount(2, $ids);

        $contents = $this->repository->findByColumnForDomain(12, 1, true);
        $this->assertSame('html', $contents[0]->getContentTypeString());
        $this->assertSame(0, $contents[0]->getSortOrder());
        $this->assertSame('board', $contents[1]->getContentTypeString());
        $this->assertSame(1, $contents[1]->getSortOrder());
    }

    public function testReorderKeepsContentIds(): void
    {
        [$firstId, $secondId] = $this->repository->syncForColumn(12, 1, [
            ['content_type' => 'html'],
            ['content_type' => 'board'],
        ]);

        // 순서만 뒤집어 재저장 — content_id 유지, sort_order 만 변경 (계획 6.2.0)
        $ids = $this->repository->syncForColumn(12, 1, [
            ['content_id' => $secondId, 'content_type' => 'board'],
            ['content_id' => $firstId, 'content_type' => 'html'],
        ]);

        $this->assertSame([$secondId, $firstId], $ids);

        $contents = $this->repository->findByColumnForDomain(12, 1, true);
        $this->assertSame($secondId, $contents[0]->getContentId());
        $this->assertSame('board', $contents[0]->getContentTypeString());
        $this->assertSame($firstId, $contents[1]->getContentId());
    }

    public function testSyncDeletesOnlyMissingContents(): void
    {
        [$firstId, $secondId, $thirdId] = $this->repository->syncForColumn(12, 1, [
            ['content_type' => 'html'],
            ['content_type' => 'board'],
            ['content_type' => 'banner'],
        ]);

        $this->repository->syncForColumn(12, 1, [
            ['content_id' => $thirdId, 'content_type' => 'banner'],
            ['content_id' => $firstId, 'content_type' => 'html'],
        ]);

        $remaining = array_map(
            static fn($c) => $c->getContentId(),
            $this->repository->findByColumnForDomain(12, 1, true)
        );
        $this->assertSame([$thirdId, $firstId], $remaining);
        $this->assertNotContains($secondId, $remaining);
    }

    public function testSyncRejectsForeignContentId(): void
    {
        [$foreignId] = $this->repository->syncForColumn(99, 2, [
            ['content_type' => 'html'],
        ]);

        // 다른 칸·도메인의 content_id 를 이 칸 payload 에 실으면 거부 (계획 6.3 소유권)
        $this->expectException(\InvalidArgumentException::class);
        $this->repository->syncForColumn(12, 1, [
            ['content_id' => $foreignId, 'content_type' => 'html'],
        ]);
    }

    public function testInactiveContentSurvivesSyncWhenIncluded(): void
    {
        [$id] = $this->repository->syncForColumn(12, 1, [
            ['content_type' => 'html', 'is_active' => 0],
        ]);

        // 저장 동기화는 비활성 포함으로 읽으므로 "누락 DELETE" 오인이 없다 (계획 6.2.2)
        $ids = $this->repository->syncForColumn(12, 1, [
            ['content_id' => $id, 'content_type' => 'html', 'is_active' => 0],
            ['content_type' => 'board'],
        ]);

        $this->assertSame($id, $ids[0]);
        $this->assertCount(2, $this->repository->findByColumnForDomain(12, 1, true));
        $this->assertCount(1, $this->repository->findByColumnForDomain(12, 1, false));
    }

    public function testBatchPreloadGroupsByColumn(): void
    {
        $this->repository->syncForColumn(12, 1, [['content_type' => 'html']]);
        $this->repository->syncForColumn(13, 1, [['content_type' => 'board'], ['content_type' => 'banner']]);
        $this->repository->syncForColumn(99, 2, [['content_type' => 'html']]); // 다른 도메인 — 제외

        $grouped = $this->repository->findByColumnsForDomain([12, 13, 99], 1);

        $this->assertCount(1, $grouped[12]);
        $this->assertCount(2, $grouped[13]);
        $this->assertArrayNotHasKey(99, $grouped);
    }

    public function testColumnIdLookupsApplyActiveFilter(): void
    {
        $this->repository->syncForColumn(12, 1, [
            ['content_type' => 'faq', 'content_kind' => 'PLUGIN', 'content_items' => [7]],
        ]);
        $this->repository->syncForColumn(13, 1, [
            ['content_type' => 'faq', 'content_kind' => 'PLUGIN', 'is_active' => 0],
        ]);

        // 자식 콘텐츠 비활성은 역참조에서 제외 (계획 6.2.2 고정 문장)
        $this->assertSame([12], $this->repository->findColumnIdsByContentType(1, 'faq'));
        $this->assertSame([12], $this->repository->findColumnIdsByContentKind(1, 'PLUGIN'));
        $this->assertSame([12], $this->repository->findColumnIdsByContentItem(1, 'faq', 7));
        $this->assertSame([], $this->repository->findColumnIdsByContentItem(1, 'faq', 999));
    }
}
