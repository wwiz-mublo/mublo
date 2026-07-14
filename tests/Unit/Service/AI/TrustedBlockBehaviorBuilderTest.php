<?php
namespace Tests\Unit\Service\AI;

use Mublo\Service\AI\TrustedBlockBehaviorBuilder;
use PHPUnit\Framework\TestCase;

class TrustedBlockBehaviorBuilderTest extends TestCase
{
    public function testNoBehaviorsDoNotGenerateAssets(): void
    {
        $this->assertSame(
            ['css' => '', 'js' => '', 'warnings' => []],
            (new TrustedBlockBehaviorBuilder())->build(['types' => [], 'autoplay_seconds' => 0], 'scope', '<div></div>')
        );
    }

    public function testSliderGeneratesGlobalSwiperAdapterCall(): void
    {
        $html = '<section class="mublo-slider"><div class="mublo-slider-track">'
            . '<article class="mublo-slide">A</article><article class="mublo-slide">B</article></div></section>';
        $assets = (new TrustedBlockBehaviorBuilder())->build(
            ['types' => ['slider'], 'autoplay_seconds' => 5, 'slider_preset' => 'cards'],
            'mublo-block-scope',
            $html
        );
        // fallback CSS는 전역 Swiper 강화 전(is-enhanced 부재)에만 적용된다 (개선 계획 §6.4)
        $this->assertStringContainsString('.mublo-slider:not(.is-enhanced) .mublo-slider-track', $assets['css']);
        $this->assertStringContainsString("document.querySelector(\".mublo-block-scope\")", $assets['js']);
        $this->assertStringContainsString('MubloSlider.init', $assets['js']);
        $this->assertStringContainsString('preset: "cards"', $assets['js']);
        $this->assertStringContainsString('autoplaySeconds: 5', $assets['js']);
        // 독자적인 타이머·scrollTo 슬라이더 구현은 더 이상 생성하지 않는다
        $this->assertStringNotContainsString('setInterval', $assets['js']);
        $this->assertSame([], $assets['warnings']);
    }

    public function testSliderWithoutAutoplayStillInitializesAdapterAndInvalidPresetFallsBackToHero(): void
    {
        $html = '<section class="mublo-slider"><div class="mublo-slider-track">'
            . '<article class="mublo-slide">A</article><article class="mublo-slide">B</article></div></section>';
        $assets = (new TrustedBlockBehaviorBuilder())->build(
            ['types' => ['slider'], 'autoplay_seconds' => 0, 'slider_preset' => 'free-form-옵션'],
            'scope',
            $html
        );
        $this->assertStringContainsString('MubloSlider.init', $assets['js']);
        $this->assertStringContainsString('preset: "hero"', $assets['js']);
        $this->assertStringContainsString('autoplaySeconds: 0', $assets['js']);
    }

    public function testInvalidSliderIsSkippedWithWarning(): void
    {
        $assets = (new TrustedBlockBehaviorBuilder())->build(
            ['types' => ['slider'], 'autoplay_seconds' => 0],
            'scope',
            '<div class="mublo-slider"><div class="mublo-slider-track"><div class="mublo-slide">A</div></div></div>'
        );
        $this->assertSame('', $assets['js']);
        $this->assertNotEmpty($assets['warnings']);
        $this->assertStringContainsString('슬라이드 구조', $assets['warnings'][0]);
    }

    public function testTabsGenerateKeyboardAccessibleScopedBehavior(): void
    {
        $html = '<section class="mublo-tabs">'
            . '<div class="mublo-tab-list"><span class="mublo-tab">A</span>'
            . '<span class="mublo-tab">B</span></div>'
            . '<div class="mublo-tab-panels"><article class="mublo-tab-panel">첫째</article>'
            . '<article class="mublo-tab-panel">둘째</article></div></section>';

        $assets = (new TrustedBlockBehaviorBuilder())->build(
            ['types' => ['tabs'], 'autoplay_seconds' => 0],
            'mublo-block-tabs',
            $html
        );

        $this->assertStringContainsString('.mublo-tabs.is-enhanced .mublo-tab-panel.is-active', $assets['css']);
        $this->assertStringContainsString("document.querySelector(\".mublo-block-tabs\")", $assets['js']);
        $this->assertStringContainsString("event.key === 'ArrowRight'", $assets['js']);
        $this->assertStringContainsString("setAttribute('role', 'tab')", $assets['js']);
        $this->assertSame([], $assets['warnings']);
    }

    public function testTabsWithMismatchedPanelsAreSkipped(): void
    {
        $html = '<div class="mublo-tabs"><div class="mublo-tab-list">'
            . '<span class="mublo-tab">A</span><span class="mublo-tab">B</span></div>'
            . '<div class="mublo-tab-panels"><div class="mublo-tab-panel">A</div></div></div>';

        $assets = (new TrustedBlockBehaviorBuilder())->build(
            ['types' => ['tabs'], 'autoplay_seconds' => 0],
            'scope',
            $html
        );

        $this->assertSame('', $assets['js']);
        $this->assertStringContainsString('탭 구조', $assets['warnings'][0]);
    }

    public function testAccordionGeneratesTrustedToggleBehavior(): void
    {
        $html = '<div class="mublo-accordion"><article class="mublo-accordion-item">'
            . '<span class="mublo-accordion-trigger">질문</span>'
            . '<div class="mublo-accordion-panel"><p>답변</p></div>'
            . '</article></div>';

        $assets = (new TrustedBlockBehaviorBuilder())->build(
            ['types' => ['accordion'], 'autoplay_seconds' => 0],
            'mublo-block-accordion',
            $html
        );

        $this->assertStringContainsString('.mublo-accordion.is-enhanced .mublo-accordion-panel.is-open', $assets['css']);
        $this->assertStringContainsString("setAttribute('aria-expanded', 'false')", $assets['js']);
        $this->assertStringContainsString("setAttribute('role', 'button')", $assets['js']);
        $this->assertStringContainsString("document.querySelector(\".mublo-block-accordion\")", $assets['js']);
        $this->assertSame([], $assets['warnings']);
    }

    public function testMultipleTrustedBehaviorsCanBeCombined(): void
    {
        $html = '<div class="mublo-tabs"><div class="mublo-tab-list">'
            . '<span class="mublo-tab">A</span><span class="mublo-tab">B</span></div>'
            . '<div class="mublo-tab-panels"><div class="mublo-tab-panel">A</div><div class="mublo-tab-panel">B</div></div></div>'
            . '<div class="mublo-accordion"><div class="mublo-accordion-item">'
            . '<span class="mublo-accordion-trigger">Q</span><div class="mublo-accordion-panel">A</div>'
            . '</div></div>';

        $assets = (new TrustedBlockBehaviorBuilder())->build(
            ['types' => ['tabs', 'accordion'], 'autoplay_seconds' => 0],
            'scope',
            $html
        );

        $this->assertSame(1, substr_count($assets['js'], 'mublo-block-behavior:start'));
        $this->assertStringContainsString('.mublo-tabs', $assets['js']);
        $this->assertStringContainsString('.mublo-accordion', $assets['js']);
        $this->assertSame([], $assets['warnings']);
    }

    public function testInvalidBehaviorDoesNotDiscardAnotherValidBehavior(): void
    {
        $html = '<div class="mublo-tabs"><div class="mublo-tab-list"><span class="mublo-tab">A</span></div></div>'
            . '<div class="mublo-accordion"><div class="mublo-accordion-item">'
            . '<span class="mublo-accordion-trigger">Q</span><div class="mublo-accordion-panel">A</div>'
            . '</div></div>';

        $assets = (new TrustedBlockBehaviorBuilder())->build(
            ['types' => ['tabs', 'accordion'], 'autoplay_seconds' => 0],
            'scope',
            $html
        );

        $this->assertStringContainsString('.mublo-accordion', $assets['js']);
        $this->assertStringNotContainsString("querySelectorAll('.mublo-tabs')", $assets['js']);
        $this->assertStringContainsString('탭 구조', $assets['warnings'][0]);
    }

    public function testGeneratedBehaviorIsReplacedWithoutRemovingManualJs(): void
    {
        $current = "console.log('manual');\n/* mublo-ai-behavior:start */old/* mublo-ai-behavior:end */";
        $merged = (new TrustedBlockBehaviorBuilder())->mergeJs($current, 'new trusted behavior');
        $this->assertStringContainsString("console.log('manual')", $merged);
        $this->assertStringNotContainsString('old', $merged);
        $this->assertStringContainsString('new trusted behavior', $merged);
        $this->assertStringNotContainsString('mublo-ai-behavior', $merged);
    }
}
