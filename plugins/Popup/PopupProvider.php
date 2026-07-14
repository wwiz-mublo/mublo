<?php

namespace Mublo\Plugin\Popup;

use Mublo\Contract\DataResetResult;
use Mublo\Contract\DataResettableInterface;
use Mublo\Core\Container\DependencyContainer;
use Mublo\Core\Context\Context;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Core\Extension\ExtensionProviderInterface;
use Mublo\Core\Extension\MigrationRunner;
use Mublo\Infrastructure\Database\Database;
use Mublo\Plugin\Popup\Controller\PopupController;
use Mublo\Plugin\Popup\Repository\PopupConfigRepository;
use Mublo\Plugin\Popup\Repository\PopupRepository;
use Mublo\Plugin\Popup\Service\PopupService;
use Mublo\Plugin\Popup\Service\PopupDataResetter;

class PopupProvider implements ExtensionProviderInterface, DataResettableInterface
{
    private PopupDataResetter $dataResetter;

    public function register(DependencyContainer $container): void
    {
        $container->singleton(PopupDataResetter::class, fn(DependencyContainer $c) =>
            new PopupDataResetter($c->get(Database::class))
        );
        $container->singleton(PopupRepository::class, fn(DependencyContainer $c) =>
            new PopupRepository($c->get(Database::class))
        );

        $container->singleton(PopupConfigRepository::class, fn(DependencyContainer $c) =>
            new PopupConfigRepository($c->get(Database::class))
        );

        $container->singleton(PopupService::class, fn(DependencyContainer $c) =>
            new PopupService(
                $c->get(PopupRepository::class),
                $c->get(PopupConfigRepository::class)
            )
        );

        $container->singleton(PopupController::class, function (DependencyContainer $c) {
            return new PopupController(
                $c->get(PopupService::class),
                $c->get(MigrationRunner::class)
            );
        });
    }

    public function boot(DependencyContainer $container, Context $context): void
    {
        $this->dataResetter = $container->get(PopupDataResetter::class);
        $eventDispatcher = $container->get(EventDispatcher::class);
        $eventDispatcher->addSubscriber(new AdminMenuSubscriber());
        $eventDispatcher->addSubscriber(new FrontRenderSubscriber(
            $container->get(PopupService::class),
            $container->get(\Mublo\Core\Rendering\FrontViewRuntime::class)
        ));
    }

    public function getResetCategories(): array
    {
        return $this->dataResetter->getResetCategories();
    }

    public function reset(string $category, int $domainId): DataResetResult
    {
        return $this->dataResetter->reset($category, $domainId);
    }
}
