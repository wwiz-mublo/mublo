<?php

namespace Tests\Unit\Controller\Front;

use Mublo\Controller\Front\PageController;
use Mublo\Core\Context\Context;
use Mublo\Core\Http\Request;
use Mublo\Entity\Block\BlockPage;
use Mublo\Entity\Domain\Domain;
use Mublo\Service\Auth\AuthService;
use Mublo\Service\Block\BlockPageService;
use Mublo\Service\Block\BlockRenderService;
use PHPUnit\Framework\TestCase;

final class PageControllerTest extends TestCase
{
    public function testAnonymousPreviewFlagDoesNotExposeInactivePage(): void
    {
        $pageService = $this->createMock(BlockPageService::class);
        $pageService->method('getPageByCode')->with(7, 'secret')->willReturn($this->inactivePage());
        $render = $this->createMock(BlockRenderService::class);
        $render->expects($this->never())->method('renderPage');
        $auth = $this->createMock(AuthService::class);
        $auth->method('canOperateDomain')->willReturn(false);

        $response = (new PageController($pageService, $render, $auth))
            ->view(['code' => 'secret'], $this->context(['preview' => '1']));

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testDomainOperatorCanPreviewInactivePageWithoutUsingPublicCache(): void
    {
        $pageService = $this->createMock(BlockPageService::class);
        $pageService->method('getPageByCode')->willReturn($this->inactivePage());
        $render = $this->createMock(BlockRenderService::class);
        $render->expects($this->once())->method('renderPage')->with(31, false)->willReturn('<section>draft</section>');
        $auth = $this->createMock(AuthService::class);
        $auth->method('canOperateDomain')->willReturn(true);
        $auth->method('user')->willReturn(['level_value' => 10]);

        $response = (new PageController($pageService, $render, $auth))
            ->view(['code' => 'secret'], $this->context(['preview' => '1']));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('<section>draft</section>', $response->getViewData()['blockHtml']);
        $this->assertSame('no-store, no-cache, must-revalidate', $response->getHeaders()['Cache-Control']);
    }

    private function inactivePage(): BlockPage
    {
        return BlockPage::fromArray([
            'page_id' => 31,
            'domain_id' => 7,
            'page_code' => 'secret',
            'page_title' => '미공개',
            'allow_level' => 0,
            'is_active' => 0,
        ]);
    }

    private function context(array $query): Context
    {
        $context = new Context(new Request('GET', '/p/secret', $query));
        $context->setDomainInfo(new Domain(7, 'example.test'));
        return $context;
    }
}
