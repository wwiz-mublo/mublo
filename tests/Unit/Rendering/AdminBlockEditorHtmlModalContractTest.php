<?php

namespace Tests\Unit\Rendering;

use PHPUnit\Framework\TestCase;

class AdminBlockEditorHtmlModalContractTest extends TestCase
{
    public function testPreviewFrameDoesNotUseTheRemovedRadiusToken(): void
    {
        $view = file_get_contents(MUBLO_ROOT_PATH . '/views/Admin/Blockeditor/Index.php');
        $style = file_get_contents(MUBLO_ROOT_PATH . '/public/assets/css/admin/block-editor.css');

        $this->assertIsString($view);
        $this->assertIsString($style);
        $this->assertStringNotContainsString('--bke-radius', $view . $style);
        $this->assertStringNotContainsString('border-radius: var(--bke-radius)', $view . $style);
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
     */
    public function testUnsavedEditsAreGuardedBeforeEitherEditModalDiscardsThem(): void
    {
        $view = file_get_contents(MUBLO_ROOT_PATH . '/views/Admin/Blockeditor/Index.php');

        $this->assertIsString($view);
        $this->assertStringContainsString('function bkefIsDirty()', $view);
        $this->assertStringContainsString('function cmIsDirty()', $view);
        // 닫기(배경 포함)는 변경분이 있으면 확인을 거친다 — 무조건 폐기하던 배선은 없어야 한다
        $this->assertStringNotContainsString("el.addEventListener('click', closeContentModal)", $view);
        $this->assertStringNotContainsString(
            "el.addEventListener('click', () => bkef.modal.classList.remove('open'))",
            $view
        );
        // 탭 닫기·새로고침·뒤로가기도 같은 기준으로 막는다
        $this->assertStringContainsString("window.addEventListener('beforeunload'", $view);
    }
}
