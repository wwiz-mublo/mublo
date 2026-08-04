<?php
declare(strict_types=1);

namespace Mublo\Contract\Extension;

interface ExtensionCatalogInterface
{
    /** @return list<string> */
    public function pluginNames(): array;

    /** @return list<string> */
    public function packageNames(): array;
}
