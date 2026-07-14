<?php

namespace Tests\Unit\Service\Block;

use Mublo\Core\Block\BlockRegistry;
use Mublo\Entity\Block\BlockColumn;
use Mublo\Entity\Block\BlockPage;
use Mublo\Entity\Block\BlockRow;
use Mublo\Repository\Block\BlockColumnRepository;
use Mublo\Repository\Block\BlockRowRepository;
use Mublo\Service\Block\BlockContentSanitizer;
use Mublo\Service\Block\BlockKitExporter;
use Mublo\Service\Block\MainScreenComposition;
use PHPUnit\Framework\TestCase;

class BlockKitExporterTest extends TestCase
{
    /** 레지스트리는 정적이다. 테스트가 등록한 가짜 타입이 다른 테스트로 새지 않게 되돌린다. */
    protected function tearDown(): void
    {
        BlockRegistry::reset();

        parent::tearDown();
    }

    public function testExportRowsBuildsPortableKitAndOmitsDatabaseIdentifiers(): void
    {
        $row = BlockRow::fromArray([
            'row_id' => 10,
            'domain_id' => 1,
            'position' => 'index',
            'admin_title' => 'Hero',
            'sort_order' => 3,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);
        $column = BlockColumn::fromArray([
            'column_id' => 99,
            'row_id' => 10,
            'domain_id' => 1,
            'column_index' => 0,
            'content_type' => 'html',
            'content_kind' => 'CORE',
            'content_config' => [
                'html' => '<p>Hello</p>',
                'js' => 'console.log("x");',
            ],
            'content_items' => [['id' => 1]],
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);

        $rowRepository = $this->createMock(BlockRowRepository::class);
        $columnRepository = $this->createMock(BlockColumnRepository::class);
        $columnRepository->method('findAllByRowForDomain')->with(10, 1)->willReturn([$column]);

        $exporter = new BlockKitExporter($rowRepository, $columnRepository, new BlockContentSanitizer(), new MainScreenComposition());
        $kit = $exporter->exportRows(
            [$row],
            ['kind' => 'position', 'position' => 'index'],
            ['name' => 'Main hero']
        );

        $this->assertSame('mublo-starter-kit', $kit['format']);
        $this->assertSame('1.0', $kit['format_version']);
        $this->assertSame('distribution', $kit['export_mode']);
        $this->assertSame('Main hero', $kit['name']);
        $this->assertTrue($kit['contains_script']);
        $this->assertSame([['type' => 'html', 'kind' => 'CORE']], $kit['requires']['block_types']);
        $this->assertArrayNotHasKey('row_id', $kit['rows'][0]);
        $this->assertArrayNotHasKey('domain_id', $kit['rows'][0]);
        $this->assertArrayNotHasKey('column_id', $kit['rows'][0]['columns'][0]);
        $this->assertArrayNotHasKey('row_id', $kit['rows'][0]['columns'][0]);
        $this->assertNull($kit['rows'][0]['columns'][0]['content_items']);
    }

    public function testExportMainScreenPreservesSlotsAndCarriesOnlyLayoutSettings(): void
    {
        $globalIndex = BlockRow::fromArray([
            'row_id' => 10,
            'domain_id' => 1,
            'position' => 'index',
            'position_menu' => null,
            'admin_title' => 'Main',
        ]);
        $globalRight = BlockRow::fromArray([
            'row_id' => 11,
            'domain_id' => 1,
            'position' => 'right',
            'position_menu' => null,
            'admin_title' => 'Sidebar',
        ]);
        $menuRight = BlockRow::fromArray([
            'row_id' => 12,
            'domain_id' => 1,
            'position' => 'right',
            'position_menu' => 'shop',
            'admin_title' => 'Shop only',
        ]);

        $rowRepository = $this->createMock(BlockRowRepository::class);
        $rowRepository->method('findAllByPosition')
            ->willReturnCallback(static fn (int $domainId, string $position): array => match ($position) {
                'index' => [$globalIndex],
                'right' => [$globalRight, $menuRight],
                default => [],
            });

        $columnRepository = $this->createMock(BlockColumnRepository::class);
        $columnRepository->method('findAllByRowForDomain')->willReturn([]);

        $siteConfig = [
            'site_title' => '운반하면 안 되는 값',
            'layout_type' => 'right-sidebar',
            'use_main_layout' => true,
            'layout_right_width' => 280,
            'sidebar_right_mobile' => true,
        ];

        $kit = (new BlockKitExporter($rowRepository, $columnRepository, new BlockContentSanitizer(), new MainScreenComposition()))
            ->exportMainScreen(1, $siteConfig, ['name' => '메인화면']);

        $this->assertSame('screen', $kit['target']['kind']);
        $this->assertSame('main', $kit['target']['screen']);
        $this->assertSame(
            ['topbar', 'subhead', 'contenthead', 'index', 'contentfoot', 'right', 'subfoot'],
            $kit['target']['slots']
        );
        $this->assertSame(['index', 'right'], array_column($kit['rows'], 'position'));
        $this->assertArrayNotHasKey('site_title', $kit['site_settings']['site_config']);
        $this->assertSame('right-sidebar', $kit['site_settings']['site_config']['layout_type']);
        $this->assertTrue($kit['site_settings']['site_config']['use_main_layout']);
    }

    /**
     * 가져오는 쪽에는 확장이 설치되어 있지 않아 "이 블록은 누가 제공했나"를 알 방법이 없다.
     * 내보내는 쪽만 알 수 있으므로 블록 킷에 적어 보내야 "Shop 패키지를 설치하세요"라고 말할 수 있다.
     */
    public function testRequiredBlockTypesCarryProviderForExtensionTypes(): void
    {
        BlockRegistry::registerContentType(
            'product_list',
            'PACKAGE',
            '상품 목록',
            'Mublo\Packages\Shop\Block\ProductListRenderer',
            null,
            ['allowOverwrite' => true, 'skipValidation' => true]
        );
        BlockRegistry::registerContentType(
            'banner_slide',
            'PLUGIN',
            '배너',
            'Mublo\Plugin\Banner\Block\BannerRenderer',
            null,
            ['allowOverwrite' => true, 'skipValidation' => true]
        );

        $kit = $this->exportColumnsOfTypes(['product_list', 'banner_slide', 'html']);
        $byType = array_column($kit['requires']['block_types'], null, 'type');

        $this->assertSame('Shop', $byType['product_list']['provider']);
        $this->assertSame('Banner', $byType['banner_slide']['provider']);

        // 코어 타입은 설치할 확장이 없다. provider 키 자체가 없어야 한다.
        $this->assertArrayNotHasKey('provider', $byType['html']);
    }

    /**
     * 네임스페이스 관례를 따르지 않는 렌더러는 확장 이름을 유추할 수 없다.
     * 이때는 조용히 생략한다 — 틀린 이름을 적어 보내는 것보다 낫다.
     */
    public function testProviderIsOmittedWhenRendererIgnoresNamespaceConvention(): void
    {
        BlockRegistry::registerContentType(
            'legacy_widget',
            'PACKAGE',
            '레거시',
            'Acme\Widget\Renderer',
            null,
            ['allowOverwrite' => true, 'skipValidation' => true]
        );

        $kit = $this->exportColumnsOfTypes(['legacy_widget']);

        $this->assertArrayNotHasKey('provider', $kit['requires']['block_types'][0]);
    }

    /** 등록되지 않은 타입(레지스트리에 없음)도 내보내기를 깨뜨리지 않는다. */
    public function testUnregisteredContentTypeExportsWithoutProvider(): void
    {
        $kit = $this->exportColumnsOfTypes(['never_registered']);

        $this->assertSame('never_registered', $kit['requires']['block_types'][0]['type']);
        $this->assertArrayNotHasKey('provider', $kit['requires']['block_types'][0]);
    }

    // =========================================================================
    // requires.core (설계 6.3)
    // =========================================================================

    /** 저작자가 적은 제약을 그대로 싣는다. */
    public function testAuthoredCoreConstraintIsRecorded(): void
    {
        $kit = $this->exportColumnsOfTypes(['html'], ['requires_core' => ' ^1.2 ']);

        $this->assertSame('^1.2', $kit['requires']['core']);
    }

    /**
     * 현재 코어 버전을 자동으로 박지 않는다. 그러면 블록 킷마다 불필요한 하한선이 생겨
     * 멀쩡히 붙을 블록 킷이 경고를 달고 다닌다.
     *
     * @param mixed $meta
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('absentConstraintMetaCases')]
    public function testAbsentCoreConstraintStaysNull(array $meta): void
    {
        $kit = $this->exportColumnsOfTypes(['html'], $meta);

        $this->assertNull($kit['requires']['core']);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function absentConstraintMetaCases(): array
    {
        return [
            'not provided' => [[]],
            'empty string' => [['requires_core' => '']],
            'whitespace' => [['requires_core' => '   ']],
            'not a string' => [['requires_core' => ['^1.0']]],
        ];
    }

    // =========================================================================
    // 도메인 종속 이미지 (설계 4.4) — 파일을 가리키면 콘텐츠, 값이면 구조
    // =========================================================================

    /**
     * `/storage/2026/01/hero.jpg` 는 만든 사이트에만 존재한다. 실어 보내면 반드시 깨진다.
     * 배경색·그라데이션·정렬처럼 값인 것은 구조이므로 남는다.
     */
    public function testDistributionKitStripsImagePathsButKeepsStyleValues(): void
    {
        $kit = $this->exportStyledRow();

        $rowBackground = $kit['rows'][0]['background_config'];
        $this->assertArrayNotHasKey('image', $rowBackground);
        $this->assertSame('#fff', $rowBackground['color']);
        $this->assertTrue($kit['rows'][0]['kit_needs_bg_image']);

        $column = $kit['rows'][0]['columns'][0];
        $this->assertArrayNotHasKey('image', $column['background_config']);
        $this->assertArrayNotHasKey('pc_image', $column['title_config']);
        $this->assertArrayNotHasKey('mo_image', $column['title_config']);

        // 값은 남는다 — 이게 블록 킷이 나르는 구조다.
        $this->assertSame('공지사항', $column['title_config']['text']);
        $this->assertSame('24px', $column['title_config']['size_pc']);
        $this->assertSame('cover', $column['background_config']['size']);

        $this->assertTrue($column['kit_needs_bg_image']);
        $this->assertTrue($column['kit_needs_title_image']);
    }

    /** clone 은 같은 설치로 되돌리는 백업이다. 경로가 그대로 유효하므로 남긴다(설계 7.2). */
    public function testCloneKitKeepsImagePaths(): void
    {
        $kit = $this->exportStyledRow(['export_mode' => 'clone']);

        $this->assertSame('/storage/2026/01/row-bg.jpg', $kit['rows'][0]['background_config']['image']);
        $this->assertSame('/storage/2026/01/title.png', $kit['rows'][0]['columns'][0]['title_config']['pc_image']);
        $this->assertArrayNotHasKey('kit_needs_bg_image', $kit['rows'][0]);
    }

    /** 이미지가 원래 없던 칸은 표시하지 않는다. 없는 걸 채우라고 하면 안 된다. */
    public function testColumnsWithoutImagesAreNotMarked(): void
    {
        $kit = $this->exportColumnsOfTypes(['html']);

        $this->assertArrayNotHasKey('kit_needs_bg_image', $kit['rows'][0]);
        $this->assertArrayNotHasKey('kit_needs_bg_image', $kit['rows'][0]['columns'][0]);
        $this->assertArrayNotHasKey('kit_needs_title_image', $kit['rows'][0]['columns'][0]);
    }

    /** @param array<string, mixed> $options */
    private function exportStyledRow(array $options = []): array
    {
        $row = BlockRow::fromArray([
            'row_id' => 1,
            'domain_id' => 1,
            'position' => 'index',
            'background_config' => ['color' => '#fff', 'image' => '/storage/2026/01/row-bg.jpg'],
        ]);
        $column = BlockColumn::fromArray([
            'column_id' => 5,
            'row_id' => 1,
            'column_index' => 0,
            'content_type' => 'html',
            'background_config' => ['image' => '/storage/2026/01/col-bg.jpg', 'size' => 'cover'],
            'title_config' => [
                'text' => '공지사항',
                'size_pc' => '24px',
                'pc_image' => '/storage/2026/01/title.png',
                'mo_image' => '/storage/2026/01/title-mo.png',
            ],
        ]);

        $columnRepository = $this->createMock(BlockColumnRepository::class);
        $columnRepository->method('findAllByRowForDomain')->willReturn([$column]);

        $exporter = new BlockKitExporter(
            $this->createMock(BlockRowRepository::class),
            $columnRepository,
            new BlockContentSanitizer(),
            new MainScreenComposition()
        );

        return $exporter->exportRows([$row], ['kind' => 'position', 'position' => 'index'], [], $options);
    }

    /**
     * @param string[] $contentTypes
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private function exportColumnsOfTypes(array $contentTypes, array $meta = []): array
    {
        $row = BlockRow::fromArray(['row_id' => 1, 'domain_id' => 1, 'position' => 'index']);

        $columns = [];
        foreach ($contentTypes as $index => $type) {
            $columns[] = BlockColumn::fromArray([
                'column_id' => 100 + $index,
                'row_id' => 1,
                'column_index' => $index,
                'content_type' => $type,
                'content_kind' => 'CORE',
            ]);
        }

        $columnRepository = $this->createMock(BlockColumnRepository::class);
        $columnRepository->method('findAllByRowForDomain')->willReturn($columns);

        $exporter = new BlockKitExporter(
            $this->createMock(BlockRowRepository::class),
            $columnRepository,
            new BlockContentSanitizer(),
            new MainScreenComposition()
        );

        return $exporter->exportRows([$row], ['kind' => 'position', 'position' => 'index'], $meta);
    }

    public function testExportRowsCanKeepContentItemsWhenExplicitlyRequested(): void
    {
        $row = BlockRow::fromArray(['row_id' => 10, 'domain_id' => 1, 'position' => 'index']);
        $column = BlockColumn::fromArray([
            'column_id' => 99,
            'row_id' => 10,
            'domain_id' => 1,
            'column_index' => 0,
            'content_type' => 'board',
            'content_kind' => 'PACKAGE',
            'content_items' => [['id' => 7]],
        ]);

        $rowRepository = $this->createMock(BlockRowRepository::class);
        $columnRepository = $this->createMock(BlockColumnRepository::class);
        $columnRepository->method('findAllByRowForDomain')->willReturn([$column]);

        $exporter = new BlockKitExporter($rowRepository, $columnRepository, new BlockContentSanitizer(), new MainScreenComposition());
        $kit = $exporter->exportRows(
            [$row],
            ['kind' => 'position', 'position' => 'index'],
            [],
            ['include_content_items_by_column' => [99]]
        );

        $this->assertSame([['id' => 7]], $kit['rows'][0]['columns'][0]['content_items']);
    }

    public function testEmptiedColumnsAreMarkedAsNeedingItems(): void
    {
        $row = BlockRow::fromArray(['row_id' => 10, 'domain_id' => 1, 'position' => 'index']);
        $withItems = BlockColumn::fromArray([
            'column_id' => 99,
            'row_id' => 10,
            'column_index' => 0,
            'content_type' => 'board',
            'content_kind' => 'PACKAGE',
            'content_items' => [['id' => 7]],
        ]);
        $withoutItems = BlockColumn::fromArray([
            'column_id' => 98,
            'row_id' => 10,
            'column_index' => 1,
            'content_type' => 'product_auto',
            'content_kind' => 'PACKAGE',
            'content_items' => [],
        ]);

        $rowRepository = $this->createMock(BlockRowRepository::class);
        $columnRepository = $this->createMock(BlockColumnRepository::class);
        $columnRepository->method('findAllByRowForDomain')->willReturn([$withItems, $withoutItems]);

        $exporter = new BlockKitExporter($rowRepository, $columnRepository, new BlockContentSanitizer(), new MainScreenComposition());
        $kit = $exporter->exportRows([$row], ['kind' => 'position', 'position' => 'index']);

        // 참조가 있었는데 비운 칸만 표시된다
        $this->assertTrue($kit['rows'][0]['columns'][0]['kit_needs_items']);

        // 애초에 참조를 쓰지 않는 칸(product_auto)은 표시되지 않는다 — 체크리스트 오탐 방지
        $this->assertArrayNotHasKey('kit_needs_items', $kit['rows'][0]['columns'][1]);
    }

    public function testUncheckedColumnsAreEmptiedByDefault(): void
    {
        $row = BlockRow::fromArray(['row_id' => 10, 'domain_id' => 1, 'position' => 'index']);
        $column = BlockColumn::fromArray([
            'column_id' => 99,
            'row_id' => 10,
            'domain_id' => 1,
            'column_index' => 0,
            'content_type' => 'board',
            'content_kind' => 'PACKAGE',
            'content_items' => [['id' => 7]],
        ]);

        $rowRepository = $this->createMock(BlockRowRepository::class);
        $columnRepository = $this->createMock(BlockColumnRepository::class);
        $columnRepository->method('findAllByRowForDomain')->willReturn([$column]);

        $exporter = new BlockKitExporter($rowRepository, $columnRepository, new BlockContentSanitizer(), new MainScreenComposition());

        // 다른 칸(88)만 체크했다 — 99는 비워져야 한다
        $kit = $exporter->exportRows(
            [$row],
            ['kind' => 'position', 'position' => 'index'],
            [],
            ['include_content_items_by_column' => [88]]
        );

        $this->assertNull($kit['rows'][0]['columns'][0]['content_items'], '기본 정책은 비움이다');
    }

    public function testExportRowsAlwaysDropsBannerContentItems(): void
    {
        $row = BlockRow::fromArray(['row_id' => 10, 'domain_id' => 1, 'position' => 'index']);
        $column = BlockColumn::fromArray([
            'column_id' => 99,
            'row_id' => 10,
            'domain_id' => 1,
            'column_index' => 0,
            'content_type' => 'banner',
            'content_kind' => 'PLUGIN',
            'content_items' => [['id' => 7, 'pc_image_url' => '/storage/D1/banner/a.jpg']],
        ]);

        $rowRepository = $this->createMock(BlockRowRepository::class);
        $columnRepository = $this->createMock(BlockColumnRepository::class);
        $columnRepository->method('findAllByRowForDomain')->willReturn([$column]);

        $exporter = new BlockKitExporter($rowRepository, $columnRepository, new BlockContentSanitizer(), new MainScreenComposition());
        $kit = $exporter->exportRows(
            [$row],
            ['kind' => 'position', 'position' => 'index'],
            [],
            ['export_mode' => 'clone', 'include_content_items_by_column' => [99]]
        );

        $this->assertNull($kit['rows'][0]['columns'][0]['content_items']);
    }

    public function testCloneModeKeepsContentItemsWithoutPerColumnChecks(): void
    {
        $row = BlockRow::fromArray(['row_id' => 10, 'domain_id' => 1, 'position' => 'index']);
        $column = BlockColumn::fromArray([
            'column_id' => 99,
            'row_id' => 10,
            'domain_id' => 1,
            'column_index' => 0,
            'content_type' => 'board',
            'content_kind' => 'PACKAGE',
            'content_items' => [['id' => 7]],
        ]);

        $rowRepository = $this->createMock(BlockRowRepository::class);
        $columnRepository = $this->createMock(BlockColumnRepository::class);
        $columnRepository->method('findAllByRowForDomain')->willReturn([$column]);

        $exporter = new BlockKitExporter($rowRepository, $columnRepository, new BlockContentSanitizer(), new MainScreenComposition());
        $kit = $exporter->exportRows(
            [$row],
            ['kind' => 'position', 'position' => 'index'],
            [],
            ['export_mode' => 'clone']
        );

        $this->assertSame([['id' => 7]], $kit['rows'][0]['columns'][0]['content_items']);
    }

    public function testCloneExportCarriesSourceInstallField(): void
    {
        $rowRepository = $this->createMock(BlockRowRepository::class);
        $columnRepository = $this->createMock(BlockColumnRepository::class);

        $exporter = new BlockKitExporter($rowRepository, $columnRepository, new BlockContentSanitizer(), new MainScreenComposition());
        $kit = $exporter->exportRows(
            [],
            ['kind' => 'position', 'position' => 'index'],
            [],
            ['export_mode' => 'clone', 'source_install' => 'hash-value']
        );

        $this->assertSame('clone', $kit['export_mode']);
        $this->assertSame('hash-value', $kit['source_install']);
    }

    public function testDistributionExportOmitsSourceInstall(): void
    {
        $exporter = new BlockKitExporter(
            $this->createMock(BlockRowRepository::class),
            $this->createMock(BlockColumnRepository::class),
            new BlockContentSanitizer(),
            new MainScreenComposition()
        );

        $kit = $exporter->exportRows([], ['kind' => 'position', 'position' => 'index']);

        $this->assertArrayNotHasKey('source_install', $kit, '배포 파일에 설치 정보를 남기지 않는다');
    }

    public function testExportPositionScopesRowsByMenuCodeAndRecordsItInTarget(): void
    {
        $globalRow = BlockRow::fromArray(['row_id' => 1, 'domain_id' => 1, 'position' => 'top']);
        $shopRow = BlockRow::fromArray([
            'row_id' => 2,
            'domain_id' => 1,
            'position' => 'top',
            'position_menu' => 'shop',
        ]);

        $rowRepository = $this->createMock(BlockRowRepository::class);
        $rowRepository->method('findAllByPosition')->willReturn([$globalRow, $shopRow]);
        $columnRepository = $this->createMock(BlockColumnRepository::class);
        $columnRepository->method('findAllByRowForDomain')->willReturn([]);

        $exporter = new BlockKitExporter($rowRepository, $columnRepository, new BlockContentSanitizer(), new MainScreenComposition());

        $shopKit = $exporter->exportPosition(1, 'top', 'shop');
        $this->assertSame('shop', $shopKit['target']['menu_code']);
        $this->assertCount(1, $shopKit['rows']);

        $globalKit = $exporter->exportPosition(1, 'top');
        $this->assertArrayNotHasKey('menu_code', $globalKit['target']);
        $this->assertCount(1, $globalKit['rows'], 'menu 스코프 행은 전역 블록 킷에 섞이지 않는다');
    }

    public function testExportPageCarriesSelfContainedPageObject(): void
    {
        $page = BlockPage::fromArray([
            'page_id' => 5,
            'domain_id' => 1,
            'page_code' => 'about',
            'page_title' => '회사소개',
            'layout_type' => 3,
        ]);

        $rowRepository = $this->createMock(BlockRowRepository::class);
        $rowRepository->method('findAllByPage')->with(5)->willReturn([]);
        $columnRepository = $this->createMock(BlockColumnRepository::class);

        $exporter = new BlockKitExporter($rowRepository, $columnRepository, new BlockContentSanitizer(), new MainScreenComposition());
        $kit = $exporter->exportPage($page);

        $this->assertSame('page', $kit['target']['kind']);
        $this->assertSame('about', $kit['target']['page_code']);
        $this->assertSame(3, $kit['page']['layout_type']);
        $this->assertArrayNotHasKey('page_id', $kit['page']);
        $this->assertArrayNotHasKey('domain_id', $kit['page']);
    }
}
