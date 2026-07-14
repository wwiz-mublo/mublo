<?php

namespace Mublo\Core\Report\Contract;

interface PermissionGateInterface
{
    public function assertDownloadAllowed(int $domainId, string $menuCode): void;
}

