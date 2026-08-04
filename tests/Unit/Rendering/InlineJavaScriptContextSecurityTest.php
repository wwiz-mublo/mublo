<?php

namespace Tests\Unit\Rendering;

use PHPUnit\Framework\TestCase;

/**
 * tests/Unit/Rendering/InlineJavaScriptContextSecurityTest.php
 *
 * 인라인 JS 컨텍스트 이스케이프 — 코어 뷰
 *
 * htmlspecialchars() 는 HTML 컨텍스트용이라 `<script>` 안의 JS 문자열
 * 리터럴을 보호하지 못한다. `</script>` 로 스크립트 블록을 닫아버리거나
 * 따옴표를 탈출할 수 있다. JS 로 나가는 값은 json_encode() 에
 * JSON_HEX_* 플래그를 얹어 리터럴 자체를 생성해야 한다.
 *
 * 같은 성격의 회귀 테스트를 확장도 각자 소유한다.
 * @see packages/Board/tests/Unit/Rendering/InlineJavaScriptContextSecurityTest.php
 * @see packages/Shop/tests/Unit/Rendering/InlineJavaScriptContextSecurityTest.php
 */
class InlineJavaScriptContextSecurityTest extends TestCase
{
    public function testDynamicLabelsArePassedThroughDataAttributesInsteadOfInlineHandlers(): void
    {
        $paths = [
            '/views/Admin/Blockrow/Index.php',
            '/views/Admin/Blockpage/Index.php',
            '/views/Admin/Dashboard/Index.php',
            '/views/Admin/Policy/Form.php',
        ];

        foreach ($paths as $path) {
            $source = file_get_contents(MUBLO_ROOT_PATH . $path);
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
            '/views/Admin/Blockpage/Form.php',
            '/views/Admin/Domains/Form.php',
            '/views/Admin/Member-levels/Form.php',
            '/views/Admin/Policy/Form.php',
            '/views/Block/menu/sidebar/sidebar.php',
            '/views/Front/frame/basic/Head.php',
        ];

        $unsafeMarkers = [
            "const actionUrl = '<?=",
            "getElementById('<?=",
            "'/p/<?=",
            'wcs_add["wa"]="<?=',
        ];

        foreach ($paths as $path) {
            $source = file_get_contents(MUBLO_ROOT_PATH . $path);
            $this->assertIsString($source, $path);
            foreach ($unsafeMarkers as $marker) {
                $this->assertStringNotContainsString($marker, $source, $path);
            }
        }
    }
}
