<?php

namespace Tests\Unit\Core\Event\Domain;

use Mublo\Core\Event\Domain\DomainCreatedEvent;
use Mublo\Core\Event\Domain\DomainEventSubscriber;
use Mublo\Core\Event\Domain\DomainProvisioningEvent;
use Mublo\Core\Result\Result;
use Mublo\Service\Extension\ExtensionService;
use Mublo\Service\Member\PolicyService;
use Mublo\Service\Menu\MenuService;
use PHPUnit\Framework\TestCase;

class DomainEventSubscriberTest extends TestCase
{
    public function testRequiredCoreSeedsRunDuringProvisioning(): void
    {
        $menuService = $this->createMock(MenuService::class);
        $policyService = $this->createMock(PolicyService::class);

        $menuService->expects($this->once())
            ->method('seedDefaultMenus')
            ->with(17)
            ->willReturn(Result::success());
        $policyService->expects($this->once())
            ->method('seedDefaultPolicies')
            ->with(17)
            ->willReturn(Result::success());

        $subscriber = new DomainEventSubscriber($menuService, $policyService);
        $subscriber->onDomainProvisioning(new DomainProvisioningEvent(17, '1/17', 5, 1));
    }

    public function testRequiredSeedFailureIsRethrownForTransactionRollback(): void
    {
        $menuService = $this->createMock(MenuService::class);
        $policyService = $this->createMock(PolicyService::class);

        $menuService->method('seedDefaultMenus')
            ->willReturn(Result::failure('기본 메뉴 생성 실패'));
        $policyService->expects($this->never())->method('seedDefaultPolicies');

        $subscriber = new DomainEventSubscriber($menuService, $policyService);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('기본 메뉴 생성 실패');
        $subscriber->onDomainProvisioning(new DomainProvisioningEvent(17, '1/17', 5, 1));
    }

    public function testExtensionSeedingRunsOnlyAfterDomainCommit(): void
    {
        $menuService = $this->createMock(MenuService::class);
        $policyService = $this->createMock(PolicyService::class);
        $extensionService = $this->createMock(ExtensionService::class);

        $menuService->expects($this->never())->method('seedDefaultMenus');
        $policyService->expects($this->never())->method('seedDefaultPolicies');
        $extensionService->expects($this->once())->method('seedDefaultExtensions')->with(17);

        $subscriber = new DomainEventSubscriber($menuService, $policyService, $extensionService);
        $subscriber->onDomainCreated(new DomainCreatedEvent(17, '1/17', 5, 1));
    }
}
