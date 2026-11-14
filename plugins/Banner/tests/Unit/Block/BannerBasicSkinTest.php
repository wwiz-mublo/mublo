<?php

namespace Tests\Banner\Unit\Block;

use Mublo\Contract\Block\BlockColumnView;
use PHPUnit\Framework\TestCase;

/**
 * banner/basic 스킨의 좌우 화살표·점 인디케이터 출력
 *
 * - show_navigation / show_pagination 이 켜져 있고 배너가 2장 이상일 때만 출력한다
 * - 값이 없는(예전에 저장된) 블록은 예전처럼 출력하지 않는다
 * - Swiper 연결은 data-swiper 의 navigation/pagination 으로 한다
 */
class BannerBasicSkinTest extends TestCase
{
    private string $titlePartial;

    protected function setUp(): void
    {
        $this->titlePartial = tempnam(sys_get_temp_dir(), 'banner-title-');
        file_put_contents($this->titlePartial, '');
    }

    protected function tearDown(): void
    {
        @unlink($this->titlePartial);
    }

    public function testControlsRenderedWhenEnabledWithMultipleBanners(): void
    {
        $html = $this->render(['show_navigation' => '1', 'show_pagination' => '1'], 3);

        $this->assertStringContainsString('id="block-banner-col_7-prev"', $html);
        $this->assertStringContainsString('id="block-banner-col_7-next"', $html);
        $this->assertStringContainsString('id="block-banner-col_7-pagination"', $html);
        $this->assertStringContainsString('aria-label="이전 배너"', $html);

        $options = $this->swiperOptions($html);
        $this->assertSame('#block-banner-col_7-prev', $options['navigation']['prevEl']);
        $this->assertSame('#block-banner-col_7-next', $options['navigation']['nextEl']);
        $this->assertSame('#block-banner-col_7-pagination', $options['pagination']['el']);
        $this->assertTrue($options['pagination']['clickable']);
    }

    public function testOnlyNavigationWhenPaginationOff(): void
    {
        $html = $this->render(['show_navigation' => '1', 'show_pagination' => '0'], 2);

        $this->assertStringContainsString('block-banner__nav--prev', $html);
        $this->assertStringNotContainsString('block-banner__pagination', $html);
        $this->assertArrayNotHasKey('pagination', $this->swiperOptions($html));
    }

    public function testLegacyBlockWithoutValuesKeepsOldOutput(): void
    {
        $html = $this->render([], 9);

        $this->assertStringNotContainsString('block-banner__nav', $html);
        $this->assertStringNotContainsString('block-banner__pagination', $html);
        $this->assertStringNotContainsString('data-swiper', $html);
    }

    public function testSingleBannerHasNoControls(): void
    {
        $html = $this->render(['show_navigation' => '1', 'show_pagination' => '1'], 1);

        $this->assertStringNotContainsString('block-banner__nav', $html);
        $this->assertStringNotContainsString('data-swiper', $html);
    }

    private function render(array $contentConfig, int $count): string
    {
        $column = $this->createMock(BlockColumnView::class);
        $column->method('getLayoutDataAttributes')
            ->willReturn('data-pc-style="slide" data-mo-style="slide" data-pc-cols="1" data-mo-cols="1"');
        $column->method('getRenderKey')->willReturn('col_7');

        $items = [];
        for ($i = 1; $i <= $count; $i++) {
            $items[] = ['banner_id' => $i, 'title' => '배너 ' . $i, 'pc_image_url' => '/b' . $i . '.jpg'];
        }

        $titlePartial = $this->titlePartial;
        $assets = null;

        ob_start();
        (static function () use ($column, $items, $contentConfig, $titlePartial, $assets): void {
            include dirname(__DIR__, 3) . '/views/Block/banner/basic/basic.php';
        })();

        return (string) ob_get_clean();
    }

    private function swiperOptions(string $html): array
    {
        $this->assertSame(1, preg_match('/data-swiper="([^"]*)"/', $html, $m));
        $decoded = json_decode(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
