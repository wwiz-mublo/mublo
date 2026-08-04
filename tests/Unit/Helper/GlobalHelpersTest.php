<?php

declare(strict_types=1);

namespace Tests\Unit\Helper;

use PHPUnit\Framework\TestCase;

/**
 * 전역 헬퍼 함수 테스트 (src/Helper/EnvHelpers.php)
 */
class GlobalHelpersTest extends TestCase
{
    // =========================================================================
    // e() — HTML 출력 이스케이프
    // =========================================================================

    public function testEIsDefined(): void
    {
        $this->assertTrue(function_exists('e'));
    }

    public function testEEscapesHtmlSpecialChars(): void
    {
        $this->assertSame(
            '&lt;script&gt;alert(1)&lt;/script&gt;',
            e('<script>alert(1)</script>')
        );
    }

    public function testEEscapesBothQuoteTypes(): void
    {
        // ENT_HTML5 모드에서 작은따옴표는 &apos; 로 인코딩된다
        $this->assertSame('&quot;a&quot; &apos;b&apos;', e('"a" \'b\''));
    }

    public function testENullReturnsEmptyString(): void
    {
        $this->assertSame('', e(null));
    }

    public function testENonStringableEmitsWarningAndReturnsEmpty(): void
    {
        // 배열이 화면에 'Array' 로 조용히 찍히지 않고, 경고 후 빈 문자열이어야 한다
        $warned = false;
        set_error_handler(function () use (&$warned) {
            $warned = true;
            return true;
        }, E_USER_WARNING);

        try {
            $result = e(['a', 'b']);
        } finally {
            restore_error_handler();
        }

        $this->assertSame('', $result);
        $this->assertTrue($warned, 'E_USER_WARNING 이 발생해야 한다');
    }

    public function testEAcceptsNumbers(): void
    {
        $this->assertSame('42', e(42));
        $this->assertSame('3.5', e(3.5));
    }

    public function testEAcceptsStringable(): void
    {
        $value = new class {
            public function __toString(): string
            {
                return '<b>bold</b>';
            }
        };

        $this->assertSame('&lt;b&gt;bold&lt;/b&gt;', e($value));
    }

    public function testEKeepsPlainKoreanText(): void
    {
        $this->assertSame('안녕하세요', e('안녕하세요'));
    }

    public function testEReplacesInvalidUtf8InsteadOfDroppingOutput(): void
    {
        // ENT_SUBSTITUTE: 잘못된 바이트가 섞여도 출력 전체가 빈 문자열이 되지 않는다
        $result = e("유효\xB1\x31텍스트");

        $this->assertNotSame('', $result);
        $this->assertStringContainsString('유효', $result);
        $this->assertStringContainsString('텍스트', $result);
    }

    public function testEIsIdempotentSafeForAttributes(): void
    {
        $escaped = e('a&b');

        $this->assertSame('a&amp;b', $escaped);
        // 이중 이스케이프 동작 확인 (한 번만 거치는 것이 규칙)
        $this->assertSame('a&amp;amp;b', e($escaped));
    }
}
