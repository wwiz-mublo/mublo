<?php
namespace Tests\Unit\Service\AI;

use Mublo\Service\AI\ResponsiveCssAuditor;
use PHPUnit\Framework\TestCase;

/**
 * 반응형 정적 검사 (개선 계획 §8) — 보수적 휴리스틱:
 * 명백한 파손 가능성은 error, 개선 권고는 warning, 문제없으면 pass.
 */
class ResponsiveCssAuditorTest extends TestCase
{
    private ResponsiveCssAuditor $auditor;

    protected function setUp(): void
    {
        $this->auditor = new ResponsiveCssAuditor();
    }

    private function codes(array $findings): array
    {
        return array_column($findings, 'code');
    }

    public function testFluidResponsiveCssPasses(): void
    {
        $css = <<<CSS
.scope .card-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1.5rem; }
.scope .card { padding: 1.25rem; max-width: 100%; }
.scope .card-title { font-size: clamp(1.2rem, 3.5cqi, 2rem); }
@media (min-width: 768px) {
.scope .card { padding: 2rem; }
}
CSS;
        $result = $this->auditor->audit('<div><p>내용</p></div>', $css, 'scope');

        $this->assertSame('pass', $result['status']);
        $this->assertSame([], $result['errors']);
        $this->assertSame([], $result['warnings']);
        $this->assertNotEmpty($result['checks']);
    }

    public function testFixedWidthBeyondMobileIsErrorAndModerateIsWarning(): void
    {
        $result = $this->auditor->audit('<div></div>', '.scope .wide { width: 640px; } .scope .mid { width: 320px; }', 'scope');

        $this->assertSame('needs_fix', $result['status']);
        $this->assertContains('fixed-width', $this->codes($result['errors']));
        $this->assertContains('fixed-width', $this->codes($result['warnings']));
        // 메시지에는 스코프가 걷힌 선택자와 문제 폭이 담긴다 (§9.3 원인 표시)
        $this->assertStringContainsString('.wide', $result['errors'][0]['message']);
        $this->assertStringContainsString('640', $result['errors'][0]['message']);
    }

    public function testFluidMaxWidthExemptsFixedWidth(): void
    {
        $result = $this->auditor->audit('<div></div>', '.scope .box { width: 640px; max-width: 100%; }', 'scope');
        $this->assertSame('pass', $result['status']);
    }

    public function testFixedHeightWithOverflowHiddenIsClippedTextError(): void
    {
        $css = '.scope .teaser { height: 120px; overflow: hidden; } .scope .banner { height: 300px; }';
        $result = $this->auditor->audit('<div></div>', $css, 'scope');

        $this->assertContains('clipped-text', $this->codes($result['errors']));
        $this->assertContains('fixed-height', $this->codes($result['warnings']));
    }

    public function testPxFontSizeAndLargeSpacingAndMinWidthAreWarnings(): void
    {
        $css = '.scope .lead { font-size: 18px; padding: 80px; min-width: 480px; }';
        $result = $this->auditor->audit('<div></div>', $css, 'scope');

        $this->assertSame('warning', $result['status']);
        $codes = $this->codes($result['warnings']);
        $this->assertContains('px-font-size', $codes);
        $this->assertContains('large-spacing', $codes);
        $this->assertContains('min-width', $codes);
    }

    public function testViewportFontSizeWarnsButContainerRelativeFontSizePasses(): void
    {
        $viewport = $this->auditor->audit(
            '<section><h2>제목</h2></section>',
            '.scope .display { font-size: clamp(2rem, 6vw, 5rem); }',
            'scope'
        );
        $this->assertContains('viewport-font-size', $this->codes($viewport['warnings']));

        $container = $this->auditor->audit(
            '<section><h2>제목</h2></section>',
            '.scope .display { font-size: clamp(2rem, 6cqi, 5rem); }',
            'scope'
        );
        $this->assertNotContains('viewport-font-size', $this->codes($container['warnings']));
        $this->assertSame('pass', $container['status']);
    }

    public function testFlexRowWithoutWrapWarnsUnlessMediaAdjustsIt(): void
    {
        $bare = $this->auditor->audit('<div></div>', '.scope .row { display: flex; gap: 1rem; }', 'scope');
        $this->assertContains('flex-no-wrap', $this->codes($bare['warnings']));

        $wrapped = $this->auditor->audit('<div></div>', '.scope .row { display: flex; flex-wrap: wrap; }', 'scope');
        $this->assertNotContains('flex-no-wrap', $this->codes($wrapped['warnings']));

        $column = $this->auditor->audit('<div></div>', '.scope .row { display: flex; flex-direction: column; }', 'scope');
        $this->assertNotContains('flex-no-wrap', $this->codes($column['warnings']));

        $mediaAdjusted = $this->auditor->audit(
            '<div></div>',
            '.scope .row { display: flex; } @media (max-width: 767px) { .scope .row { flex-direction: column; } }',
            'scope'
        );
        $this->assertNotContains('flex-no-wrap', $this->codes($mediaAdjusted['warnings']));
    }

    public function testFixedMultiColumnGridWithoutMobileSwitchIsError(): void
    {
        $fixed = $this->auditor->audit('<div></div>', '.scope .grid { display: grid; grid-template-columns: repeat(3, 1fr); }', 'scope');
        $this->assertContains('fixed-grid', $this->codes($fixed['errors']));

        $autoFit = $this->auditor->audit('<div></div>', '.scope .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); }', 'scope');
        $this->assertNotContains('fixed-grid', $this->codes($autoFit['errors']));

        $mediaSwitched = $this->auditor->audit(
            '<div></div>',
            '.scope .grid { display: grid; grid-template-columns: 1fr 1fr 1fr; } @media (max-width: 767px) { .scope .grid { grid-template-columns: 1fr; } }',
            'scope'
        );
        $this->assertNotContains('fixed-grid', $this->codes($mediaSwitched['errors']));
    }

    public function testImageWithoutWidthLimitWarnsOnlyWhenHtmlHasImage(): void
    {
        $withImg = $this->auditor->audit('<img src="/storage/a.png" alt="">', '.scope .card { padding: 1rem; }', 'scope');
        $this->assertContains('img-width', $this->codes($withImg['warnings']));

        $limited = $this->auditor->audit('<img src="/storage/a.png" alt="">', '.scope .photo { max-width: 100%; height: auto; }', 'scope');
        $this->assertNotContains('img-width', $this->codes($limited['warnings']));

        $noImg = $this->auditor->audit('<div><p>텍스트</p></div>', '.scope .card { padding: 1rem; }', 'scope');
        $this->assertNotContains('img-width', $this->codes($noImg['warnings']));
    }

    public function testLongTransitionWithoutReducedMotionMediaWarns(): void
    {
        $bare = $this->auditor->audit('<div></div>', '.scope .card { transition: transform 0.6s ease; }', 'scope');
        $this->assertContains('reduced-motion', $this->codes($bare['warnings']));

        $handled = $this->auditor->audit(
            '<div></div>',
            '.scope .card { transition: transform 0.6s ease; } @media (prefers-reduced-motion: reduce) { .scope .card { transition-duration: 0.01s; } }',
            'scope'
        );
        $this->assertNotContains('reduced-motion', $this->codes($handled['warnings']));

        $short = $this->auditor->audit('<div></div>', '.scope .card { transition: color 0.2s; }', 'scope');
        $this->assertNotContains('reduced-motion', $this->codes($short['warnings']));
    }

    public function testEmptyCssPassesAndFindingsAreDeduplicated(): void
    {
        $empty = $this->auditor->audit('<div></div>', '', 'scope');
        $this->assertSame('pass', $empty['status']);

        $duplicated = '.scope .a { font-size: 14px; } .scope .a { font-size: 14px; }';
        $result = $this->auditor->audit('<div></div>', $duplicated, 'scope');
        $this->assertCount(1, $result['warnings']);
    }
}
