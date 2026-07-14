<?php

namespace Tests\Unit\Helper\View;

use Mublo\Core\Context\Context;
use Mublo\Core\Http\Request;
use Mublo\Entity\Domain\Domain;
use Mublo\Helper\View\FrontPreviewTokensHelper;
use PHPUnit\Framework\TestCase;

/**
 * 프리뷰 토큰 조립 계약
 *
 * 블록 에디터·미리보기 화면이 이 값을 window.MubloFrontPreviewTokens 로 노출하고
 * front-preview-tokens.js 가 읽는다. 값이 비면 캔버스 색이 조용히 기본값으로
 * 돌아가므로(에러 없음), 조립 규칙을 테스트로 고정한다.
 */
class FrontPreviewTokensHelperTest extends TestCase
{
    private function contextWith(array $siteConfig, array $themeConfig): Context
    {
        $context = new Context(new Request('GET', '/admin/block-row'));
        $context->setDomainInfo(new Domain(
            domainId: 7,
            domain: 'example.test',
            siteConfig: $siteConfig,
            themeConfig: $themeConfig,
        ));

        return $context;
    }

    public function testPrimaryColorComesFromDomainSiteConfig(): void
    {
        $tokens = FrontPreviewTokensHelper::forContext(
            $this->contextWith(['primary_color' => '#0e9478'], [])
        );

        $this->assertSame('#0e9478', $tokens['primaryColor']);
    }

    public function testFrameCssUrlUsesThemeConfigFrameSkin(): void
    {
        $tokens = FrontPreviewTokensHelper::forContext(
            $this->contextWith([], ['frame' => 'basic'])
        );

        $this->assertStringStartsWith('/serve/front/basic/css/front.css', $tokens['frameCssUrl']);
    }

    /**
     * 스킨 미설정/빈 값은 basic 으로 폴백 — 프론트 frame 해석과 같은 기본값.
     */
    public function testFrameSkinFallsBackToBasic(): void
    {
        foreach ([[], ['frame' => '']] as $themeConfig) {
            $tokens = FrontPreviewTokensHelper::forContext($this->contextWith([], $themeConfig));
            $this->assertStringStartsWith('/serve/front/basic/css/front.css', $tokens['frameCssUrl']);
        }
    }

    /**
     * 존재하는 스킨은 filemtime 캐시버스터가 붙는다 — 스킨 CSS 를 고쳐도
     * 프리뷰가 옛 변수값을 계속 쓰지 않게 하는 장치.
     */
    public function testExistingSkinCssGetsCacheBuster(): void
    {
        $tokens = FrontPreviewTokensHelper::forContext($this->contextWith([], ['frame' => 'basic']));

        $this->assertMatchesRegularExpression('#^/serve/front/basic/css/front\.css\?\d+$#', $tokens['frameCssUrl']);
    }

    public function testMissingOrPollutedSkinFallsBackToBasic(): void
    {
        foreach (['no-such-skin', '../basic', '..\\basic'] as $skin) {
            $tokens = FrontPreviewTokensHelper::forContext(
                $this->contextWith([], ['frame' => $skin])
            );

            $this->assertStringStartsWith('/serve/front/basic/css/front.css', $tokens['frameCssUrl']);
        }
    }

    public function testDomainlessContextStillYieldsUsableShape(): void
    {
        $context = new Context(new Request('GET', '/admin/block-row'));

        $tokens = FrontPreviewTokensHelper::forContext($context);

        $this->assertSame('', $tokens['primaryColor']);
        $this->assertStringStartsWith('/serve/front/basic/css/front.css', $tokens['frameCssUrl']);
    }
}
