<?php

namespace Tests\Unit\Rendering;

use Mublo\Core\Rendering\ViewContext;
use Mublo\Helper\BlockHelper;
use Mublo\Service\Block\BlockRenderService;
use Tests\TestCase;

class BasicIndexSkinTest extends TestCase
{
    private mixed $previousRequestUri = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousRequestUri = $_SERVER['REQUEST_URI'] ?? null;
        $_SERVER['REQUEST_URI'] = '/';
    }

    protected function tearDown(): void
    {
        BlockHelper::setRenderService(null);

        if ($this->previousRequestUri === null) {
            unset($_SERVER['REQUEST_URI']);
        } else {
            $_SERVER['REQUEST_URI'] = $this->previousRequestUri;
        }

        parent::tearDown();
    }

    public function testBasicSkinLazilyRendersTheIndexBlock(): void
    {
        $blocks = $this->getMockBuilder(BlockRenderService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['renderPosition'])
            ->getMock();
        $blocks->expects($this->once())
            ->method('renderPosition')
            ->with(7, 'index', null, true, true)
            ->willReturn('<section>main blocks</section>');
        BlockHelper::setRenderService($blocks);

        ob_start();
        (new ViewContext('front'))->render(
            MUBLO_VIEW_PATH . '/Front/Index/basic/Index.php',
            [
                'mublo' => ['site' => ['domainId' => 7]],
                'pageTitle' => '',
            ]
        );
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('<section>main blocks</section>', $output);
        $this->assertStringNotContainsString('메인 페이지 블록을 설정해주세요', $output);
    }
}
