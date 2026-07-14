<?php

namespace Tests\Integration;

use Mublo\Repository\Block\BlockRowRepository;

/**
 * 블록 킷 replace 모드의 삭제 스코프를 실 DB 로 검증한다.
 *
 * deleteByPosition() 은 블록 킷이 지정한 target 범위만 지워야 한다. 이 조건이 어긋나면
 * 무관한 화면이 비워지거나(과삭제), 살아남은 행이 중복으로 쌓인다(과소삭제).
 * 두 사고 모두 SQL 의 WHERE 절이 결정하므로 mock 으로는 검증되지 않는다.
 *
 * 설계상 주의점:
 *   - 프론트 렌더용 findByPosition() 은 is_active=1 로 거르고, menuCode 를 주면
 *     position_menu IS NULL 행까지 함께 매칭한다. 삭제에 그 조건을 쓰면 안 된다.
 *   - 전역 행은 NULL 이 정상이지만 시더·직접 INSERT 로 빈 문자열이 들어올 수 있어,
 *     내보내기와 삭제가 둘 다 ''를 전역으로 봐야 replace 후 중복이 남지 않는다.
 */
class BlockRowRepositoryTest extends DatabaseTestCase
{
    private BlockRowRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTable('block_rows', '
            row_id INT AUTO_INCREMENT PRIMARY KEY,
            domain_id INT NOT NULL,
            page_id INT NULL,
            position VARCHAR(20) NULL,
            position_menu VARCHAR(50) NULL,
            admin_title VARCHAR(100) NULL,
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NULL,
            updated_at DATETIME NULL
        ');

        $this->repository = new BlockRowRepository($this->db);
    }

    private function seedRows(): void
    {
        $this->seed('block_rows', [
            // 도메인 1, index 위치
            ['domain_id' => 1, 'position' => 'index', 'position_menu' => null,   'is_active' => 1, 'admin_title' => 'global-null'],
            ['domain_id' => 1, 'position' => 'index', 'position_menu' => '',     'is_active' => 1, 'admin_title' => 'global-empty'],
            ['domain_id' => 1, 'position' => 'index', 'position_menu' => null,   'is_active' => 0, 'admin_title' => 'global-inactive'],
            ['domain_id' => 1, 'position' => 'index', 'position_menu' => 'shop', 'is_active' => 1, 'admin_title' => 'menu-shop'],

            // 다른 위치 / 다른 도메인 / 페이지 행 — 절대 지워지면 안 된다
            ['domain_id' => 1, 'position' => 'top',   'position_menu' => null,   'is_active' => 1, 'admin_title' => 'other-position'],
            ['domain_id' => 2, 'position' => 'index', 'position_menu' => null,   'is_active' => 1, 'admin_title' => 'other-domain'],
            ['domain_id' => 1, 'position' => null,    'position_menu' => null,   'is_active' => 1, 'admin_title' => 'page-row', 'page_id' => 5],
        ]);
    }

    /** @return string[] 남아 있는 행의 admin_title */
    private function remainingTitles(): array
    {
        $titles = array_column($this->fetchAll('SELECT admin_title FROM block_rows ORDER BY admin_title'), 'admin_title');
        sort($titles);

        return $titles;
    }

    // ========================================
    // 전역 스코프 (menuCode = null)
    // ========================================

    public function testGlobalDeleteRemovesNullAndEmptyMenuRows(): void
    {
        $this->seedRows();

        $deleted = $this->repository->deleteByPosition(1, 'index', null);

        $this->assertSame(3, $deleted, 'NULL · 빈문자열 · 비활성 전역 행이 모두 지워져야 한다');
        $this->assertSame(
            ['menu-shop', 'other-domain', 'other-position', 'page-row'],
            $this->remainingTitles()
        );
    }

    /**
     * is_active=0 행이 살아남으면 블록 킷 적용 후 중복으로 남는다.
     */
    public function testGlobalDeleteIgnoresIsActiveFlag(): void
    {
        $this->seedRows();

        $this->repository->deleteByPosition(1, 'index', null);

        $this->assertNotContains('global-inactive', $this->remainingTitles());
    }

    /**
     * position_menu = '' 는 시더나 직접 INSERT 로 들어온다. 내보내기가 전역으로 보므로
     * 삭제도 전역으로 봐야 한다. 아니면 replace 할 때마다 중복이 쌓인다.
     */
    public function testGlobalDeleteCatchesEmptyStringMenu(): void
    {
        $this->seedRows();

        $this->repository->deleteByPosition(1, 'index', null);

        $this->assertNotContains('global-empty', $this->remainingTitles());
    }

    // ========================================
    // 메뉴 스코프 (menuCode 지정)
    // ========================================

    /**
     * menuCode 를 지정하면 그 메뉴 행만 지운다.
     * 프론트용 findByPosition() 처럼 전역 행까지 매칭하면 무관한 화면이 비워진다.
     */
    public function testMenuScopedDeleteDoesNotTouchGlobalRows(): void
    {
        $this->seedRows();

        $deleted = $this->repository->deleteByPosition(1, 'index', 'shop');

        $this->assertSame(1, $deleted);
        $this->assertSame(
            ['global-empty', 'global-inactive', 'global-null', 'other-domain', 'other-position', 'page-row'],
            $this->remainingTitles()
        );
    }

    public function testMenuScopedDeleteWithUnknownMenuRemovesNothing(): void
    {
        $this->seedRows();

        $this->assertSame(0, $this->repository->deleteByPosition(1, 'index', 'nope'));
    }

    // ========================================
    // 격리
    // ========================================

    public function testDeleteIsScopedToDomain(): void
    {
        $this->seedRows();

        $this->repository->deleteByPosition(1, 'index', null);

        $this->assertContains('other-domain', $this->remainingTitles());
    }

    public function testDeleteIsScopedToPosition(): void
    {
        $this->seedRows();

        $this->repository->deleteByPosition(1, 'index', null);

        $this->assertContains('other-position', $this->remainingTitles());
    }

    /**
     * 페이지 행(page_id NOT NULL)은 position 블록 킷의 대상이 아니다.
     */
    public function testDeleteNeverTouchesPageRows(): void
    {
        $this->seedRows();

        $this->repository->deleteByPosition(1, 'index', null);

        $this->assertContains('page-row', $this->remainingTitles());
    }

    // ========================================
    // findAllByPosition — 관리 목록의 위치·메뉴 필터
    // ========================================

    /**
     * 관리 목록의 메뉴 필터는 프론트 findByPosition() 과 같은 스코프여야 한다 —
     * "그 페이지에 실제로 뜨는 것"(전역 + 그 메뉴). 어긋나면 관리자가 목록에서
     * 본 것과 방문자가 화면에서 보는 것이 달라진다.
     */
    private function seedSubheadRows(): void
    {
        $this->seed('block_rows', [
            ['domain_id' => 1, 'position' => 'subhead', 'position_menu' => null,      'is_active' => 1, 'admin_title' => 'sub-global'],
            ['domain_id' => 1, 'position' => 'subhead', 'position_menu' => '',        'is_active' => 1, 'admin_title' => 'sub-empty-global'],
            ['domain_id' => 1, 'position' => 'subhead', 'position_menu' => 'notice',  'is_active' => 1, 'admin_title' => 'sub-notice'],
            ['domain_id' => 1, 'position' => 'subhead', 'position_menu' => 'gallery', 'is_active' => 0, 'admin_title' => 'sub-gallery-inactive'],

            // 다른 위치·도메인·페이지 행 — 절대 섞이면 안 된다
            ['domain_id' => 1, 'position' => 'index',   'position_menu' => null,      'is_active' => 1, 'admin_title' => 'idx-row'],
            ['domain_id' => 2, 'position' => 'subhead', 'position_menu' => 'notice',  'is_active' => 1, 'admin_title' => 'other-domain'],
            ['domain_id' => 1, 'position' => null,      'position_menu' => null,      'is_active' => 1, 'admin_title' => 'page-row', 'page_id' => 5],
        ]);
    }

    /** @return string[] */
    private function titlesOf(array $rows): array
    {
        $titles = array_map(fn ($r) => $r->getAdminTitle(), $rows);
        sort($titles);

        return $titles;
    }

    /** 메뉴 미지정이면 그 위치의 모든 행(전역 + 모든 메뉴). is_active 는 무관하다(관리자용). */
    public function testPositionWithoutMenuReturnsEveryRowAtThatPosition(): void
    {
        $this->seedSubheadRows();

        $this->assertSame(
            ['sub-empty-global', 'sub-gallery-inactive', 'sub-global', 'sub-notice'],
            $this->titlesOf($this->repository->findAllByPosition(1, 'subhead', null))
        );
    }

    /** 메뉴를 지정하면 전역 + 그 메뉴 행만. 다른 메뉴(gallery)는 빠진다. */
    public function testMenuFilterReturnsGlobalPlusThatMenu(): void
    {
        $this->seedSubheadRows();

        $this->assertSame(
            ['sub-empty-global', 'sub-global', 'sub-notice'],
            $this->titlesOf($this->repository->findAllByPosition(1, 'subhead', 'notice'))
        );
    }

    /**
     * position_menu = '' 는 시더·직접 INSERT 로 들어온 전역 행이다.
     * NULL 전역과 똑같이 모든 메뉴 필터에 포함돼야 한다.
     */
    public function testEmptyStringMenuCountsAsGlobal(): void
    {
        $this->seedSubheadRows();

        $titles = $this->titlesOf($this->repository->findAllByPosition(1, 'subhead', 'gallery'));

        $this->assertContains('sub-empty-global', $titles);
        $this->assertContains('sub-global', $titles);
        $this->assertContains('sub-gallery-inactive', $titles);
        $this->assertNotContains('sub-notice', $titles);
    }

    public function testMenuFilterIsScopedToDomain(): void
    {
        $this->seedSubheadRows();

        $this->assertNotContains(
            'other-domain',
            $this->titlesOf($this->repository->findAllByPosition(1, 'subhead', 'notice'))
        );
    }

    /** 위치 필터는 page_id 행을 절대 포함하지 않는다(그건 페이지 관리의 몫). */
    public function testPositionFilterNeverIncludesPageRows(): void
    {
        $this->seedSubheadRows();

        $all = $this->titlesOf($this->repository->findAllByPosition(1, null, null));

        $this->assertNotContains('page-row', $all);
    }
}
