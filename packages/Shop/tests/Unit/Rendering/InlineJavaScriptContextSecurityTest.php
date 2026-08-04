<?php

namespace Tests\Shop\Unit\Rendering;

use PHPUnit\Framework\TestCase;

/**
 * packages/Shop/tests/Unit/Rendering/InlineJavaScriptContextSecurityTest.php
 *
 * 인라인 JS 컨텍스트 이스케이프 — Shop 뷰
 *
 * 인라인 onclick 핸들러 안에 htmlspecialchars() 로 감싼 값을 박으면,
 * 그 값은 HTML 속성이 아니라 JS 코드로 파싱된다. HTML 이스케이프로는
 * 따옴표 탈출을 막지 못하므로 값은 data-* 로 넘기고 핸들러는
 * addEventListener 로 붙인다.
 *
 * 코어 뷰의 동일 회귀는 코어가 소유한다.
 * @see tests/Unit/Rendering/InlineJavaScriptContextSecurityTest.php
 */
class InlineJavaScriptContextSecurityTest extends TestCase
{
    public function testDynamicLabelsArePassedThroughDataAttributesInsteadOfInlineHandlers(): void
    {
        $paths = [
            '/views/Admin/Order/View.php',
            '/views/Front/Review/basic/List.php',
        ];

        foreach ($paths as $path) {
            $source = file_get_contents(MUBLO_PACKAGE_PATH . '/Shop' . $path);
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
}
