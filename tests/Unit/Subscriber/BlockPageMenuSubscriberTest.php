<?php

namespace Tests\Unit\Subscriber;

use Mublo\Core\Container\DependencyContainer;
use Mublo\Core\Event\Block\BlockPageCreatedEvent;
use Mublo\Core\Event\Block\BlockPageMenuSyncEvent;
use Mublo\Core\Event\Block\BlockPageUpdatedEvent;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Core\Event\EventInterface;
use Mublo\Repository\Menu\MenuItemRepository;
use Mublo\Service\Menu\MenuService;
use Mublo\Subscriber\BlockPageMenuSubscriber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BlockPageMenuSubscriberTest extends TestCase
{
    #[DataProvider('pageMenuEventProvider')]
    public function testPageEventsCreateMissingMenuItem(EventInterface $event): void
    {
        $menuService = $this->createMock(MenuService::class);
        $menuService->expects($this->once())
            ->method('createItem')
            ->with(7, [
                'label' => '회사소개',
                'url' => '/p/about',
                'provider_type' => 'core',
                'provider_name' => 'blockpage',
            ]);

        $repository = $this->createMock(MenuItemRepository::class);
        $repository->method('findByProvider')->with(7, 'core', 'blockpage')->willReturn([]);

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new BlockPageMenuSubscriber(
            $this->container($menuService, $repository)
        ));

        $dispatcher->dispatch($event);
    }

    /** @return iterable<string, array{EventInterface}> */
    public static function pageMenuEventProvider(): iterable
    {
        yield 'regular page creation' => [new BlockPageCreatedEvent(7, 55, 'about', '회사소개')];
        yield 'block kit page creation' => [new BlockPageMenuSyncEvent(7, 55, 'about', '회사소개')];
    }

    public function testKitPageSyncIsIdempotentAndRefreshesExistingLabel(): void
    {
        $menuService = $this->createMock(MenuService::class);
        $menuService->expects($this->never())->method('createItem');
        $menuService->expects($this->once())
            ->method('updateItem')
            ->with(91, ['label' => '새 회사소개'], 7);

        $repository = $this->createMock(MenuItemRepository::class);
        $repository->method('findByProvider')->willReturn([[
            'item_id' => 91,
            'label' => '회사소개',
            'url' => '/p/about',
        ]]);

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new BlockPageMenuSubscriber(
            $this->container($menuService, $repository)
        ));

        $dispatcher->dispatch(new BlockPageMenuSyncEvent(7, 55, 'about', '새 회사소개'));
    }

    public function testPageCodeChangeRemovesOldMenuAndCreatesNewMenu(): void
    {
        $menuService = $this->createMock(MenuService::class);
        $menuService->expects($this->once())->method('deleteItem')->with(81);
        $menuService->expects($this->once())->method('createItem')->with(7, [
            'label' => '새 회사소개',
            'url' => '/p/company',
            'provider_type' => 'core',
            'provider_name' => 'blockpage',
        ]);

        $repository = $this->createMock(MenuItemRepository::class);
        $repository->expects($this->exactly(2))
            ->method('findByProvider')
            ->willReturnOnConsecutiveCalls(
                [['item_id' => 81, 'label' => '회사소개', 'url' => '/p/about']],
                []
            );

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new BlockPageMenuSubscriber(
            $this->container($menuService, $repository)
        ));

        $dispatcher->dispatch(new BlockPageUpdatedEvent(
            7,
            55,
            'about',
            'company',
            '새 회사소개'
        ));
    }

    private function container(
        MenuService $menuService,
        MenuItemRepository $repository
    ): DependencyContainer {
        $container = $this->createMock(DependencyContainer::class);
        $container->method('get')->willReturnCallback(
            static fn (string $id): object => match ($id) {
                MenuService::class => $menuService,
                MenuItemRepository::class => $repository,
            }
        );
        return $container;
    }
}
