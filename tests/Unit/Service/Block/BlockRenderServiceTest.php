<?php

namespace Tests\Unit\Service\Block;

use Mublo\Core\Container\DependencyContainer;
use Mublo\Entity\Block\BlockColumn;
use Mublo\Entity\Block\BlockRow;
use Mublo\Infrastructure\Cache\CacheInterface;
use Mublo\Repository\Block\BlockColumnRepository;
use Mublo\Repository\Block\BlockRowRepository;
use Mublo\Repository\Menu\MenuItemRepository;
use Mublo\Service\Block\BlockRenderService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class BlockRenderServiceTest extends TestCase
{
    public function testImageColumnWithBorderRadiusClipsOverflow(): void
    {
        $service = $this->makeService();

        $row = BlockRow::fromArray([
            'row_id' => 1,
            'domain_id' => 1,
            'width_type' => 1,
            'column_count' => 1,
            'column_margin' => 0,
        ]);

        $column = BlockColumn::fromArray([
            'column_id' => 1,
            'row_id' => 1,
            'domain_id' => 1,
            'column_index' => 0,
            'content_type' => 'image',
            'border_config' => json_encode([
                'width' => '1px',
                'color' => '#dddddd',
                'radius' => '12px',
            ]),
        ]);

        $style = $this->invoke($service, 'buildColumnStyle', [$column, $row, 1]);

        $this->assertStringContainsString('border: 1px solid #dddddd', $style);
        $this->assertStringContainsString('border-radius: 12px', $style);
        $this->assertStringContainsString('overflow: hidden', $style);
    }

    // ========================================
    // 빈 칸 backstop 플레이스홀더
    //
    // 렌더러가 ''를 반환해도 칸 영역을 유지한다. 블록 킷이 잡아준 구조가
    // 무너지지 않고, 렌더러 미설치 칸도 같은 경로로 처리된다.
    // ========================================

    public function testPublicEmptyColumnKeepsSlotWithoutLeakingEditorPlaceholder(): void
    {
        $service = $this->makeService();

        $html = $this->invoke($service, 'buildColumnHtml', [
            $this->makeEmptyColumn(),
            $this->makeRow(),
            1,
        ]);

        $this->assertStringNotContainsString('block-placeholder', $html);
        $this->assertStringContainsString('block-column', $html, '블록 킷이 잡아준 공간이 유지되어야 한다');
        $this->assertStringContainsString('block-column--empty', $html, '빈 칸은 1px로 접히지 않아야 한다');
        $this->assertNotSame('', $html, '빈 콘텐츠라도 칸이 사라지면 안 된다');
    }

    public function testPublicTypePlaceholderIsBlank(): void
    {
        $service = $this->makeService();

        $html = $this->invoke($service, 'renderTypePlaceholder', []);

        $this->assertSame('', $html);
    }

    public function testPublicEmptyContentPlaceholderIsBlank(): void
    {
        $service = $this->makeService();

        $html = $this->invoke($service, 'renderEmptyPlaceholder', []);

        $this->assertSame('', $html);
    }

    public function testEntityPreviewShowsEditorPlaceholder(): void
    {
        $service = $this->makeService();

        $html = $service->renderRowFromEntities($this->makeRow(), [$this->makeEmptyColumn()]);

        $this->assertStringContainsString('block-placeholder', $html);
        $this->assertStringContainsString('콘텐츠 타입을 선택하세요', $html);
    }

    /**
     * 플레이스홀더는 방문자·관리자 구분이 없으므로 캐시해도 안전하다.
     * (관리자 전용 렌더가 캐시에 섞이는 오염 경로가 존재하지 않는다)
     */
    public function testRowRenderUsesCacheWhenAvailable(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->once())->method('get')->willReturn(['h' => '<section>cached</section>']);

        $service = new BlockRenderService(
            $this->createMock(BlockRowRepository::class),
            $this->createMock(BlockColumnRepository::class),
            $cache,
            $this->createMock(DependencyContainer::class)
        );

        $this->assertSame('<section>cached</section>', $service->renderRow($this->makeRow(), true));
    }

    public function testColumnStyleAttributeIsEscapedAgainstBreakout(): void
    {
        $service = $this->makeService();

        $row = BlockRow::fromArray([
            'row_id' => 1,
            'domain_id' => 1,
            'width_type' => 1,
            'column_count' => 1,
            'column_margin' => 0,
        ]);

        // border color 에 심은 따옴표로 style 속성을 탈출해 onmouseover 를 주입하려는 시도.
        // 저장단 검증을 우회해 DB 에 남은 경우라도 출력단에서 이스케이프되어야 한다.
        $column = BlockColumn::fromArray([
            'column_id' => 1,
            'row_id' => 1,
            'domain_id' => 1,
            'column_index' => 0,
            'border_config' => json_encode([
                'width' => '1px',
                'style' => 'solid',
                'color' => '#fff" onmouseover="alert(1)',
            ]),
        ]);

        $html = $this->invoke($service, 'buildColumnHtml', [$column, $row, 1]);

        // 속성 탈출(진짜 따옴표로 닫히는 온이벤트)이 그대로 렌더되면 안 된다
        $this->assertStringNotContainsString('onmouseover="alert(1)"', $html);
        // 위험 문자는 엔티티로 이스케이프되어 style 값 안에 갇혀야 한다
        $this->assertStringContainsString('&quot;', $html);
    }

    /**
     * 전역 행(position_menu=NULL) 변경 시, 블록 행이 하나도 없는 메뉴의 목록 캐시도 지워야 한다.
     *
     * 목록 캐시는 결과가 빈 배열이어도 기록되므로(getRowsForPosition), 방문만 된 메뉴에도
     * :{menuCode} 키가 생긴다. 열거 소스가 block_rows 뿐이면 그런 메뉴가 누락되어
     * 전역 행을 추가·수정해도 그 메뉴에서만 TTL 만료 전까지 블록이 안 보인다.
     */
    public function testGlobalRowInvalidationClearsMenusWithoutOwnRows(): void
    {
        $rowRepository = $this->createMock(BlockRowRepository::class);
        // 블록 행이 배정된 메뉴는 about 뿐
        $rowRepository->method('getDistinctMenuCodes')->willReturn(['about']);

        // 메뉴 테이블에는 download 도 있다 — 자기 블록 행은 없지만 방문되어 빈 캐시가 남을 수 있다
        $menuRepository = $this->createMock(MenuItemRepository::class);
        $menuRepository->method('findMenuCodesByDomain')->willReturn(['about', 'download']);

        $container = $this->createMock(DependencyContainer::class);
        $container->method('canResolve')->with(MenuItemRepository::class)->willReturn(true);
        $container->method('get')->with(MenuItemRepository::class)->willReturn($menuRepository);

        $deleted = [];
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('delete')->willReturnCallback(function (string $key) use (&$deleted): bool {
            $deleted[] = $key;
            return true;
        });

        $service = new BlockRenderService(
            $rowRepository,
            $this->createMock(BlockColumnRepository::class),
            $cache,
            $container
        );

        $service->invalidateRowRelatedCache(BlockRow::fromArray([
            'row_id' => 47,
            'domain_id' => 1,
            'position' => 'right',
            'position_menu' => null,
            'width_type' => 1,
            'column_count' => 1,
        ]));

        $this->assertContains('block:ids:pos:1:right:download', $deleted);
        $this->assertContains('block:ids:pos:1:right:about', $deleted);
        $this->assertContains('block:ids:pos:1:right', $deleted);
    }

    public function testInvalidateRowCacheDeletesEveryRecordedContextVariant(): void
    {
        $deleted = [];
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')
            ->with('block:row-variants:47')
            ->willReturn(['brand-a', 'brand-b']);
        $cache->method('delete')->willReturnCallback(function (string $key) use (&$deleted): bool {
            $deleted[] = $key;
            return true;
        });

        $service = new BlockRenderService(
            $this->createMock(BlockRowRepository::class),
            $this->createMock(BlockColumnRepository::class),
            $cache,
            $this->createMock(DependencyContainer::class)
        );

        $service->invalidateRowCache(47);

        $this->assertSame([
            'block:row:47',
            'block:row:47:brand-a',
            'block:row:47:brand-b',
            'block:row-variants:47',
        ], $deleted);
    }

    // ========================================
    // Helpers
    // ========================================

    private function makeService(): BlockRenderService
    {
        return new BlockRenderService(
            $this->createMock(BlockRowRepository::class),
            $this->createMock(BlockColumnRepository::class),
            $this->createMock(CacheInterface::class),
            $this->createMock(DependencyContainer::class)
        );
    }

    private function makeRow(): BlockRow
    {
        return BlockRow::fromArray([
            'row_id' => 1,
            'domain_id' => 1,
            'width_type' => 1,
            'column_count' => 1,
            'column_margin' => 0,
            'is_active' => 1,
        ]);
    }

    /**
     * content_type이 없어 렌더 결과가 빈 문자열이 되는 칸
     */
    private function makeEmptyColumn(): BlockColumn
    {
        return BlockColumn::fromArray([
            'column_id' => 1,
            'row_id' => 1,
            'domain_id' => 1,
            'column_index' => 0,
            'content_type' => null,
            'is_active' => 1,
        ]);
    }

    private function invoke(BlockRenderService $service, string $method, array $args): mixed
    {
        $reflection = (new ReflectionClass($service))->getMethod($method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($service, $args);
    }
}
