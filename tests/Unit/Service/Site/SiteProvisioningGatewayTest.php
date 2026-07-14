<?php

namespace Tests\Unit\Service\Site;

use Mublo\Contract\Site\SiteProvisioningInterface;
use Mublo\Core\Result\Result;
use Mublo\Service\Block\BlockKitApplier;
use Mublo\Service\Domain\DomainSettingsService;
use Mublo\Service\Menu\MenuService;
use Mublo\Service\Site\SiteProvisioningGateway;
use PHPUnit\Framework\TestCase;

/**
 * SiteProvisioningGateway 는 대부분 순수 위임이다. 검증할 것은 자체 로직 둘뿐이다.
 *
 * 1. applyScreen 의 가드 — target.kind 검사, allowScripts=false 고정
 * 2. setPackageFrameOverride 의 병합 — 얕은 병합이 다른 패키지 오버라이드를
 *    덮지 않도록 기존 theme_config 를 읽어 FrameOverride 규약으로 합친다
 */
class SiteProvisioningGatewayTest extends TestCase
{
    private function gateway(?BlockKitApplier $applier = null): SiteProvisioningGateway
    {
        return new SiteProvisioningGateway(
            $this->createMock(MenuService::class),
            $this->createMock(DomainSettingsService::class),
            $applier ?? $this->createMock(BlockKitApplier::class)
        );
    }

    public function testApplyScreenRejectsNonScreenKit(): void
    {
        $applier = $this->createMock(BlockKitApplier::class);
        $applier->expects($this->never())->method('apply');

        $result = $this->gateway($applier)->applyScreen(1, ['target' => ['kind' => 'page']]);

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('screen', $result->getMessage());
    }

    public function testApplyScreenRejectsKitWithoutTarget(): void
    {
        $result = $this->gateway()->applyScreen(1, ['rows' => []]);

        $this->assertFalse($result->isSuccess());
    }

    public function testApplyScreenRejectsUnknownMode(): void
    {
        $applier = $this->createMock(BlockKitApplier::class);
        $applier->expects($this->never())->method('apply');

        $result = $this->gateway($applier)->applyScreen(1, ['target' => ['kind' => 'screen']], 'merge');

        $this->assertFalse($result->isSuccess());
    }

    /**
     * 확장 경로에는 "이 사람이 최고관리자인가"를 물을 문맥이 없다.
     * 그래서 raw JS 를 허용하지 않는 쪽으로 고정한다 — 코어 기본값은 true 이므로
     * 명시적으로 false 를 넘겨야 한다.
     */
    public function testApplyScreenForcesAllowScriptsFalse(): void
    {
        $applier = $this->createMock(BlockKitApplier::class);
        $applier->expects($this->once())
            ->method('apply')
            ->with(
                7,
                $this->anything(),
                SiteProvisioningInterface::MODE_REPLACE,
                false,   // applySiteSettings — 코어가 kind=screen 이면 강제로 켠다
                false    // allowScripts
            )
            ->willReturn(['ok' => true, 'summary' => ['x' => 1], 'warnings' => []]);

        $result = $this->gateway($applier)->applyScreen(7, ['target' => ['kind' => 'screen']]);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(['x' => 1], $result->getData()['summary']);
    }

    public function testApplyScreenSurfacesApplierErrors(): void
    {
        $applier = $this->createMock(BlockKitApplier::class);
        $applier->method('apply')->willReturn([
            'ok' => false,
            'errors' => ['미등록 콘텐츠 타입입니다.'],
        ]);

        $result = $this->gateway($applier)->applyScreen(1, ['target' => ['kind' => 'screen']]);

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('미등록 콘텐츠 타입', $result->getMessage());
    }

    public function testDelegatesMenuAndConfigCallsUnchanged(): void
    {
        $menus = $this->createMock(MenuService::class);
        $menus->expects($this->once())
            ->method('createItem')
            ->with(3, ['label' => '회사소개'])
            ->willReturn(Result::success('ok', ['menu_code' => 'abc12345']));
        $menus->expects($this->once())
            ->method('addToTree')
            ->with(3, 'abc12345', 'parent01');

        $settings = $this->createMock(DomainSettingsService::class);
        $settings->expects($this->once())
            ->method('updateCompanyConfig')
            ->with(3, ['name' => '연결온']);

        $gateway = new SiteProvisioningGateway($menus, $settings, $this->createMock(BlockKitApplier::class));

        $created = $gateway->createMenuItem(3, ['label' => '회사소개']);
        $this->assertSame('abc12345', $created->getData()['menu_code']);

        $gateway->placeMenuItem(3, 'abc12345', 'parent01');
        $gateway->updateCompanyConfig(3, ['name' => '연결온']);
    }

    public function testDelegatesEnsureMethods(): void
    {
        $menus = $this->createMock(MenuService::class);
        $menus->expects($this->once())->method('ensureItem')->with(3, 'sitekit:group:about', $this->anything());
        $menus->expects($this->once())->method('ensurePlacement')->with(3, 'abc12345', null);
        $menus->expects($this->once())->method('ensurePageMenuPlacement')->with(3, 'about', 'parent01');

        $gateway = new SiteProvisioningGateway(
            $menus,
            $this->createMock(DomainSettingsService::class),
            $this->createMock(BlockKitApplier::class)
        );

        $gateway->ensureMenuItem(3, 'sitekit:group:about', ['label' => '회사소개']);
        $gateway->ensureMenuPlacement(3, 'abc12345');
        $gateway->ensurePageMenuPlacement(3, 'about', 'parent01');
    }

    /**
     * updateThemeConfig() 는 최상위 얕은 병합이라 frame_overrides 를 통째로 덮는다.
     * 게이트웨이가 기존 값을 읽어 FrameOverride 규약으로 병합해야 다른 패키지가 산다.
     */
    public function testSetPackageFrameOverridePreservesOtherPackages(): void
    {
        $settings = $this->createMock(DomainSettingsService::class);
        $settings->method('getThemeConfig')->willReturn([
            'frame' => 'basic',
            'frame_overrides' => ['packages' => ['shop' => 'store']],
        ]);
        $settings->expects($this->once())
            ->method('updateThemeConfig')
            ->with(9, [
                'frame' => 'basic',
                'frame_overrides' => ['packages' => ['shop' => 'store', 'sitekit' => 'default']],
            ])
            ->willReturn(Result::success('ok'));

        $gateway = new SiteProvisioningGateway(
            $this->createMock(MenuService::class),
            $settings,
            $this->createMock(BlockKitApplier::class)
        );

        // 대문자로 넘겨도 소문자 canonical name 으로 정규화된다.
        $this->assertTrue($gateway->setPackageFrameOverride(9, 'SiteKit', 'default')->isSuccess());
    }

    public function testSetPackageFrameOverrideRemovesOnNull(): void
    {
        $settings = $this->createMock(DomainSettingsService::class);
        $settings->method('getThemeConfig')->willReturn([
            'frame_overrides' => ['packages' => ['shop' => 'store', 'sitekit' => 'default']],
        ]);
        $settings->expects($this->once())
            ->method('updateThemeConfig')
            ->with(9, ['frame_overrides' => ['packages' => ['shop' => 'store']]])
            ->willReturn(Result::success('ok'));

        $gateway = new SiteProvisioningGateway(
            $this->createMock(MenuService::class),
            $settings,
            $this->createMock(BlockKitApplier::class)
        );

        $gateway->setPackageFrameOverride(9, 'sitekit', null);
    }

    public function testSetPackageFrameOverrideRejectsEmptyPackage(): void
    {
        $result = $this->gateway()->setPackageFrameOverride(9, '  ', 'default');

        $this->assertFalse($result->isSuccess());
    }
}
