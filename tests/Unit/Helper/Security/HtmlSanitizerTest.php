<?php

namespace Tests\Unit\Helper\Security;

use Mublo\Helper\Security\HtmlSanitizer;
use PHPUnit\Framework\TestCase;

class HtmlSanitizerTest extends TestCase
{
    public function testEditorContentKeepsFormattingImagesAndTrustedVideo(): void
    {
        $html = '<p><span style="font-size: 18px; color: #ff0000; position: fixed;">Hello</span></p>'
            . '<img src="/storage/D1/editor/temp/a.jpg" alt="A" loading="lazy">'
            . '<iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ" allowfullscreen></iframe>';

        $result = HtmlSanitizer::sanitizeEditorContent($html);

        // 값 보존 (엔진별 공백/정규화 차이에 독립적으로 검증)
        $this->assertMatchesRegularExpression('/font-size\s*:\s*18px/', $result);
        $this->assertMatchesRegularExpression('/color\s*:\s*#ff0000/', $result);
        $this->assertStringNotContainsString('position', $result);
        $this->assertStringContainsString('<img', $result);
        $this->assertStringContainsString('youtube.com/embed/dQw4w9WgXcQ', $result);
    }

    public function testEditorContentRemovesEventHandlersAndUntrustedIframeSrc(): void
    {
        $html = '<p onclick="alert(1)">Text</p>'
            . '<iframe src="https://evil.example/embed/1"></iframe>'
            . '<a href="javascript:alert(1)">bad</a>';

        $result = HtmlSanitizer::sanitizeEditorContent($html);

        $this->assertStringNotContainsString('onclick', $result);
        $this->assertStringNotContainsString('evil.example', $result);
        $this->assertStringNotContainsString('javascript:', $result);
        $this->assertStringContainsString('Text', $result);
    }

    public function testEditorContentKeepsImageCaptionAndSafeLinkRel(): void
    {
        $html = '<figure class="mublo-image">'
            . '<img src="/storage/D1/editor/temp/a.jpg" alt="A">'
            . '<figcaption class="mublo-image-caption">Caption</figcaption>'
            . '</figure>'
            . '<a href="https://example.com" target="_blank" rel="noopener noreferrer">link</a>';

        $result = HtmlSanitizer::sanitizeEditorContent($html);

        $this->assertStringContainsString('<figcaption', $result);
        $this->assertStringContainsString('Caption', $result);
        // _blank 링크에 noopener/noreferrer 부착 (토큰 순서 무관)
        $this->assertStringContainsString('noopener', $result);
        $this->assertStringContainsString('noreferrer', $result);
    }

    public function testStripsScriptAndActiveContentTags(): void
    {
        $html = '<p>safe</p>'
            . '<script>alert(1)</script>'
            . '<object data="x.swf"></object>'
            . '<embed src="x.swf">'
            . '<form action="/steal"><input name="pw"></form>'
            . '<style>body{background:url(javascript:alert(1))}</style>';

        $result = HtmlSanitizer::sanitize($html);

        $this->assertStringContainsString('safe', $result);
        foreach (['<script', '<object', '<embed', '<form', '<input', '<style'] as $tag) {
            $this->assertStringNotContainsStringIgnoringCase($tag, $result);
        }
    }

    public function testStripsDataUriHtmlAndSvg(): void
    {
        $html = '<a href="data:text/html,<script>alert(1)</script>">x</a>'
            . '<img src="data:image/svg+xml;base64,PHN2Zz48c2NyaXB0Pg==">';

        $result = HtmlSanitizer::sanitize($html);

        $this->assertStringNotContainsStringIgnoringCase('data:text/html', $result);
        $this->assertStringNotContainsStringIgnoringCase('data:image/svg', $result);
    }

    public function testStripsObfuscatedJavascriptProtocol(): void
    {
        // 엔티티/제어문자로 난독화한 javascript: 스킴도 차단되어야 한다
        $html = '<a href="java&#9;script:alert(1)">a</a>'
            . '<a href="javascript&#58;alert(1)">b</a>'
            . '<a href="  JaVaScRiPt:alert(1)">c</a>';

        $result = HtmlSanitizer::sanitize($html);

        // 실행 가능한 스킴이 남지 않아야 한다 (href 제거 또는 콜론 인코딩으로 중화)
        $this->assertStringNotContainsStringIgnoringCase('javascript:', $result);
        $this->assertDoesNotMatchRegularExpression('/href\s*=\s*["\']?\s*[a-z]*script\s*:/i', $result);
    }

    public function testBasicProfileStripsAllIframes(): void
    {
        // 폼 필드(basic)는 신뢰 iframe도 허용하지 않는다
        $html = '<p>text</p><iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ"></iframe>';

        $result = HtmlSanitizer::sanitizeBasic($html);

        $this->assertStringContainsString('text', $result);
        $this->assertStringNotContainsStringIgnoringCase('<iframe', $result);
    }

    public function testRichProfileRejectsUntrustedIframeButKeepsYoutube(): void
    {
        $html = '<iframe src="https://evil.example/embed/x"></iframe>'
            . '<iframe src="https://player.vimeo.com/video/12345"></iframe>';

        $result = HtmlSanitizer::sanitize($html);

        $this->assertStringNotContainsStringIgnoringCase('evil.example', $result);
        $this->assertStringContainsString('player.vimeo.com/video/12345', $result);
    }

    public function testEmptyInputReturnsEmpty(): void
    {
        $this->assertSame('', HtmlSanitizer::sanitize(''));
        $this->assertSame('', HtmlSanitizer::sanitizeEditorContent('   '));
        $this->assertSame('', HtmlSanitizer::sanitizeBasic(''));
        $this->assertSame('', HtmlSanitizer::sanitizeForBlock(''));
    }

    // =========================================================================
    // block 프로파일 — 레이아웃 CSS 허용, 스크립트는 여전히 차단
    // =========================================================================

    /**
     * 블록은 관리자가 조판하는 채널이라 레이아웃 CSS 를 살려야 한다.
     * 시더가 만든 기본 화면의 line-height 가 블록 킷 왕복에서 사라지던 문제의 뿌리다.
     *
     * @param string $style 인라인 style 선언
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('blockAllowedStyles')]
    public function testBlockProfileKeepsLayoutCss(string $style, string $expectFragment): void
    {
        $result = HtmlSanitizer::sanitizeForBlock('<div style="' . $style . '">x</div>');

        $this->assertStringContainsString($expectFragment, $result, "block 프로파일이 '{$style}' 를 지웠다");
    }

    /** @return array<string, array{string, string}> */
    public static function blockAllowedStyles(): array
    {
        return [
            'line-height' => ['line-height:1.6', 'line-height:1.6'],
            'letter-spacing' => ['letter-spacing:1px', 'letter-spacing:1px'],
            'display flex' => ['display:flex', 'display:flex'],
            'display grid' => ['display:grid', 'display:grid'],
            'gap' => ['gap:8px 16px', 'gap:8px 16px'],
            'justify-content' => ['justify-content:space-between', 'justify-content:space-between'],
            'align-items' => ['align-items:center', 'align-items:center'],
            'flex shorthand' => ['flex:1 1 200px', 'flex:1 1 200px'],
            'border-radius' => ['border-radius:8px', 'border-radius:8px'],
            'border-radius percent' => ['border-radius:50%', 'border-radius:50%'],
            'box-shadow rgba' => ['box-shadow:0 2px 8px rgba(0,0,0,0.1)', 'box-shadow:0 2px 8px'],
            'box-shadow inset' => ['box-shadow:inset 0 -2px 4px red', 'inset'],
            'overflow' => ['overflow:hidden', 'overflow:hidden'],
            'opacity' => ['opacity:0.9', 'opacity:0.9'],
            'word-break' => ['word-break:keep-all', 'word-break:keep-all'],
        ];
    }

    /**
     * CSS 를 넓혀도 스크립트 채널은 세 프로파일 모두 똑같이 막힌다.
     *
     * @param string $html 공격 입력
     * @param string $mustNotContain 결과에 있어서는 안 되는 조각
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('blockXssVectors')]
    public function testBlockProfileStillBlocksScriptChannels(string $html, string $mustNotContain): void
    {
        $result = HtmlSanitizer::sanitizeForBlock($html);

        $this->assertStringNotContainsStringIgnoringCase($mustNotContain, $result);
    }

    /** @return array<string, array{string, string}> */
    public static function blockXssVectors(): array
    {
        return [
            'onerror' => ['<img src=x onerror="alert(document.cookie)">', 'onerror'],
            'onclick with flex' => ['<div style="display:flex" onclick="steal()">x</div>', 'onclick'],
            'javascript url' => ['<a href="javascript:alert(1)">x</a>', 'javascript:'],
            'script tag' => ['<script>alert(1)</script>', '<script'],
            'svg' => ['<svg onload="alert(1)"></svg>', 'onload'],
        ];
    }

    /**
     * 레이아웃 탈출·외부 리소스 CSS 는 block 에서도 막는다.
     * position:fixed 는 클릭재킹, background:url() 은 방문자 IP 유출 경로다.
     *
     * @param string $style
     * @param string $dangerousToken
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('blockRejectedStyles')]
    public function testBlockProfileRejectsDangerousCss(string $style, string $dangerousToken): void
    {
        $result = HtmlSanitizer::sanitizeForBlock('<div style="' . $style . '">x</div>');

        $this->assertStringNotContainsStringIgnoringCase($dangerousToken, $result);
    }

    /** @return array<string, array{string, string}> */
    public static function blockRejectedStyles(): array
    {
        return [
            'position fixed' => ['position:fixed', 'fixed'],
            'z-index' => ['z-index:9999', 'z-index'],
            'background url' => ['background:url(//evil/track.png)', 'url'],
            'display with bad keyword' => ['display:absolute', 'absolute'],
            'expression' => ['width:expression(alert(1))', 'expression'],
        ];
    }

    /**
     * CSS 를 넓힌 뒤에도 **외부** 리소스 로딩·스크립트 벡터가 새지 않아야 한다.
     *
     * 불변식(#55에서 개정): url() 자체는 同출처 상대 경로에 한해 background 계열에서
     * 허용된다 — 하지만 스킴(javascript:/https:/data:)·프로토콜 상대(//)·비-background
     * 속성의 url() 은 여전히 전부 죽어야 한다. 외부 요청/실행 경로는 절대 열리지 않는다.
     *
     * @param string $html
     * @param string $dangerousToken
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('blockCssResourceVectors')]
    public function testBlockProfileNeverLoadsExternalResourcesViaCss(string $html, string $dangerousToken): void
    {
        $result = HtmlSanitizer::sanitizeForBlock($html);

        $this->assertStringNotContainsStringIgnoringCase(
            $dangerousToken,
            $result,
            "리소스 로딩 벡터가 새어 나왔다: {$html}"
        );
    }

    /** @return array<string, array{string, string}> */
    public static function blockCssResourceVectors(): array
    {
        return [
            'bg-image js url' => ['<div style="background-image:url(javascript:alert(1))">x</div>', 'javascript'],
            'bg-image scheme url' => ['<div style="background-image:url(https://evil/track.png)">x</div>', 'evil'],
            'bg-image protocol-relative' => ['<div style="background-image:url(//evil/track.png)">x</div>', 'evil'],
            'bg-image data uri' => ['<div style="background-image:url(data:image/svg+xml,x)">x</div>', 'data:'],
            'bg-image quoted url' => ['<div style="background-image:url(&quot;/storage/x.png&quot;)">x</div>', 'url'],
            'bg-image dotdot' => ['<div style="background-image:url(/storage/../config/app.php)">x</div>', 'url'],
            'gradient smuggled url' => ['<div style="background:linear-gradient(url(/x), red)">x</div>', 'url'],
            'moz-binding' => ['<div style="-moz-binding:url(//evil/x.xml)">x</div>', 'evil'],
            'behavior' => ['<div style="behavior:url(#default#time2)">x</div>', 'behavior'],
            'content url' => ['<div style="content:url(//evil/track)">x</div>', 'evil'],
            'list-style-image' => ['<div style="list-style-image:url(//evil)">x</div>', 'evil'],
            'background shorthand external' => ['<div style="background:#fff url(//evil/track.png) no-repeat">x</div>', 'evil'],
            'filter url' => ['<div style="filter:url(//evil)">x</div>', 'evil'],
            'style import' => ['<style>@import url(//evil)</style>', 'evil'],
        ];
    }

    /**
     * 同출처 상대 경로 url() 은 block 프로파일의 background 계열에서 허용된다(#55).
     *
     * 사진 히어로·사진 밴드 같은 표현 섹션을 HTML 블록으로 만들기 위한 완화 —
     * 문자셋에서 `:` 를 배제해 스킴이 구조적으로 불가능하므로 외부 요청은 열리지 않는다.
     */
    public function testBlockProfileAllowsSameOriginBackgroundUrl(): void
    {
        $plain = HtmlSanitizer::sanitizeForBlock(
            '<div style="background-image:url(/serve/plugin/Sample/assets/img/hero-city.svg)">x</div>'
        );
        $this->assertStringContainsString('url(/serve/plugin/Sample/assets/img/hero-city.svg)', $plain);

        // 오버레이 그라디언트 + 사진의 다중 레이어 (사진 히어로의 표준 문법)
        $layered = HtmlSanitizer::sanitizeForBlock(
            '<div style="background:linear-gradient(rgba(8,15,28,0.6), rgba(8,15,28,0.2)), url(/storage/D1/hero.jpg)">x</div>'
        );
        $this->assertStringContainsString('url(/storage/D1/hero.jpg)', $layered);
        $this->assertStringContainsString('linear-gradient', $layered);
    }

    /**
     * url() 레이어는 block 한정 완화다. 게시판(rich)은 background 를 받지만
     * 색·그라디언트까지이고, 폼(basic)은 background 자체가 없다.
     * 회원 콘텐츠가 리소스를 참조하는 경로는 열지 않는다.
     */
    public function testSameOriginUrlStaysBlockedInRichAndBasic(): void
    {
        $html = '<div style="background-image:url(/storage/D1/x.png)">x</div>';

        $this->assertStringNotContainsString('url(', HtmlSanitizer::sanitize($html));
        $this->assertStringNotContainsString('url(', HtmlSanitizer::sanitizeBasic($html));

        // 색·그라디언트는 rich 에서도 통과한다
        $gradient = '<div style="background:linear-gradient(135deg,#e7f5ff,#f3f0ff)">x</div>';
        $this->assertStringContainsString('linear-gradient', HtmlSanitizer::sanitize($gradient));
        $this->assertStringNotContainsString('linear-gradient', HtmlSanitizer::sanitizeBasic($gradient));
    }

    /**
     * 폼(basic)은 서식 수준을 유지한다. 에디터가 쓰지 않는 채널이므로
     * 레이아웃 CSS 를 함께 열 이유가 없다.
     */
    public function testBasicProfileStaysNarrow(): void
    {
        $result = HtmlSanitizer::sanitizeBasic('<div style="display:flex; line-height:1.6; border:1px solid #000; color:red;">x</div>');

        $this->assertStringNotContainsString('display:flex', $result, '폼에 flex 가 새면 안 된다');
        $this->assertStringNotContainsString('line-height', $result);
        $this->assertStringNotContainsString('border', $result);
        $this->assertStringContainsString('color:', $result, '기존 서식은 유지되어야 한다');
    }

    // =========================================================================
    // rich 프로파일 — MubloEditor 출력 보존
    // =========================================================================

    /**
     * 에디터가 만든 인용구·카드·레이아웃은 저장 후에도 같은 모습이어야 한다.
     * 이 CSS 가 떨어지면 정화기가 조용히 기능을 깨뜨리는 셈이다.
     *
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('editorStyleProvider')]
    public function testRichProfileKeepsEditorOutputCss(string $style, string $expectFragment): void
    {
        $result = HtmlSanitizer::sanitizeEditorContent('<div style="' . $style . '">x</div>');

        $this->assertStringContainsString($expectFragment, $result);
    }

    /** @return array<string, array{string, string}> */
    public static function editorStyleProvider(): array
    {
        return [
            '인용구 왼쪽 라인' => ['border-left:4px solid #adb5bd;', 'border-left:4px solid #adb5bd'],
            '인용구 배경'     => ['background:#e7f5ff;', 'background:#e7f5ff'],
            '알림박스 테두리' => ['border:1px solid #a5d8ff;', 'border:1px solid #a5d8ff'],
            '둥근 모서리'     => ['border-radius:6px;', 'border-radius:6px'],
            '섀도 카드'       => ['box-shadow:0 4px 12px rgba(0,0,0,.08);', 'box-shadow'],
            '카드 플렉스'     => ['display:flex;gap:16px;align-items:flex-start;', 'display:flex'],
            '레이아웃 비율'   => ['flex:0 0 40%;min-width:0;', 'flex:0 0 40%'],
            '썸네일 크롭'     => ['object-fit:cover;', 'object-fit:cover'],
            '체크리스트 마커' => ['list-style:none;', 'list-style:none'],
            '행간'            => ['line-height:1.6;', 'line-height:1.6'],
        ];
    }

    /** 체크리스트 항목은 체크 상태까지 본문에 남아야 뷰 페이지에서 유지된다. */
    public function testRichProfileKeepsChecklistCheckbox(): void
    {
        $html = '<ul data-mublo-checklist><li><input type="checkbox" checked><span>할 일</span></li></ul>';

        $result = HtmlSanitizer::sanitizeEditorContent($html);

        $this->assertStringContainsString('data-mublo-checklist', $result);
        $this->assertStringContainsString('type="checkbox"', $result);
        $this->assertStringContainsString('checked', $result);
    }

    /**
     * 열어준 것은 체크박스뿐이다. type 이 필수라 다른 input 은 요소째 사라진다 —
     * 본문에 로그인 폼처럼 보이는 입력칸이 생길 여지를 남기지 않는다.
     *
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('nonCheckboxInputProvider')]
    public function testRichProfileDropsNonCheckboxInputs(string $html): void
    {
        $this->assertStringNotContainsString('<input', HtmlSanitizer::sanitizeEditorContent($html));
    }

    /** @return array<string, array{string}> */
    public static function nonCheckboxInputProvider(): array
    {
        return [
            '텍스트'   => ['<input type="text" name="id">'],
            '비밀번호' => ['<input type="password" name="pw">'],
            '숨김'     => ['<input type="hidden" name="csrf" value="x">'],
            '타입없음' => ['<input>'],
        ];
    }

    /** 마커는 지정한 요소에서만 살아남고, 값이 정해진 것은 값까지 검사한다. */
    public function testRichProfileKeepsEditorMarkersOnlyWhereDefined(): void
    {
        $kept = HtmlSanitizer::sanitizeEditorContent(
            '<nav data-mublo-toc><ul><li>목차</li></ul></nav>'
            . '<figure data-mublo-card="og">카드</figure>'
            . '<figure data-mublo-layout="img-left">레이아웃</figure>'
        );
        $this->assertStringContainsString('data-mublo-toc', $kept);
        $this->assertStringContainsString('data-mublo-card="og"', $kept);
        $this->assertStringContainsString('data-mublo-layout="img-left"', $kept);

        // 다른 요소에 옮겨 붙인 마커 · 목록에 없는 값 · 임의 data-* 는 탈락
        $dropped = HtmlSanitizer::sanitizeEditorContent(
            '<div data-mublo-toc data-mublo-card="og">x</div>'
            . '<figure data-mublo-card="evil" data-evil="1">y</figure>'
        );
        $this->assertStringNotContainsString('data-mublo-toc', $dropped);
        $this->assertStringNotContainsString('data-mublo-card', $dropped);
        $this->assertStringNotContainsString('data-evil', $dropped);
    }

    /**
     * 넓힌 것은 레이아웃까지다. 겹치기(클릭재킹) 채널은 rich 에서도 닫혀 있다.
     *
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('overlayCssProvider')]
    public function testRichProfileStillBlocksOverlayCss(string $style, string $mustNotContain): void
    {
        $result = HtmlSanitizer::sanitizeEditorContent('<div style="' . $style . '">x</div>');

        $this->assertStringNotContainsString($mustNotContain, $result);
    }

    /** @return array<string, array{string, string}> */
    public static function overlayCssProvider(): array
    {
        return [
            'absolute'    => ['position:absolute;top:0;left:0;', 'position'],
            'fixed'       => ['position:fixed;inset:0;', 'position'],
            'z-index'     => ['z-index:9999;', 'z-index'],
            'transform'   => ['transform:translate(-50%,-50%);', 'transform'],
            'list 이미지' => ['list-style:url(https://evil.example/x.png);', 'url('],
        ];
    }

    // =========================================================================
    // block 프로파일 — MubloItemLayout 레이아웃 data-* 화이트리스트
    // =========================================================================

    /**
     * 직접입력 HTML 로 mublo-item-layout 을 조판할 수 있어야 한다 —
     * 공식 레이아웃 속성 10종은 유효값과 함께 block 프로파일을 통과한다.
     */
    public function testBlockProfileKeepsMubloLayoutDataAttributes(): void
    {
        $html = '<div class="mublo-item-layout"'
            . ' data-pc-style="list" data-mo-style="slide"'
            . ' data-pc-cols="4" data-mo-cols="auto"'
            . ' data-pc-loop="true" data-mo-loop="false"'
            . ' data-pc-slide-cover="true" data-mo-slide-cover="false"'
            . ' data-pc-autoplay="3000" data-mo-autoplay="5000">'
            . '<ul><li>a</li><li>b</li></ul></div>';

        $result = HtmlSanitizer::sanitizeForBlock($html);

        foreach ([
            'data-pc-style="list"', 'data-mo-style="slide"',
            'data-pc-cols="4"', 'data-mo-cols="auto"',
            'data-pc-loop="true"', 'data-mo-loop="false"',
            'data-pc-slide-cover="true"', 'data-mo-slide-cover="false"',
            'data-pc-autoplay="3000"', 'data-mo-autoplay="5000"',
        ] as $attr) {
            $this->assertStringContainsString($attr, $result, "block 프로파일이 공식 속성 {$attr} 을 지웠다");
        }
        $this->assertStringContainsString('<ul>', $result);
        $this->assertStringContainsString('<li>a</li>', $result);
    }

    /**
     * 값 검증 — 허용 목록 밖의 값은 속성째 탈락하고 요소는 남는다.
     * Number(HTMLPurifier)는 양의 정수만 통과한다: 0·음수·단위는 탈락하며,
     * autoplay 0 의 탈락은 "속성 생략 = 자동재생 없음" 계약과 동작이 같다.
     *
     * @param string $attr 속성 선언
     * @param string $mustNotContain 결과에 남으면 안 되는 조각
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('blockInvalidLayoutDataValues')]
    public function testBlockProfileDropsInvalidLayoutDataValues(string $attr, string $mustNotContain): void
    {
        $result = HtmlSanitizer::sanitizeForBlock('<div ' . $attr . '>x</div>');

        $this->assertStringNotContainsString($mustNotContain, $result);
        $this->assertStringContainsString('x', $result, '값이 나빠도 요소는 남아야 한다');
    }

    /** @return array<string, array{string, string}> */
    public static function blockInvalidLayoutDataValues(): array
    {
        return [
            'style unknown' => ['data-pc-style="hack"', 'data-pc-style'],
            'cols too big' => ['data-pc-cols="13"', 'data-pc-cols'],
            'cols zero' => ['data-mo-cols="0"', 'data-mo-cols'],
            'autoplay with unit' => ['data-pc-autoplay="3s"', 'data-pc-autoplay'],
            'autoplay zero' => ['data-pc-autoplay="0"', 'data-pc-autoplay'],
            'autoplay negative' => ['data-mo-autoplay="-1"', 'data-mo-autoplay'],
            'loop arbitrary' => ['data-pc-loop="yes"', 'data-pc-loop'],
        ];
    }

    /**
     * 화이트리스트 밖 데이터 속성은 계속 죽는다 — data-swiper(자유 JSON 통로)와
     * 타 라이브러리(data-bs-*) 속성이 무규정 채널이 되면 안 된다.
     */
    public function testBlockProfileStillDropsUnlistedDataAttributes(): void
    {
        $html = '<div class="mublo-item-layout" data-swiper=\'{"loop":true}\''
            . ' data-bs-toggle="modal" data-bs-target="#x" data-anything="1">x</div>';

        $result = HtmlSanitizer::sanitizeForBlock($html);

        $this->assertStringNotContainsString('data-swiper', $result);
        $this->assertStringNotContainsString('data-bs-toggle', $result);
        $this->assertStringNotContainsString('data-bs-target', $result);
        $this->assertStringNotContainsString('data-anything', $result);
        $this->assertStringContainsString('mublo-item-layout', $result, 'class 는 유지');
    }

    /** 허용은 div 한정 — 다른 요소에 얹은 공식 속성은 탈락한다. */
    public function testLayoutDataAttributesAreDivOnly(): void
    {
        $result = HtmlSanitizer::sanitizeForBlock('<span data-pc-style="slide">x</span>');

        $this->assertStringNotContainsString('data-pc-style', $result);
        $this->assertStringContainsString('x', $result);
    }

    /**
     * 프로파일 격리 — rich(게시판)·basic(폼)에서는 공식 속성도 전부 사라진다.
     * 레이아웃 조판은 블록 채널의 권한이지 일반 회원 채널의 권한이 아니다.
     */
    public function testLayoutDataAttributesStayBlockedInRichAndBasic(): void
    {
        $html = '<div data-pc-style="slide" data-mo-style="slide" data-pc-cols="4"'
            . ' data-mo-cols="2" data-pc-loop="true" data-mo-loop="true"'
            . ' data-pc-slide-cover="true" data-mo-slide-cover="true"'
            . ' data-pc-autoplay="3000" data-mo-autoplay="3000">x</div>';

        foreach (['rich' => HtmlSanitizer::sanitize($html), 'basic' => HtmlSanitizer::sanitizeBasic($html)] as $profile => $result) {
            $this->assertStringNotContainsString('data-', $result, "{$profile} 프로파일에 레이아웃 data-* 가 새면 안 된다");
            $this->assertStringContainsString('x', $result);
        }
    }

    /** 화이트리스트 속성과 스크립트 채널이 한 요소에 있어도 각자 규칙대로 처리된다. */
    public function testLayoutDataAttributesSurviveWhileScriptChannelsDie(): void
    {
        $html = '<div data-mo-style="slide" onclick="steal()" onmouseover="x()">x</div>';

        $result = HtmlSanitizer::sanitizeForBlock($html);

        $this->assertStringContainsString('data-mo-style="slide"', $result);
        $this->assertStringNotContainsString('onclick', $result);
        $this->assertStringNotContainsString('onmouseover', $result);
    }
}
