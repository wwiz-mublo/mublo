<?php
declare(strict_types=1);

namespace Mublo\Service\Extension;

use Mublo\Contract\Extension\ExtensionCatalogInterface;

final class ExtensionCatalog implements ExtensionCatalogInterface
{
    public function __construct(private ExtensionService $extensions)
    {
    }

    public function pluginNames(): array
    {
        return array_keys($this->extensions->getPluginManifests());
    }

    public function packageNames(): array
    {
        return array_keys($this->extensions->getPackageManifests());
    }
}
