<?php

namespace Tests\Board\Unit\Rendering;

use PHPUnit\Framework\TestCase;

/**
 * packages/Board/tests/Unit/Rendering/InlineJavaScriptContextSecurityTest.php
 *
 * 인라인 JS 컨텍스트 이스케이프 — Board 뷰
 *
 * htmlspecialchars() 는 HTML 컨텍스트용이라 `<script>` 안의 JS 문자열
 * 리터럴을 보호하지 못한다. Board 프론트 스킨이 `const boardSlug = '<?= $boardSlug ?>';`
 * 처럼 값을 따옴표 안에 직접 박고 있었고, htmlspecialchars() 를 거쳤어도
 * `</script>` 로 스크립트 블록을 닫아버릴 수 있었다.
 * JS 로 나가는 값은 json_encode() 에 JSON_HEX_* 플래그를 얹는다.
 *
 * 관리자 뷰 쪽은 인라인 onclick 에 값을 박던 것을 data-* 로 뺐다.
 *
 * 코어 뷰의 동일 회귀는 코어가 소유한다.
 * @see tests/Unit/Rendering/InlineJavaScriptContextSecurityTest.php
 */
class InlineJavaScriptContextSecurityTest extends TestCase
{
    public function testDynamicLabelsArePassedThroughDataAttributesInsteadOfInlineHandlers(): void
    {
        $paths = [
            '/views/Admin/Config/Index.php',
            '/views/Admin/Group/Index.php',
            '/views/Admin/Category/Index.php',
        ];

        foreach ($paths as $path) {
            $source = file_get_contents(MUBLO_PACKAGE_PATH . '/Board' . $path);
            $this->assertIsString($source, $path);
            $this->assertDoesNotMatchRegularExpression(
                '/onclick\s*=\s*"[^"]*<\?=\s*(?:htmlspecialchars|e)\s*\(/s',
                $source,
                $path
            );
            $this->assertDoesNotMatchRegularExpression(
                "/onclick\\s*=\\s*'[^']*<\\?=\\s*(?:htmlspecialchars|e)\\s*\\(/s",
                $source,
                $path
            );
        }
    }

    public function testDynamicJavaScriptStringsAreEncodedAsJavaScriptLiterals(): void
    {
        $paths = [
            '/views/Front/Board/basic/View.php',
            '/views/Front/Board/basic/Write.php',
            '/views/Front/Board/gallery/View.php',
            '/views/Front/Board/gallery/Write.php',
        ];

        $unsafeMarkers = [
            "const boardSlug = '<?=",
            "const actionUrl = '<?=",
            "getElementById('<?=",
        ];

        foreach ($paths as $path) {
            $source = file_get_contents(MUBLO_PACKAGE_PATH . '/Board' . $path);
            $this->assertIsString($source, $path);
            foreach ($unsafeMarkers as $marker) {
                $this->assertStringNotContainsString($marker, $source, $path);
            }
        }
    }
}
