<?php

namespace Mublo\Packages\Board\Tests\Unit\Subscriber;

use Mublo\Contract\Menu\MenuDescriptor;
use Mublo\Contract\Menu\MenuManagementInterface;
use Mublo\Contract\Site\SiteProvisioningInterface;
use Mublo\Core\Result\Result;
use Mublo\Packages\Board\Entity\BoardConfig;
use Mublo\Packages\Board\Event\BoardConfigCreatedEvent;
use Mublo\Packages\Board\Event\BoardConfigDeletedEvent;
use Mublo\Packages\Board\Subscriber\MenuAutoRegistrationSubscriber;
use PHPUnit\Framework\TestCase;

final class MenuAutoRegistrationSubscriberTest extends TestCase
{
    public function testCreatesAndRemovesBoardMenuThroughStableContracts(): void
    {
        $provisioning = $this->createMock(SiteProvisioningInterface::class);
        $provisioning->expects($this->once())
            ->method('createMenuItem')
            ->with(17, [
                'label' => 'News',
                'url' => '/board/news',
                'provider_type' => 'package',
                'provider_name' => 'Board',
            ])
            ->willReturn(Result::success());

        $menus = $this->createMock(MenuManagementInterface::class);
        $menus->expects($this->once())
            ->method('findProviderMenus')
            ->with(17, 'package', 'Board')
            ->willReturn([new MenuDescriptor(91, 'board-news', 'News', '/board/news')]);
        $menus->expects($this->once())
            ->method('removeMenu')
            ->with(17, 91)
            ->willReturn(Result::success());

        $subscriber = new MenuAutoRegistrationSubscriber($provisioning, $menus);
        $subscriber->onBoardCreated(new BoardConfigCreatedEvent(BoardConfig::fromArray([
            'domain_id' => 17,
            'board_slug' => 'news',
            'board_name' => 'News',
        ])));
        $subscriber->onBoardDeleted(new BoardConfigDeletedEvent(17, 5, 'news', 'News'));
    }
}
