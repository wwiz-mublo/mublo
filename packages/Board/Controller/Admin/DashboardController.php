<?php
declare(strict_types=1);

namespace Mublo\Packages\Board\Controller\Admin;

use Mublo\Core\Context\Context;
use Mublo\Core\Response\ViewResponse;
use Mublo\Packages\Board\Service\BoardDashboardService;

/**
 * Admin DashboardController
 *
 * 게시판 관리자 대시보드 (K_Board_000)
 *
 * 라우트:
 * - GET /admin/board/dashboard → index
 */
class DashboardController
{
    public function __construct(
        private BoardDashboardService $dashboardService,
    ) {}

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
