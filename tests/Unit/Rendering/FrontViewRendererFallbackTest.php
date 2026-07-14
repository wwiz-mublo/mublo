<?php
/**
 * FrontViewRenderer per-file 스킨 폴백 테스트
 *
 * 선택한 Front 스킨에 해당 뷰 파일이 없으면 basic 스킨의 동일 파일로 폴백하는지 검증한다.
 * (커스텀 스킨이 일부 파일만 오버라이드해도 나머지는 basic 그대로 렌더 + 코어가 새 파일을 추가해도 안 깨짐)
 *
 * 실제 코어 뷰(views/Front/Auth/basic/Login.php) 존재를 기준으로 검증한다.
 */

namespace Tests\Unit\Rendering;

use Tests\TestCase;
use Mublo\Core\Rendering\FrontViewRenderer;
use Mublo\Core\Rendering\ViewContext;
use Mublo\Core\Response\ViewResponse;
use Mublo\Core\Context\Context;

class FrontViewRendererFallbackTest extends TestCase
{
    /** render(path) 호출을 include 없이 포착하는 ViewContext */
    private function captureContext(): ViewContext
    {
        return new class('front') extends ViewContext {
            public ?string $rendered = null;
            public function render(string $path, array $data = []): void
            {
                $this->rendered = $path;
            }
        };
    }

    /** 부모 생성자를 우회하고 renderContent만 노출하는 테스트용 렌더러 */
    private function renderer(ViewContext $vc): FrontViewRenderer
    {
        $r = new class extends FrontViewRenderer {
            public function __construct() {}
            public function setViewContext(ViewContext $vc): void { $this->viewContext = $vc; }
            public function callRenderContent(ViewResponse $res, Context $ctx): void { $this->renderContent($res, $ctx); }
        };
        $r->setViewContext($vc);
        return $r;
    }

    public function testFallsBackToBasicWhenSelectedSkinFileMissing(): void
    {
        $vc = $this->captureContext();
        $renderer = $this->renderer($vc);

        $context = $this->createMock(Context::class);
        $context->method('getFrontSkin')->willReturn('nonexistent_skin_xyz'); // 없는 스킨

        $renderer->callRenderContent(ViewResponse::view('auth/login'), $context);

        $this->assertNotNull($vc->rendered, '폴백이 렌더를 호출해야 한다');
        $this->assertStringEndsWith(
            '/Front/Auth/basic/Login.php',
            str_replace('\\', '/', $vc->rendered),
            '없는 스킨 → basic 동일 파일로 폴백해야 한다'
        );
    }

    public function testRendersSelectedSkinDirectlyWhenPresent(): void
    {
        $vc = $this->captureContext();
        $renderer = $this->renderer($vc);

        $context = $this->createMock(Context::class);
        $context->method('getFrontSkin')->willReturn('basic'); // 존재하는 스킨

        $renderer->callRenderContent(ViewResponse::view('auth/login'), $context);

        // 파일이 있으면 폴백 없이 그대로 렌더 (정상 경로가 폴백에 가로채이지 않음)
        $this->assertStringEndsWith(
            '/Front/Auth/basic/Login.php',
            str_replace('\\', '/', (string) $vc->rendered)
        );
    }
}
