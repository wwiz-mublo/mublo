<?php
declare(strict_types=1);
namespace Mublo\Controller\Admin;

use Mublo\Core\Context\Context;
use Mublo\Core\Response\ViewResponse;

class GuideController
{
    /**
     * GET /admin/guide
     */
    public function index(array $params, Context $context): ViewResponse
    {
        return ViewResponse::view('guide/index')->withData([
            'pageTitle' => '관리자 매뉴얼',
        ]);
    }
}

