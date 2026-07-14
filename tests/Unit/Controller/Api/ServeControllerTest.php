<?php

namespace Tests\Unit\Controller\Api;

use Mublo\Controller\Api\ServeController;
use Mublo\Core\Context\Context;
use Mublo\Entity\Domain\Domain;
use PHPUnit\Framework\TestCase;

class ServeControllerTest extends TestCase
{
    private ServeController $controller;
    private \ReflectionMethod $isExtensionAssetAllowed;
    private \ReflectionMethod $isAssetSubtreePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->controller = new ServeController();
        $this->isExtensionAssetAllowed = new \ReflectionMethod($this->controller, 'isExtensionAssetAllowed');
        $this->isExtensionAssetAllowed->setAccessible(true);
        $this->isAssetSubtreePath = new \ReflectionMethod($this->controller, 'isAssetSubtreePath');
        $this->isAssetSubtreePath->setAccessible(true);
    }

    public function testPluginAssetRequiresDomainContext(): void
    {
        $context = $this->createMock(Context::class);
        $context->method('getDomainInfo')->willReturn(null);

        $this->assertFalse(
            $this->isExtensionAssetAllowed->invoke($this->controller, 'Banner', 'plugin', $context)
        );
    }

    public function testPluginAssetAllowsEnabledPluginWithNormalizedName(): void
    {
        $context = $this->contextWithExtensions(
            plugins: ['member-point'],
            packages: []
        );

        $this->assertTrue(
            $this->isExtensionAssetAllowed->invoke($this->controller, 'MemberPoint', 'plugin', $context)
        );
    }

    public function testPackageAssetAllowsEnabledPackageWithNormalizedName(): void
    {
        $context = $this->contextWithExtensions(
            plugins: [],
            packages: ['shop']
        );

        $this->assertTrue(
            $this->isExtensionAssetAllowed->invoke($this->controller, 'Shop', 'package', $context)
        );
    }

    public function testPackageAssetRejectsDisabledPackage(): void
    {
        $context = $this->contextWithExtensions(
            plugins: [],
            packages: ['Board']
        );

        $this->assertFalse(
            $this->isExtensionAssetAllowed->invoke($this->controller, 'Shop', 'package', $context)
        );
    }

    public function testNestedPluginAssetRequiresParentPackageInSameDomain(): void
    {
        $withoutParent = $this->contextWithExtensions(
            plugins: ['Board/BoardReport'],
            packages: []
        );
        $withParent = $this->contextWithExtensions(
            plugins: ['Board/BoardReport'],
            packages: ['Board']
        );

        $this->assertFalse(
            $this->isExtensionAssetAllowed->invoke(
                $this->controller,
                'Board/BoardReport',
                'plugin',
                $withoutParent
            )
        );
        $this->assertTrue(
            $this->isExtensionAssetAllowed->invoke(
                $this->controller,
                'Board/BoardReport',
                'plugin',
                $withParent
            )
        );
    }

    public function testLegacyViewAssetRequiresAssetsSubtree(): void
    {
        $this->assertTrue($this->isAssetSubtreePath->invoke($this->controller, '_assets/css/admin.css'));
        $this->assertTrue($this->isAssetSubtreePath->invoke($this->controller, 'frame/basic/_assets/css/admin.css'));
        $this->assertFalse($this->isAssetSubtreePath->invoke($this->controller, 'Dashboard/Index.php'));
        $this->assertFalse($this->isAssetSubtreePath->invoke($this->controller, 'frame/basic/template.css'));
    }

    private function contextWithExtensions(array $plugins, array $packages): Context
    {
        $domain = new Domain(
            domainId: 1,
            domain: 'example.com',
            extensionConfig: [
                'plugins' => $plugins,
                'packages' => $packages,
            ],
        );

        $context = $this->createMock(Context::class);
        $context->method('getDomainInfo')->willReturn($domain);

        return $context;
    }
}
