<?php
declare(strict_types=1);
namespace Mublo\Controller\Front;

use Mublo\Core\Response\ViewResponse;
use Mublo\Core\Context\Context;

class IndexController
{
    public function index(array $params, Context $context): ViewResponse
    {
        $siteConfig = $context->getDomainInfo()?->getSiteConfig() ?? [];

        $pageConfig = [];

        if (empty($siteConfig['use_main_layout'])) {
            $pageConfig = [
                'layout_type' => 1,
                'use_fullpage' => 1,
            ];
        }

        return ViewResponse::view('index/index')
            ->withData([
                'pageTitle' => '',
                '_pageConfig' => $pageConfig,
            ]);
    }
}
