<?php

namespace Tests\Unit\Rendering;

use PHPUnit\Framework\TestCase;

class AdminBlockEditorHtmlModalContractTest extends TestCase
{
    public function testPreviewFrameDoesNotUseTheRemovedRadiusToken(): void
    {
        $view = file_get_contents(MUBLO_ROOT_PATH . '/views/Admin/Blockeditor/Index.php');
        $script = file_get_contents(MUBLO_ROOT_PATH . '/public/assets/js/admin/blockeditor.js');
        $style = file_get_contents(MUBLO_ROOT_PATH . '/public/assets/css/admin/block-editor.css');

        $this->assertIsString($view);
        $this->assertIsString($script);
        $this->assertIsString($style);
        // 에디터 스크립트가 뷰 밖으로 나갔으므로 그쪽도 같이 훑는다. 뷰만 보면
        // 인라인 스타일을 쓰는 JS 가 이 금지 토큰을 되살려도 걸리지 않는다.
        $this->assertStringNotContainsString('--bke-radius', $view . $script . $style);
        $this->assertStringNotContainsString('border-radius: var(--bke-radius)', $view . $script . $style);
    }

    public function testHtmlEditorHasBoundedScrollableEditingSurface(): void
    {
        $view = file_get_contents(MUBLO_ROOT_PATH . '/views/Admin/Blockeditor/Index.php');
        $style = file_get_contents(MUBLO_ROOT_PATH . '/public/assets/css/admin/block-editor.css');

        $this->assertIsString($view);
        $this->assertIsString($style);
        $this->assertStringContainsString(
            '<div class="block-html-editor-wrapper" data-editor-id="bke_html_content">',
            $view
        );
        $this->assertStringContainsString(
            '.bkec-editor-pane .block-html-editor-wrapper .mublo-editor-content',
            $style
        );
        $this->assertStringContainsString('overflow-y: scroll; scrollbar-gutter: stable;', $style);
        $this->assertStringContainsString('.bke-contentmodal *::-webkit-scrollbar', $style);
    }

    public function testPreviewUsesTheSameLocalSliderRuntimeAsTheStorefront(): void
    {
        $preview = file_get_contents(MUBLO_ROOT_PATH . '/public/assets/js/admin/block-preview-iframe.js');

        $this->assertIsString($preview);
        $this->assertStringContainsString('/assets/lib/swiper/12/swiper-bundle.min.css', $preview);
        $this->assertStringContainsString('/assets/lib/swiper/12/swiper-bundle.min.js', $preview);
        $this->assertStringContainsString('/assets/js/MubloSlider.js', $preview);
        $this->assertStringNotContainsString('cdn.jsdelivr.net/npm/swiper', $preview);
    }

    public function testBlockPreviewRunsInsideAnOpaqueSandboxAndUsesMessagesForHeight(): void
    {
        $list = file_get_contents(MUBLO_ROOT_PATH . '/views/Admin/Blockrow/Index.php');
        $form = file_get_contents(MUBLO_ROOT_PATH . '/views/Admin/Blockrow/Form.php');
        $preview = file_get_contents(MUBLO_ROOT_PATH . '/public/assets/js/admin/block-preview-iframe.js');

        $this->assertIsString($list);
        $this->assertIsString($form);
        $this->assertIsString($preview);
        $this->assertStringContainsString('sandbox="allow-scripts"', $list);
        $this->assertStringContainsString('sandbox="allow-scripts"', $form);
        $this->assertStringNotContainsString('allow-same-origin', $list . $form);
        $this->assertStringContainsString('mublo:block-preview-height', $preview);
        $this->assertStringContainsString('event.source !== frame.contentWindow', $preview);
        $this->assertStringNotContainsString('frame.contentDocument', $preview);
    }

    public function testHtmlEditorScopesPreviewCssToTheEditingCanvas(): void
    {
        $editor = file_get_contents(MUBLO_ROOT_PATH . '/public/assets/js/admin/block-html-editor/index.js');

        $this->assertIsString($editor);
        $this->assertStringContainsString("const FRONT_TOKENS_SCOPE = '.mublo-editor-content';", $editor);
        $this->assertStringContainsString('scopeCssForEditor(css)', $editor);
        $this->assertStringContainsString('if (rule.type === CSSRule.IMPORT_RULE)', $editor);
    }

    /**
     * 프레임 HTML 편집·콘텐츠 모달은 '저장'을 눌러야 서버에 남는다. 배경 클릭 한 번에
     * 편집분이 말없이 사라지던 자리라, 폐기 전 변경 여부 확인을 계약으로 고정한다.
     *
     * 대상은 뷰가 아니라 blockeditor.js 다 — 에디터 로직이 뷰 인라인에서 정적 자산으로
     * 나갔다. 이 테스트가 이동을 잡아냈고(그게 이 계약의 값어치다), 위 세 테스트가
     * 이미 assets/js 를 직접 읽고 있어 읽는 대상만 옮기면 계약은 그대로다.
     */
    public function testUnsavedEditsAreGuardedBeforeEitherEditModalDiscardsThem(): void
    {
        $script = file_get_contents(MUBLO_ROOT_PATH . '/public/assets/js/admin/blockeditor.js');

        $this->assertIsString($script);
        $this->assertStringContainsString('function bkefIsDirty()', $script);
        $this->assertStringContainsString('function cmIsDirty()', $script);
        // 닫기(배경 포함)는 변경분이 있으면 확인을 거친다 — 무조건 폐기하던 배선은 없어야 한다
        $this->assertStringNotContainsString("el.addEventListener('click', closeContentModal)", $script);
        $this->assertStringNotContainsString(
            "el.addEventListener('click', () => bkef.modal.classList.remove('open'))",
            $script
        );
        // 탭 닫기·새로고침·뒤로가기도 같은 기준으로 막는다
        $this->assertStringContainsString("window.addEventListener('beforeunload'", $script);
    }

    /**
     * 분리된 스크립트가 서버 데이터를 받는 유일한 통로를 계약으로 고정한다.
     *
     * 뷰의 JSON 아일랜드와 스크립트의 읽기 지점이 각각 따로 바뀌면 화면은
     * 조용히 빈 트리로 뜬다(모든 값이 폴백으로 떨어질 뿐 에러가 안 난다).
     * 양쪽 키가 실제로 맞물려 있는지 여기서 확인한다.
     */
    public function testExtractedEditorReceivesServerDataThroughTheConfigIsland(): void
    {
        $view = file_get_contents(MUBLO_ROOT_PATH . '/views/Admin/Blockeditor/Index.php');
        $script = file_get_contents(MUBLO_ROOT_PATH . '/public/assets/js/admin/blockeditor.js');

        $this->assertIsString($view);
        $this->assertIsString($script);

        // 스크립트는 정적 자산이다 — PHP 가 한 조각이라도 남으면 그대로 브라우저에 노출된다
        $this->assertStringNotContainsString('<?', $script);

        $this->assertStringContainsString('window.MubloBlockEditorConfig = {', $view);
        $this->assertStringContainsString('const CONFIG = window.MubloBlockEditorConfig || {};', $script);

        foreach (['contexts', 'initialContext', 'csrfToken', 'contentTypes', 'skinLists', 'canEditInclude'] as $key) {
            $this->assertStringContainsString($key . ':', $view, "뷰가 {$key} 를 넘기지 않는다");
            $this->assertStringContainsString('CONFIG.' . $key, $script, "스크립트가 {$key} 를 읽지 않는다");
        }

        // 값에 '<' 가 섞여도 인라인 블록이 </script> 로 조기 종료되지 않아야 한다
        $this->assertStringContainsString('JSON_HEX_TAG', $view);

        // 이 스크립트는 즉시 DOM 을 조회하고 선행 전역에 의존한다 — 지연 로드하면 깨진다
        $this->assertMatchesRegularExpression(
            '#<script src="<\?= asset\(\'/assets/js/admin/blockeditor\.js\'\) \?>"></script>#',
            $view
        );
        $this->assertDoesNotMatchRegularExpression(
            '#<script[^>]+blockeditor\.js[^>]*\s(?:defer|async)#',
            $view
        );
    }
}
