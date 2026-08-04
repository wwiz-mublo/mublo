<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\Controller\Admin;

use Mublo\Core\Context\Context;
use Mublo\Core\Response\ViewResponse;
use Mublo\Packages\Shop\Service\DashboardService;

class DashboardController
{
    private DashboardService $dashboardService;

    public function __construct(DashboardService $dashboardService)
    {
        $this->dashboardService = $dashboardService;
    }

    public function index(array $params, Context $context): ViewResponse
    {
        $domainId = $context->getDomainId() ?? 1;

        $data = $this->dashboardService->getDashboardData($domainId);

        return ViewResponse::absoluteView(dirname(__DIR__, 2) . '/views/Admin/Dashboard/Index')
            ->withData(array_merge($data, [
                'pageTitle' => '대시보드',
            ]));
    }
}
