<?php
/**
 * FrontViewRenderer 스킨 격리(막코딩 방어) 테스트
 *
 * 스킨 파일에서 Error/Exception이 발생해도 페이지 전체가 죽지 않고
 * 그 스킨 자리에만 에러 박스가 출력되는지 검증한다.
 *
 * - 절반 출력 후 죽은 스킨의 부분 출력은 버려진다 (열린 태그로 레이아웃 파괴 방지)
 * - 스킨이 자기 ob_start를 연 채 죽어도 버퍼 레벨이 복원된다
 * - 운영 모드에서는 예외 메시지가 노출되지 않고, 디버그 모드에서만 상세 표시
 */

namespace Tests\Unit\Rendering;

use Tests\TestCase;
use Mublo\Core\Rendering\FrontViewRenderer;
use Mublo\Core\Rendering\ViewContext;
use Mublo\Core\Response\ViewResponse;
use Mublo\Core\Context\Context;

class FrontViewRendererIsolationTest extends TestCase
{
    private string $prevErrorLog = '';
    private mixed $prevAppDebug = null;

    protected function setUp(): void
    {
        parent::setUp();
        // 격리 실패 시 error_log가 테스트 출력을 오염시키지 않도록 임시 파일로 우회
        $this->prevErrorLog = (string) ini_get('error_log');
        ini_set('error_log', tempnam(sys_get_temp_dir(), 'mublo-test-log'));
        $this->prevAppDebug = $_ENV['APP_DEBUG'] ?? null;
        unset($_ENV['APP_DEBUG']);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->prevErrorLog);
        if ($this->prevAppDebug === null) {
            unset($_ENV['APP_DEBUG']);
        } else {
            $_ENV['APP_DEBUG'] = $this->prevAppDebug;
        }
        parent::tearDown();
    }

    /** render() 호출 시 지정한 동작을 수행하는 ViewContext */
    private function contextWith(\Closure $onRender): ViewContext
    {
        return new class('front', $onRender) extends ViewContext {
            private \Closure $onRender;
            public function __construct(string $area, \Closure $onRender)
            {
                parent::__construct($area);
                $this->onRender = $onRender;
            }
            public function render(string $path, array $data = []): void
            {
                ($this->onRender)($path, $data);
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

    /** 실존하는 코어 뷰(auth/login, basic 스킨)를 향해 renderContent를 호출하고 출력을 반환 */
    private function renderCapturing(FrontViewRenderer $renderer): string
    {
        $context = $this->createMock(Context::class);
        $context->method('getFrontSkin')->willReturn('basic');

        ob_start();
        $renderer->callRenderContent(ViewResponse::view('auth/login'), $context);
        return ob_get_clean();
    }

    public function testSkinExceptionIsIsolatedAndPartialOutputDiscarded(): void
    {
        $vc = $this->contextWith(function (): void {
            echo '<div>절반만 출력된 스킨';
            throw new \RuntimeException('skin boom');
        });

        $level = ob_get_level();
        $output = $this->renderCapturing($this->renderer($vc));

        $this->assertSame($level, ob_get_level(), '출력 버퍼 레벨이 복원되어야 한다');
        $this->assertStringContainsString('mublo-skin-error', $output, '스킨 자리에 에러 박스가 출력되어야 한다');
        $this->assertStringNotContainsString('절반만 출력된 스킨', $output, '실패한 스킨의 부분 출력은 버려져야 한다');
    }

    public function testBufferLevelRestoredWhenSkinDiesWithOpenBuffer(): void
    {
        $vc = $this->contextWith(function (): void {
            ob_start(); // 스킨이 자기 버퍼를 연 채
            echo 'inner';
            throw new \Error('fatal in skin');
        });

        $level = ob_get_level();
        $output = $this->renderCapturing($this->renderer($vc));

        $this->assertSame($level, ob_get_level(), '스킨이 연 버퍼까지 정리되어야 한다');
        $this->assertStringContainsString('mublo-skin-error', $output);
        $this->assertStringNotContainsString('inner', $output);
    }

    public function testProductionModeDoesNotLeakExceptionDetails(): void
    {
        $vc = $this->contextWith(function (): void {
            throw new \RuntimeException('internal path C:/secret/skin.php');
        });

        $output = $this->renderCapturing($this->renderer($vc));

        $this->assertStringContainsString('화면 일부를 표시할 수 없습니다', $output);
        $this->assertStringNotContainsString('internal path', $output, '운영 모드에서 예외 메시지가 노출되면 안 된다');
    }

    public function testDebugModeShowsExceptionDetails(): void
    {
        $_ENV['APP_DEBUG'] = 'true';

        $vc = $this->contextWith(function (): void {
            throw new \RuntimeException('skin boom detail');
        });

        $output = $this->renderCapturing($this->renderer($vc));

        $this->assertStringContainsString('skin boom detail', $output, '디버그 모드에서는 예외 메시지를 표시한다');
        $this->assertStringContainsString('RuntimeException', $output);
    }

    public function testHealthySkinRendersUnchanged(): void
    {
        $vc = $this->contextWith(function (): void {
            echo '<main>정상 스킨</main>';
        });

        $output = $this->renderCapturing($this->renderer($vc));

        $this->assertStringContainsString('<main>정상 스킨</main>', $output);
        $this->assertStringNotContainsString('mublo-skin-error', $output);
    }
}
