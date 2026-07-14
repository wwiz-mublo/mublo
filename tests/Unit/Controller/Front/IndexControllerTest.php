<?php

namespace Tests\Unit\Controller\Front;

use Mublo\Controller\Front\IndexController;
use Mublo\Core\Context\Context;
use Mublo\Core\Http\Request;
use Mublo\Entity\Domain\Domain;
use Tests\TestCase;

class IndexControllerTest extends TestCase
{
    public function testUsesSiteLayoutWhenMainLayoutIsEnabled(): void
    {
        $controller = new IndexController();

        $response = $controller->index([], $this->contextWithSiteConfig([
            'use_main_layout' => true,
            'layout_type' => 'right-sidebar',
        ]));

        $data = $response->getViewData();

        $this->assertSame([], $data['_pageConfig']);
    }

    public function testUsesWideFullLayoutWhenMainLayoutIsDisabled(): void
    {
        $controller = new IndexController();

        $response = $controller->index([], $this->contextWithSiteConfig([
            'use_main_layout' => false,
            'layout_type' => 'right-sidebar',
        ]));

        $data = $response->getViewData();

        $this->assertSame([
            'layout_type' => 1,
            'use_fullpage' => 1,
        ], $data['_pageConfig']);
    }

    public function testDoesNotRenderBlocksBeforeTheSelectedSkinRuns(): void
    {
        $response = (new IndexController())->index([], $this->contextWithSiteConfig([]));

        $this->assertArrayNotHasKey('blockHtml', $response->getViewData());
    }

    private function contextWithSiteConfig(array $siteConfig): Context
    {
        $context = new Context(new Request('GET', '/'));
        $context->setDomainInfo(new Domain(
            domainId: 7,
            domain: 'example.test',
            siteConfig: $siteConfig,
        ));

        return $context;
    }
}
