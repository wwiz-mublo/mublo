<?php
namespace Tests\Unit\Service\AI;

use Mublo\Service\AI\AiHtmlSanitizer;
use PHPUnit\Framework\TestCase;

class AiHtmlSanitizerTest extends TestCase
{
    public function testKeepsEditableMarkupAndLocalLinks(): void
    {
        $html = (new AiHtmlSanitizer())->sanitize('<section class="hero"><h2>제목</h2><a href="/about">소개</a></section>');
        $this->assertStringContainsString('<section class="hero">', $html);
        $this->assertStringContainsString('href="/about"', $html);
    }

    public function testRemovesActiveExternalAndCollisionProneMarkup(): void
    {
        $html = (new AiHtmlSanitizer())->sanitize(
            '<div id="global" data-x="1" onclick="evil()"><img src="https://evil.test/x">'
            . '<a href="https://evil.test" target="_blank">외부</a><script>alert(1)</script></div>'
        );
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('id=', $html);
        $this->assertStringNotContainsString('data-x', $html);
        $this->assertStringNotContainsString('href=', $html);
        $this->assertStringContainsString('외부', $html);
    }

    public function testKeepsTrustedTriggerAsInertEditableMarkup(): void
    {
        $html = (new AiHtmlSanitizer())->sanitize(
            '<span class="mublo-tab" onclick="evil()">탭</span>'
        );

        $this->assertStringContainsString('<span', $html);
        $this->assertStringContainsString('class="mublo-tab"', $html);
        $this->assertStringNotContainsString('onclick', $html);
    }
}
