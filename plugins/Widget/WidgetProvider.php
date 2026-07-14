<?php

namespace Mublo\Plugin\Widget;

use Mublo\Contract\DataResetResult;
use Mublo\Contract\DataResettableInterface;
use Mublo\Core\Container\DependencyContainer;
use Mublo\Core\Context\Context;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Core\Extension\ExtensionProviderInterface;
use Mublo\Core\Extension\MigrationRunner;
use Mublo\Infrastructure\Database\Database;
use Mublo\Infrastructure\Storage\FileUploader;
use Mublo\Plugin\Widget\Controller\WidgetController;
use Mublo\Plugin\Widget\Repository\WidgetItemRepository;
use Mublo\Plugin\Widget\Repository\WidgetConfigRepository;
use Mublo\Plugin\Widget\Service\WidgetService;
use Mublo\Plugin\Widget\Service\WidgetDataResetter;

class WidgetProvider implements ExtensionProviderInterface, DataResettableInterface
{
    private WidgetDataResetter $dataResetter;

    public function register(DependencyContainer $container): void
    {
        $container->singleton(WidgetDataResetter::class, fn($c) => new WidgetDataResetter($c->get(Database::class)));
        $container->singleton(WidgetItemRepository::class, fn(DependencyContainer $c) =>
            new WidgetItemRepository($c->get(Database::class))
        );

        $container->singleton(WidgetConfigRepository::class, fn(DependencyContainer $c) =>
            new WidgetConfigRepository($c->get(Database::class))
        );

        $container->singleton(WidgetService::class, fn(DependencyContainer $c) =>
            new WidgetService(
                $c->get(WidgetItemRepository::class),
                $c->get(WidgetConfigRepository::class)
            )
        );

        $container->singleton(WidgetController::class, fn(DependencyContainer $c) =>
            new WidgetController(
                $c->get(WidgetService::class),
                $c->get(MigrationRunner::class),
                $c->get(FileUploader::class)
            )
        );
    }

    public function boot(DependencyContainer $container, Context $context): void
    {
        $this->dataResetter = $container->get(WidgetDataResetter::class);
        $eventDispatcher = $container->get(EventDispatcher::class);
        $eventDispatcher->addSubscriber(new AdminMenuSubscriber());
        $eventDispatcher->addSubscriber(new FrontRenderSubscriber(
            $container->get(WidgetService::class),
            $container->get(\Mublo\Core\Rendering\FrontViewRuntime::class)
        ));
    }

    public function getResetCategories(): array { return $this->dataResetter->getResetCategories(); }
    public function reset(string $category, int $domainId): DataResetResult { return $this->dataResetter->reset($category, $domainId); }
}
