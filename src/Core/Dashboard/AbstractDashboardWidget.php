<?php

namespace Mublo\Core\Dashboard;

use Mublo\Core\Context\Context;

abstract class AbstractDashboardWidget implements DashboardWidgetInterface
{
    public function assets(): array
    {
        return [];
    }

    public function defaultSlot(): int
    {
        return 2;
    }

    public function canView(Context $context): bool
    {
        return true;
    }
}
