<?php

namespace Tests\Unit\Rendering;

use PHPUnit\Framework\TestCase;
use Mublo\Core\Event\Rendering\FrameTemplateSourceCollectEvent;
use Mublo\Core\Rendering\FrameTemplateRenderer;

/**
 * 프레임 템플릿 렌더러 테스트
 *
 * 계획문서 §3.1(2층 구조)·§3.9(확장 소스 규칙)·§8 테스트 계획의
 * 치환·슬롯·격리 항목을 검증한다.
 */
class FrameTemplateRendererTest extends TestCase
{
    public function testVariableSubstitutionEscapesHtml(): void
    {
        $r = new FrameTemplateRenderer();
        $r->setVariable('site_name', '무블로 <b>&</b>');

        $out = $r->render('<h1>{{site_name}}</h1>');

        $this->assertSame('<h1>무블로 &lt;b&gt;&amp;&lt;/b&gt;</h1>', $out);
    }

    public function testVariableAcceptsWhitespaceInsideBraces(): void
    {
        $r = new FrameTemplateRenderer();
        $r->setVariable('year', '2026');

        $this->assertSame('2026 / 2026', $r->render('{{ year }} / {{year}}'));
    }

    public function testCoreSlotIsInsertedRawWithoutWrapper(): void
    {
        $r = new FrameTemplateRenderer();
        $r->setSlot('menu_main', fn(): string => '<ul class="mublo-menu__list"><li>홈</li></ul>');

        $out = $r->render('<nav>{{menu_main}}</nav>');

        $this->assertSame('<nav><ul class="mublo-menu__list"><li>홈</li></ul></nav>', $out);
        $this->assertStringNotContainsString('frame-slot', $out);
    }

    public function testExtensionSlotIsWrappedWithStandardWrapper(): void
    {
        $r = new FrameTemplateRenderer();
        $event = new FrameTemplateSourceCollectEvent(1);
        $event->addSlot('shop.cart_widget', '미니 장바구니', fn(): string => '<span>3</span>');
        $r->applyCollected($event);

        $out = $r->render('{{shop.cart_widget}}');

        $this->assertSame(
            '<div class="frame-slot frame-slot--shop-cart_widget"><span>3</span></div>',
            $out
        );
    }

    public function testUndefinedTokenIsErasedWithDiagnostic(): void
    {
        $r = new FrameTemplateRenderer();

        $out = $r->render('<p>{{ghost.token}}끝</p>');

        $this->assertSame('<p>끝</p>', $out);
        $diags = $r->getDiagnostics();
        $this->assertCount(1, $diags);
        $this->assertSame('ghost.token', $diags[0]['token']);
        $this->assertSame('undefined', $diags[0]['type']);
    }

    public function testResolverFailureIsIsolatedPerToken(): void
    {
        $r = new FrameTemplateRenderer();
        $r->setVariable('boom', function (): string {
            throw new \RuntimeException('resolver died');
        });
        $r->setVariable('site_name', '무블로');

        $out = $r->render('[{{boom}}][{{site_name}}]');

        $this->assertSame('[][무블로]', $out);
        $diags = $r->getDiagnostics();
        $this->assertCount(1, $diags);
        $this->assertSame('resolver_error', $diags[0]['type']);
    }

    public function testSlotFailureIsIsolatedPerToken(): void
    {
        $r = new FrameTemplateRenderer();
        $r->setSlot('search', function (): string {
            throw new \RuntimeException('slot died');
        });
        $r->setSlot('menu_main', fn(): string => '<ul></ul>');

        $out = $r->render('{{search}}{{menu_main}}');

        $this->assertSame('<ul></ul>', $out);
        $this->assertSame('resolver_error', $r->getDiagnostics()[0]['type']);
    }

    public function testLazyResolutionDoesNotCallUnusedResolvers(): void
    {
        $called = false;
        $r = new FrameTemplateRenderer();
        $r->setVariable('unused', function () use (&$called): string {
            $called = true;
            return 'x';
        });
        $r->setVariable('site_name', '무블로');

        $r->render('{{site_name}}');

        $this->assertFalse($called, '템플릿에 없는 변수의 resolver가 호출되면 안 된다 (지연 해석)');
    }

    public function testExtensionVariableIsEscapedLikeCoreVariable(): void
    {
        $r = new FrameTemplateRenderer();
        $event = new FrameTemplateSourceCollectEvent(1);
        $event->addVariable('shop.cart_count', '장바구니 수', fn(): string => '<script>1</script>');
        $r->applyCollected($event);

        $out = $r->render('{{shop.cart_count}}');

        $this->assertSame('&lt;script&gt;1&lt;/script&gt;', $out);
    }

    public function testCollectedSourceCannotOverrideCoreRegistration(): void
    {
        $r = new FrameTemplateRenderer();
        $r->setVariable('site_name', '코어값');

        $event = new FrameTemplateSourceCollectEvent(1);
        // 이벤트 검증(무접두사 거부)을 우회해 이름이 겹쳤다고 가정해도 코어가 이긴다는
        // 이중 방어 검증 — 정상 등록 가능한 확장 이름으로 코어 슬롯명 선점을 시도
        $r->setSlot('shop.banner', fn(): string => '코어슬롯');
        $event->addSlot('shop.banner', '확장 배너', fn(): string => '확장슬롯');
        $r->applyCollected($event);

        $out = $r->render('{{shop.banner}}');
        $this->assertStringContainsString('코어슬롯', $out);
        $this->assertStringNotContainsString('확장슬롯', $out);
    }

    public function testTokensInsideHtmlCommentsAreNotSubstituted(): void
    {
        $r = new FrameTemplateRenderer();
        $r->setVariable('site_name', '무블로');
        $r->setSlot('mobile_panel', fn(): string => '<aside id="mubloPanel"></aside>');

        $template = "<!--\n    안내: {{mobile_panel}}은 토글과 한 쌍이다.\n    <img src=\"{{logo_url}}\">로 교체\n-->\n<h1>{{site_name}}</h1>\n{{mobile_panel}}";

        $out = $r->render($template);

        $this->assertStringContainsString('{{mobile_panel}}은 토글과 한 쌍이다', $out, '주석 안 토큰은 원문 유지');
        $this->assertStringContainsString('src="{{logo_url}}"', $out, '주석 안 견본 코드는 치환되지 않는다');
        $this->assertStringContainsString('<h1>무블로</h1>', $out, '주석 밖은 정상 치환');
        $this->assertSame(1, substr_count($out, '<aside id="mubloPanel">'), '패널은 주석 밖 1회만 주입');
        $this->assertSame([], $r->getDiagnostics(), '주석 안의 미정의 토큰(logo_url 미등록)은 진단 대상이 아니다');
    }

    public function testUnclosedCommentIsTreatedAsNormalContent(): void
    {
        $r = new FrameTemplateRenderer();
        $r->setVariable('site_name', '무블로');

        $this->assertSame('<!-- 열림 무블로', $r->render('<!-- 열림 {{site_name}}'));
    }

    public function testRegisteredNamesExposedForEditorPalette(): void
    {
        $r = new FrameTemplateRenderer();
        $r->setVariable('site_name', 'x');
        $r->setSlot('menu_main', fn(): string => '');

        $names = $r->getRegisteredNames();

        $this->assertContains('site_name', $names['variables']);
        $this->assertContains('menu_main', $names['slots']);
    }
}
