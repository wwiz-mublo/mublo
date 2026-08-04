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

    /**
     * 레이아웃 data-* 는 block 프로파일(직접입력)에서만 열린다 — AI 경로에서는
     * 공식 속성이라도 전부 제거된다. 지금은 1차 패스(sanitizeBasic)가 이미 지우지만,
     * 1차 패스가 꺼진 구성(useBasicPass=false)이나 내부 정화 순서가 바뀌어도
     * str_starts_with('data-') 제거가 최후 방어선으로 남는지 고정한다.
     */
    public function testRemovesMubloLayoutDataAttributesEvenWithoutBasicPass(): void
    {
        $input = '<div class="mublo-item-layout"'
            . ' data-pc-style="slide" data-mo-style="slide" data-pc-cols="4" data-mo-cols="2"'
            . ' data-pc-loop="true" data-mo-loop="true" data-pc-slide-cover="true"'
            . ' data-mo-slide-cover="true" data-pc-autoplay="3000" data-mo-autoplay="3000">레이아웃</div>';

        // 기본 구성(1차 패스 켜짐)과 프레임형 구성(1차 패스 꺼짐) 모두에서 제거돼야 한다.
        $default = (new AiHtmlSanitizer())->sanitize($input);
        $noBasicPass = (new AiHtmlSanitizer(useBasicPass: false, stripInlineStyles: true))->sanitize($input);

        foreach (['기본' => $default, '1차 패스 생략' => $noBasicPass] as $label => $html) {
            $this->assertStringNotContainsString('data-pc-style', $html, "{$label}: 레이아웃 속성이 AI 경로에 새면 안 된다");
            $this->assertStringNotContainsString('data-mo-', $html, $label);
            $this->assertStringNotContainsString('data-pc-autoplay', $html, $label);
            $this->assertStringContainsString('레이아웃', $html, $label);
        }
    }
}
