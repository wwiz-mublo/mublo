<?php

namespace Tests\Unit\Service\Extension;

use Mublo\Service\Extension\ExtensionCatalog;
use Mublo\Service\Extension\ExtensionService;
use PHPUnit\Framework\TestCase;

final class ExtensionCatalogTest extends TestCase
{
    public function testReturnsOnlyDiscoveredExtensionNames(): void
    {
        $extensions = $this->createMock(ExtensionService::class);
        $extensions->method('getPluginManifests')->willReturn([
            'Banner' => ['version' => '1.0.0'],
            'Faq' => ['version' => '2.0.0'],
        ]);
        $extensions->method('getPackageManifests')->willReturn([
            'Board' => ['version' => '3.0.0'],
        ]);

        $catalog = new ExtensionCatalog($extensions);

        self::assertSame(['Banner', 'Faq'], $catalog->pluginNames());
        self::assertSame(['Board'], $catalog->packageNames());
    }
}
