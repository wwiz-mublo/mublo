<?php

namespace Mublo\Packages\Shop\Tests\Unit\EventSubscriber;

use Mublo\Contract\Menu\MenuDescriptor;
use Mublo\Contract\Menu\MenuManagementInterface;
use Mublo\Contract\Site\SiteProvisioningInterface;
use Mublo\Core\Result\Result;
use Mublo\Packages\Shop\Event\CategoryDeletedEvent;
use Mublo\Packages\Shop\Event\CategoryUpdatedEvent;
use Mublo\Packages\Shop\EventSubscriber\CategoryMenuSubscriber;
use Mublo\Packages\Shop\EventSubscriber\DomainEventSubscriber;
use PHPUnit\Framework\TestCase;

final class MenuContractTest extends TestCase
{
    public function testSeedsShopMenusThroughStableProvisioningContract(): void
    {
        $provisioning = $this->createMock(SiteProvisioningInterface::class);
        $captured = [];
        $provisioning->expects($this->exactly(9))
            ->method('createMenuItem')
            ->willReturnCallback(function (int $domainId, array $data) use (&$captured): Result {
                $this->assertSame(7, $domainId);
                $captured[] = $data;

                return Result::success();
            });

        DomainEventSubscriber::seedMenus($provisioning, 7);

        $this->assertSame(
            [
                '/shop',
                '/shop/exhibitions',
                '/shop/cart',
                '/shop/reviews',
                '/shop/inquiries',
                '/shop/orders',
                '/shop/coupons',
                '/shop/wishlist',
                '/mypage/shop',
            ],
            array_column($captured, 'url')
        );
        $this->assertSame(['package'], array_values(array_unique(array_column($captured, 'provider_type'))));
        $this->assertSame(['Shop'], array_values(array_unique(array_column($captured, 'provider_name'))));
    }

    public function testCategorySynchronizationKeepsDomainOwnershipAtContractBoundary(): void
    {
        $menus = $this->createMock(MenuManagementInterface::class);
        $menus->expects($this->exactly(2))
            ->method('findMenusByUrlPrefix')
            ->with(23, '/shop/category/')
            ->willReturn([new MenuDescriptor(41, 'shop-category', 'Old', '/shop/category/books')]);
        $menus->expects($this->once())
            ->method('updateMenu')
            ->with(23, 41, ['label' => 'Books'])
            ->willReturn(Result::success());
        $menus->expects($this->once())
            ->method('removeMenu')
            ->with(23, 41)
            ->willReturn(Result::success());

        $subscriber = new CategoryMenuSubscriber($menus);
        $subscriber->onUpdated(new CategoryUpdatedEvent(23, 'books', 'Books'));
        $subscriber->onDeleted(new CategoryDeletedEvent(23, 'books'));
    }
}
