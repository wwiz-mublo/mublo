<?php

namespace Tests\Unit\Rendering;

use PHPUnit\Framework\TestCase;

class InlineJavaScriptContextSecurityTest extends TestCase
{
    public function testDynamicLabelsArePassedThroughDataAttributesInsteadOfInlineHandlers(): void
    {
        $paths = [
            '/views/Admin/Blockrow/Index.php',
            '/views/Admin/Blockpage/Index.php',
            '/views/Admin/Dashboard/Index.php',
            '/views/Admin/Policy/Form.php',
            '/packages/Board/views/Admin/Config/Index.php',
            '/packages/Board/views/Admin/Group/Index.php',
            '/packages/Board/views/Admin/Category/Index.php',
            '/packages/Shop/views/Admin/Order/View.php',
            '/packages/Shop/views/Front/Review/basic/List.php',
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
            '/packages/Board/views/Front/Board/basic/View.php',
            '/packages/Board/views/Front/Board/basic/Write.php',
            '/packages/Board/views/Front/Board/gallery/View.php',
            '/packages/Board/views/Front/Board/gallery/Write.php',
            '/views/Admin/Blockpage/Form.php',
            '/views/Admin/Domains/Form.php',
            '/views/Admin/Member-levels/Form.php',
            '/views/Admin/Policy/Form.php',
            '/views/Block/menu/sidebar/sidebar.php',
            '/views/Front/Index/mublo/Index.php',
            '/views/Front/frame/basic/Head.php',
        ];

        $unsafeMarkers = [
            "const boardSlug = '<?=",
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
