<?php

namespace Tests\Banner\Unit\Block;

use Mublo\Plugin\Banner\Block\BannerRenderer;
use Mublo\Plugin\Banner\Service\BannerService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class BannerRendererTest extends TestCase
{
    public function testAdminSelectorStoresCanonicalIdArray(): void
    {
        $script = file_get_contents(
            dirname(__DIR__, 3) . '/assets/js/block-banner.js'
        );

        $this->assertIsString($script);
        $this->assertStringContainsString('return dualListbox.getSelected();', $script);
        $this->assertStringNotContainsString('return item || { id };', $script);
    }

    public function testLegacyObjectItemsAreReloadedFromCurrentDomainDatabaseValues(): void
    {
        $service = $this->createMock(BannerService::class);
        $service->expects($this->once())
            ->method('findByIds')
            ->with(10, [2, 999, 1])
            ->willReturn([
                ['banner_id' => 2, 'title' => 'Current 2', 'pc_image_url' => '/current-2.jpg'],
                ['banner_id' => 1, 'title' => 'Current 1', 'pc_image_url' => '/current-1.jpg'],
            ]);

        $renderer = new BannerRenderer($service);
        $method = new ReflectionMethod($renderer, 'resolveItems');
        $items = $method->invoke($renderer, [
            ['id' => 2, 'label' => 'Stale 2', 'pc_image_url' => '/stale-2.jpg'],
            ['id' => 999, 'label' => 'Foreign', 'pc_image_url' => '/foreign.jpg'],
            ['id' => 1, 'label' => 'Stale 1', 'pc_image_url' => '/stale-1.jpg'],
        ], 10);

        $this->assertSame([2, 1], array_column($items, 'banner_id'));
        $this->assertSame(['Current 2', 'Current 1'], array_column($items, 'title'));
        $this->assertSame(['/current-2.jpg', '/current-1.jpg'], array_column($items, 'pc_image_url'));
    }
}
