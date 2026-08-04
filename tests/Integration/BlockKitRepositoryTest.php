<?php

namespace Tests\Integration;

use Mublo\Entity\Block\BlockKit;
use Mublo\Repository\Block\BlockKitRepository;

/**
 * BlockKitRepository 를 실 DB 로 검증한다.
 *
 * 이 리포지토리의 두 계약은 mock 으로 검증되지 않는다.
 *
 *   1. 목록 조회가 kit_json 을 읽지 않는다 — SELECT 절이 결정하므로 실제 쿼리를 태워야 한다.
 *      mock 은 우리가 준 배열을 그대로 돌려주므로 컬럼이 빠졌는지 알 수 없다.
 *   2. 모든 조회가 domain_id 로 격리된다 — WHERE 절이 결정한다. 빠뜨리면 남의 도메인
 *      블록 킷이 목록에 뜨거나 상세가 열린다.
 */
class BlockKitRepositoryTest extends DatabaseTestCase
{
    private BlockKitRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        // FK 없이 만든다. 여기서 검증하는 것은 리포지토리의 쿼리이지 스키마가 아니다
        // (스키마는 BlockKitsMigrationTest 가 마이그레이션 파일로 검증한다).
        $this->createTable('block_kits', "
            kit_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            domain_id BIGINT UNSIGNED NOT NULL,
            kit_name VARCHAR(100) NOT NULL,
            kit_description VARCHAR(500) NOT NULL DEFAULT '',
            kit_version VARCHAR(20) NOT NULL DEFAULT '1.0.0',
            kit_author VARCHAR(100) NOT NULL DEFAULT '',
            kit_author_url VARCHAR(255) NOT NULL DEFAULT '',
            target_kind VARCHAR(10) NOT NULL,
            target_position VARCHAR(20) NULL,
            target_menu_code VARCHAR(50) NULL,
            target_page_code VARCHAR(50) NULL,
            export_mode VARCHAR(20) NOT NULL DEFAULT 'distribution',
            contains_script TINYINT(1) NOT NULL DEFAULT 0,
            row_count INT UNSIGNED NOT NULL DEFAULT 0,
            column_count INT UNSIGNED NOT NULL DEFAULT 0,
            kit_json LONGTEXT NOT NULL,
            screenshot_path VARCHAR(255) NULL,
            source_type VARCHAR(10) NOT NULL DEFAULT 'upload',
            is_deleted TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ");

        $this->repository = new BlockKitRepository($this->db);
    }

    private function seedKits(): void
    {
        $this->seed('block_kits', [
            ['kit_id' => 1, 'domain_id' => 1, 'kit_name' => '히어로',   'target_kind' => 'position', 'kit_json' => '{"format":"mublo-starter-kit","rows":[]}'],
            ['kit_id' => 2, 'domain_id' => 1, 'kit_name' => '푸터',     'target_kind' => 'position', 'kit_json' => '{"a":1}'],
            ['kit_id' => 3, 'domain_id' => 1, 'kit_name' => '지워진 블록 킷', 'target_kind' => 'page',     'kit_json' => '{}', 'is_deleted' => 1],
            ['kit_id' => 4, 'domain_id' => 2, 'kit_name' => '남의 블록 킷',   'target_kind' => 'position', 'kit_json' => '{}'],
        ]);
    }

    /** @return string[] */
    private function namesOf(array $kits): array
    {
        return array_map(fn (BlockKit $k) => $k->getKitName(), $kits);
    }

    // ========================================
    // 도메인 격리
    // ========================================

    public function testListingIsScopedToDomainAndExcludesDeleted(): void
    {
        $this->seedKits();

        // 최신순이므로 푸터(2) → 히어로(1)
        $this->assertSame(['푸터', '히어로'], $this->namesOf($this->repository->findAllByDomain(1)));
    }

    public function testCountIgnoresOtherDomainsAndDeletedKits(): void
    {
        $this->seedKits();

        $this->assertSame(2, $this->repository->countByDomain(1));
        $this->assertSame(1, $this->repository->countByDomain(2));
    }

    /** URL 로 넘어온 kit_id 가 남의 도메인 것이면 열리면 안 된다. */
    public function testFindByDomainRefusesAnotherDomainsKit(): void
    {
        $this->seedKits();

        $this->assertNull($this->repository->findByDomain(1, 4), '도메인 2의 블록 킷이 도메인 1에서 열렸다');
        $this->assertNotNull($this->repository->findByDomain(2, 4));
    }

    public function testFindByDomainRefusesDeletedKit(): void
    {
        $this->seedKits();

        $this->assertNull($this->repository->findByDomain(1, 3));
    }

    /** 본문 조회도 같은 격리를 받아야 한다. 여기가 뚫리면 남의 블록 킷을 통째로 내려받는다. */
    public function testFindWithJsonRefusesAnotherDomainsKit(): void
    {
        $this->seedKits();

        $this->assertNull($this->repository->findWithJson(1, 4));
    }

    // ========================================
    // kit_json 로딩 경계
    // ========================================

    /** 목록은 2 MiB 짜리 본문을 읽지 않는다. */
    public function testListingDoesNotLoadKitJson(): void
    {
        $this->seedKits();

        $kit = $this->repository->findAllByDomain(1)[1]; // 히어로
        $this->assertFalse($kit->hasJson());
        $this->assertNull($kit->getKitJson());
    }

    public function testFindByDomainDoesNotLoadKitJson(): void
    {
        $this->seedKits();

        $this->assertFalse($this->repository->findByDomain(1, 1)->hasJson());
    }

    public function testFindWithJsonLoadsAndDecodesBody(): void
    {
        $this->seedKits();

        $kit = $this->repository->findWithJson(1, 1);

        $this->assertTrue($kit->hasJson());
        $this->assertSame('mublo-starter-kit', $kit->decodeJson()['format']);
    }

    /** 본문이 안 실린 엔티티에서 decodeJson() 은 null 이다. "빈 블록 킷" 이 아니라 "안 읽음". */
    public function testDecodeJsonIsNullWhenBodyWasNotLoaded(): void
    {
        $this->seedKits();

        $this->assertNull($this->repository->findByDomain(1, 1)->decodeJson());
    }

    // ========================================
    // 소프트 삭제
    // ========================================

    /** 적용 이력이 FK 로 매달려 있으므로 행을 지우지 않는다. */
    public function testSoftDeleteHidesKitButKeepsRow(): void
    {
        $this->seedKits();

        $this->assertSame(1, $this->repository->softDelete(1, 1));
        $this->assertNull($this->repository->findByDomain(1, 1));

        $rows = $this->fetchAll('SELECT is_deleted FROM block_kits WHERE kit_id = 1');
        $this->assertCount(1, $rows, '행은 남아야 한다');
        $this->assertSame(1, (int) $rows[0]['is_deleted']);
    }

    public function testSoftDeleteCannotTouchAnotherDomainsKit(): void
    {
        $this->seedKits();

        $this->assertSame(0, $this->repository->softDelete(1, 4));
        $this->assertNotNull($this->repository->findByDomain(2, 4), '도메인 2의 블록 킷이 지워졌다');
    }
}
