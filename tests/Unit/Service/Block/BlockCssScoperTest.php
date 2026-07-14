<?php

namespace Tests\Unit\Service\Block;

use Mublo\Service\Block\BlockCssScoper;
use PHPUnit\Framework\TestCase;

/**
 * 블록 CSS 스코퍼 (회귀) — 프론트 출력이 에디터 미리보기와 동일하게
 * #bc-{id} 하위로 가둬지는지.
 */
class BlockCssScoperTest extends TestCase
{
    public function testPrefixesSimpleSelectors(): void
    {
        $scoped = BlockCssScoper::scope('.title { color: red; }', '#bc-1');

        $this->assertSame('#bc-1 .title { color: red; }', $scoped);
    }

    public function testPrefixesEachSelectorInList(): void
    {
        $scoped = BlockCssScoper::scope('.a, .b h2 { margin: 0; }', '#bc-1');

        $this->assertSame('#bc-1 .a, #bc-1 .b h2 { margin: 0; }', $scoped);
    }

    public function testDoesNotSplitCommaInsideFunctionalSelector(): void
    {
        $scoped = BlockCssScoper::scope(':is(.a, .b) { color: blue; }', '#bc-1');

        $this->assertSame('#bc-1 :is(.a, .b) { color: blue; }', $scoped);
    }

    public function testRecursesIntoMediaQueries(): void
    {
        $scoped = BlockCssScoper::scope(
            '@media (max-width: 768px) { .title { font-size: 14px; } }',
            '#bc-1'
        );

        $this->assertStringContainsString('@media (max-width: 768px) {', $scoped);
        $this->assertStringContainsString('#bc-1 .title { font-size: 14px; }', $scoped);
    }

    public function testKeepsKeyframesUnscoped(): void
    {
        $css = '@keyframes spin { from { transform: rotate(0); } to { transform: rotate(360deg); } }';
        $scoped = BlockCssScoper::scope($css, '#bc-1');

        $this->assertStringNotContainsString('#bc-1 from', $scoped);
        $this->assertStringContainsString('@keyframes spin {', $scoped);
    }

    public function testKeepsBlocklessAtStatements(): void
    {
        $scoped = BlockCssScoper::scope('@charset "utf-8"; .a { top: 0; }', '#bc-1');

        $this->assertStringContainsString('@charset "utf-8";', $scoped);
        $this->assertStringContainsString('#bc-1 .a { top: 0; }', $scoped);
    }

    public function testBracesInsideStringsDoNotBreakParsing(): void
    {
        $scoped = BlockCssScoper::scope('.a::before { content: "}"; } .b { top: 0; }', '#bc-1');

        $this->assertStringContainsString('#bc-1 .a::before { content: "}"; }', $scoped);
        $this->assertStringContainsString('#bc-1 .b { top: 0; }', $scoped);
    }

    public function testCommentsArePreserved(): void
    {
        $scoped = BlockCssScoper::scope('/* note */ .a { top: 0; }', '#bc-1');

        $this->assertStringContainsString('/* note */', $scoped);
        $this->assertStringContainsString('#bc-1 .a { top: 0; }', $scoped);
    }

    public function testUnbalancedCssFallsBackToNestingWrap(): void
    {
        $scoped = BlockCssScoper::scope('.broken { color: red;', '#bc-1');

        // 파싱 불가 — 네이티브 네스팅 폴백으로도 스코핑 의미는 유지
        $this->assertStringStartsWith('#bc-1 {', $scoped);
        $this->assertStringContainsString('.broken { color: red;', $scoped);
    }

    public function testEmptyCssStaysEmpty(): void
    {
        $this->assertSame('', BlockCssScoper::scope("  \n ", '#bc-1'));
    }
}
