<?php
declare(strict_types=1);

namespace Tests\Unit\Service\Domain;

use Mublo\Contract\Site\ThemeReadSnapshot;
use Mublo\Repository\Domain\DomainRepository;
use Mublo\Service\Domain\DomainResolver;
use Mublo\Service\Domain\DomainSettingsService;
use Mublo\Service\Site\ThemeReadOverlay;
use PHPUnit\Framework\TestCase;

final class DomainSettingsReadOverlayTest extends TestCase
{
    public function testAllPreviewBucketsBypassDomainRepository(): void
    {
        $repository = $this->createMock(DomainRepository::class);
        $repository->expects($this->never())->method('find');
        $overlay = new ThemeReadOverlay();
        $result = $overlay->replace(new ThemeReadSnapshot(
            7,
            ['site_title' => '미리보기'],
            ['name' => '회사'],
            ['meta_title' => 'SEO'],
            ['frame' => 'basic']
        ));
        $this->assertTrue($result->isSuccess(), $result->getMessage());
        $service = new DomainSettingsService(
            $repository,
            $this->createMock(DomainResolver::class),
            themeReadOverlay: $overlay
        );

        $this->assertSame('미리보기', $service->getSiteConfig(7)['site_title']);
        $this->assertSame('회사', $service->getCompanyConfig(7)['name']);
        $this->assertSame('SEO', $service->getSeoConfig(7)['meta_title']);
        $this->assertSame('basic', $service->getThemeConfig(7)['frame']);
    }
}
