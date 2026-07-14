<?php

namespace Tests\Unit\Rendering;

use Mublo\Core\Rendering\FrontViewRenderer;
use Mublo\Core\Rendering\ViewContext;
use Mublo\Core\Response\ViewResponse;
use Tests\TestCase;

class FrontViewRendererContentModeTest extends TestCase
{
    public function testChromelessRendersOnlyCoreHeadContentAndFoot(): void
    {
        $viewContext = new ViewContext('front');
        $viewContext->layout(['mode' => 'chromeless']);
        $renderer = $this->renderer($viewContext);

        ob_start();
        $handled = $renderer->renderEscape(
            ViewResponse::view('index/index'),
            '<main>index</main>',
            ['pageTitle' => 'Home']
        );
        $output = (string) ob_get_clean();

        $this->assertTrue($handled);
        $this->assertSame('<Head.php><main>index</main><Foot.php>', $output);
    }

    public function testStandaloneStillWinsWithoutCoreFrameParts(): void
    {
        $viewContext = new ViewContext('front');
        $viewContext->layout([
            'standalone' => true,
            'mode' => 'chromeless',
        ]);
        $renderer = $this->renderer($viewContext);

        ob_start();
        $handled = $renderer->renderEscape(
            ViewResponse::view('index/index'),
            '<html>standalone</html>',
            []
        );
        $output = (string) ob_get_clean();

        $this->assertTrue($handled);
        $this->assertSame('<html>standalone</html>', $output);
    }

    public function testDefaultModeFallsThroughToNormalFrameAssembly(): void
    {
        $renderer = $this->renderer(new ViewContext('front'));

        ob_start();
        $handled = $renderer->renderEscape(
            ViewResponse::view('index/index'),
            '<main>index</main>',
            []
        );
        $output = (string) ob_get_clean();

        $this->assertFalse($handled);
        $this->assertSame('', $output);
    }

    public function testChromelessIsIgnoredOutsideIndexViews(): void
    {
        $viewContext = new ViewContext('front');
        $viewContext->layout(['mode' => 'chromeless']);
        $renderer = $this->renderer($viewContext);

        ob_start();
        $handled = $renderer->renderEscape(
            ViewResponse::view('auth/login'),
            '<main>login</main>',
            []
        );
        $output = (string) ob_get_clean();

        $this->assertFalse($handled);
        $this->assertSame('', $output);
    }

    private function renderer(ViewContext $viewContext): FrontViewRenderer
    {
        $renderer = new class extends FrontViewRenderer {
            public function __construct()
            {
            }

            public function setViewContext(ViewContext $viewContext): void
            {
                $this->viewContext = $viewContext;
            }

            public function renderEscape(
                ViewResponse $response,
                string $contentHtml,
                array $viewData
            ): bool {
                return $this->renderContentEscapeMode($response, $contentHtml, $viewData);
            }

            protected function includeFrameView(string $file, array $data = []): void
            {
                echo '<' . $file . '>';
            }
        };

        $renderer->setViewContext($viewContext);
        return $renderer;
    }
}
