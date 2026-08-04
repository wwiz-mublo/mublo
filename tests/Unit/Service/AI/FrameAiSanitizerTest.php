<?php

namespace Tests\Unit\Service\AI;

use PHPUnit\Framework\TestCase;
use Mublo\Service\AI\AiHtmlSanitizer;
use Mublo\Service\AI\FrameAiPolicy;

/**
 * 프레임 AI 정책 새니타이저 테스트 (계획문서 §3.4)
 *
 * 블록 정책 대비 허용 확대(img·button·target=_blank·id 화이트리스트)와
 * 계속 불허 항목, 템플릿 토큰 보존을 검증한다.
 */
class FrameAiSanitizerTest extends TestCase
{
    private AiHtmlSanitizer $sanitizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sanitizer = FrameAiPolicy::sanitizer();
    }

    public function testStorageImageIsKeptExternalImageIsStripped(): void
    {
        $out = $this->sanitizer->sanitize(
            '<img src="/storage/site/logo.png" alt="로고"><img src="https://evil.test/x.png" alt="외부">'
        );

        $this->assertStringContainsString('src="/storage/site/logo.png"', $out);
        $this->assertStringNotContainsString('evil.test', $out);
    }

    public function testTemplateTokenImageSrcIsPreserved(): void
    {
        $out = $this->sanitizer->sanitize('<img src="{{logo_url}}" alt="{{site_name}}">');

        $this->assertStringContainsString('src="{{logo_url}}"', $out, '순수 토큰 src는 치환 전 단계 보존');
    }

    public function testTargetBlankKeptWithForcedNoopener(): void
    {
        $out = $this->sanitizer->sanitize('<a href="/notice" target="_blank" rel="nofollow">공지</a>');

        $this->assertStringContainsString('target="_blank"', $out);
        $this->assertStringContainsString('rel="noopener noreferrer"', $out, 'rel은 세트로 강제된다');
    }

    public function testAllowedIdWhitelistOnly(): void
    {
        $out = $this->sanitizer->sanitize(
            '<button id="mubloPanelToggle" class="t"></button><div id="hijack"></div>'
        );

        $this->assertStringContainsString('id="mubloPanelToggle"', $out, 'front.js 훅 id는 보존');
        $this->assertStringNotContainsString('id="hijack"', $out);
    }

    public function testFrameElementsAllowedDangerousElementsRemoved(): void
    {
        $out = $this->sanitizer->sanitize(
            '<address>주소</address><i class="bi bi-bell"></i>'
            . '<script>alert(1)</script><svg onload="x()"></svg><form action="/x"><input></form>'
        );

        $this->assertStringContainsString('<address>주소</address>', $out);
        $this->assertStringContainsString('bi bi-bell', $out);
        $this->assertStringNotContainsString('<script', $out);
        $this->assertStringNotContainsString('<svg', $out);
        $this->assertStringNotContainsString('<form', $out);
    }

    public function testTemplateTokensInTextArePreserved(): void
    {
        $out = $this->sanitizer->sanitize('<nav class="my-nav">{{menu_main}}</nav><div>{{shop.cart_count}}</div>');

        $this->assertStringContainsString('{{menu_main}}', $out);
        $this->assertStringContainsString('{{shop.cart_count}}', $out);
    }

    public function testInlineStylesAreStrippedInFrameMode(): void
    {
        // 프레임 모드는 HTMLPurifier 1차 패스를 생략하므로 인라인 스타일 채널을 직접 막는다
        $out = $this->sanitizer->sanitize('<div class="x" style="position:fixed;inset:0">내용</div>');

        $this->assertStringNotContainsString('style=', $out);
        $this->assertStringContainsString('내용', $out);
    }

    /**
     * 공식 시드는 AI 정화기를 통과해도 구조가 살아남아야 한다.
     *
     * 회귀 배경: 시드에 정적 검색 폼(form/input/svg)을 인라인했을 때, AI 수정
     * 모드가 현재 HTML을 그대로 반환해도 정화 단계에서 검색 영역이 통째로
     * 사라졌다. "헤더 색상만 바꿔줘" 같은 요청에도 구조가 증발하는 문제 —
     * 시드는 AI 정책이 제거하는 요소를 포함하면 안 된다.
     */
    public function testOfficialSeedsSurviveAiSanitizer(): void
    {
        $expectations = [
            'Header' => ['{{menu_main}}', '{{search}}', '{{login_area}}', '{{menu_utility}}',
                '{{mobile_panel}}', 'id="mubloPanelToggle"', '{{site_name}}'],
            'Footer' => ['{{menu_footer}}', '{{sns}}', '{{business_info}}', '{{cs_info}}',
                '{{theme_switch}}', '{{year}}', '{{site_name}}'],
        ];

        foreach ($expectations as $part => $mustSurvive) {
            $seed = (string) file_get_contents(MUBLO_VIEW_PATH . "/Front/frame/basic/{$part}.seed.html");
            $this->assertNotSame('', $seed);

            $out = $this->sanitizer->sanitize($seed);

            foreach ($mustSurvive as $needle) {
                $this->assertStringContainsString(
                    $needle,
                    $out,
                    "{$part} 시드의 '{$needle}'가 AI 정화 후에도 살아남아야 한다"
                );
            }
        }
    }

    public function testDefaultConstructionAppliesBlockPolicy(): void
    {
        // P2부터 블록 정책도 /storage/ 이미지·아이콘을 허용한다. button·외부 이미지는 계속 불허.
        $blockSanitizer = new AiHtmlSanitizer();
        $out = $blockSanitizer->sanitize(
            '<img src="/storage/x.png" alt="a"><img src="https://evil.test/x.png" alt="b">'
            . '<i class="bi bi-star"></i><button id="mubloPanelToggle">x</button>'
        );

        $this->assertStringContainsString('src="/storage/x.png"', $out, 'P2: 블록도 /storage/ 이미지 허용');
        $this->assertStringNotContainsString('evil.test', $out, '외부 이미지는 계속 차단');
        $this->assertStringContainsString('bi bi-star', $out, 'P2: 블록도 아이콘 허용');
        $this->assertStringNotContainsString('<button', $out, '블록 정책은 button 불허 그대로');
        $this->assertStringNotContainsString('mubloPanelToggle', $out, '블록 정책은 id 화이트리스트 없음');
    }
}
