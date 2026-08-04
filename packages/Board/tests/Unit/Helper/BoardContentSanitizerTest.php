<?php

namespace Tests\Board\Unit\Helper;

use Mublo\Packages\Board\Helper\BoardContentSanitizer;
use PHPUnit\Framework\TestCase;

/**
 * 게시판 본문(회원·비신뢰 입력) 정화 검증.
 *
 * 본문은 DOM 기반 Core 정화기(HtmlSanitizer::sanitizeEditorContent)를 거쳐야 하며,
 * 신뢰 도메인(YouTube/Vimeo) iframe만 표준 임베드로 보존되어야 한다.
 */
class BoardContentSanitizerTest extends TestCase
{
    public function testStripsScriptTag(): void
    {
        $out = BoardContentSanitizer::sanitize('<p>안녕</p><script>alert(document.cookie)</script>');
        $this->assertStringContainsString('안녕', $out);
        $this->assertStringNotContainsStringIgnoringCase('<script', $out);
    }

    public function testStripsEventHandlerAttribute(): void
    {
        $out = BoardContentSanitizer::sanitize('<p onclick="steal()">hi</p>');
        $this->assertStringContainsString('hi', $out);
        $this->assertStringNotContainsStringIgnoringCase('onclick', $out);
    }

    public function testStripsJavascriptProtocolLink(): void
    {
        $out = BoardContentSanitizer::sanitize('<a href="javascript:alert(1)">x</a>');
        $this->assertStringNotContainsStringIgnoringCase('javascript:', $out);
    }

    public function testPreservesTrustedYoutubeIframeAsStandardEmbed(): void
    {
        $out = BoardContentSanitizer::sanitize(
            '<iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ"></iframe>'
        );
        $this->assertStringContainsString('youtube.com/embed/dQw4w9WgXcQ', $out);
        $this->assertStringContainsString('<iframe', $out);
    }

    public function testRemovesUntrustedIframe(): void
    {
        $out = BoardContentSanitizer::sanitize(
            '<iframe src="https://evil.example.com/phish"></iframe>'
        );
        $this->assertStringNotContainsStringIgnoringCase('evil.example.com', $out);
    }

    public function testKeepsSafeFormatting(): void
    {
        $out = BoardContentSanitizer::sanitize('<p><strong>굵게</strong> 그리고 <em>기울임</em></p>');
        $this->assertStringContainsString('<strong>', $out);
        $this->assertStringContainsString('<em>', $out);
    }

    public function testEmptyInputReturnsEmpty(): void
    {
        $this->assertSame('', BoardContentSanitizer::sanitize('   '));
    }
}
