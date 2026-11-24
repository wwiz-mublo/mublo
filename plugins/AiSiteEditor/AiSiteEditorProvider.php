<?php
declare(strict_types=1);

namespace Mublo\Plugin\AiSiteEditor;

use Mublo\Core\Container\DependencyContainer;
use Mublo\Core\Context\Context;
use Mublo\Core\Extension\ExtensionProviderInterface;
use Mublo\Plugin\AiSiteEditor\Controller\CapabilitiesController;
use Mublo\Plugin\AiSiteEditor\Service\CapabilityService;
use Mublo\Plugin\AiSiteEditor\Service\ProtocolCatalog;
use Mublo\Plugin\AiSiteEditor\Service\ProtocolValidator;

final class AiSiteEditorProvider implements ExtensionProviderInterface
{
    public function register(DependencyContainer $container): void
    {
        $container->singleton(ProtocolCatalog::class, static fn(): ProtocolCatalog => new ProtocolCatalog(
            __DIR__ . '/resources/protocol'
        ));
        $container->singleton(ProtocolValidator::class, static fn(DependencyContainer $c): ProtocolValidator =>
            new ProtocolValidator($c->get(ProtocolCatalog::class)->tools())
        );
        $container->singleton(CapabilityService::class, static fn(DependencyContainer $c): CapabilityService =>
            new CapabilityService($c->get(ProtocolCatalog::class))
        );
        $container->singleton(CapabilitiesController::class, static fn(DependencyContainer $c): CapabilitiesController =>
            new CapabilitiesController($c->get(CapabilityService::class))
        );
    }

    public function boot(DependencyContainer $container, Context $context): void
    {
    }
}
