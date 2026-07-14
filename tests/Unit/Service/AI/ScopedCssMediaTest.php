<?php
namespace Tests\Unit\Service\AI;

use Mublo\Service\AI\ScopedCssSanitizer;
use PHPUnit\Framework\TestCase;

/**
 * 스코프드 @media 허용 테스트 (품질 검토 §3.5 설계)
 */
class ScopedCssMediaTest extends TestCase
{
    private ScopedCssSanitizer $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = new ScopedCssSanitizer();
    }

    public function testWhitelistedMediaBlockIsScopedAndKept(): void
    {
        $r = $this->s->sanitizeWithReport(
            '.nav { display: flex; } @media (min-width: 768px) { .nav { gap: 24px; } .logo { font-size: 1.4rem; } }',
            'mublo-frame-header'
        );

        $this->assertStringContainsString('.mublo-frame-header .nav { display: flex; }', $r['css']);
        $this->assertStringContainsString('@media (min-width: 768px) {', $r['css']);
        $this->assertStringContainsString('.mublo-frame-header .nav { gap: 24px; }', $r['css'], '@media 본문도 스코프 접두');
        $this->assertStringContainsString('.mublo-frame-header .logo', $r['css']);
        $this->assertSame([], $r['warnings']);
    }

    public function testAllowedFeatureVariants(): void
    {
        foreach ([
            '(max-width: 48em)',
            '(orientation: portrait)',
            '(prefers-color-scheme: dark)',
            '(prefers-reduced-motion: reduce)',
            '(hover: hover) and (pointer: fine)',
            'screen and (min-width: 1024px)',
            'only screen and (min-width: 62.5rem), (max-height: 500px)',
        ] as $condition) {
            $r = $this->s->sanitizeWithReport("@media {$condition} { .x { color: red; } }", 's');
            $this->assertStringContainsString('@media', $r['css'], "허용되어야 함: {$condition}");
        }
    }

    public function testRejectedConditionsDropOnlyTheBlock(): void
    {
        foreach ([
            'print',                            // 타입 불허
            '(min-width: 10vw)',                // 단위 불허
            '(width: 500px)',                   // 피처 불허
            'screen',                           // 피처 없는 타입 단독
            '(min-width: 500px) and (device-aspect-ratio: 16/9)',
        ] as $condition) {
            $r = $this->s->sanitizeWithReport(
                "@media {$condition} { .x { color: red; } } .y { color: blue; }",
                's'
            );
            $this->assertStringNotContainsString('@media', $r['css'], "거부되어야 함: {$condition}");
            $this->assertStringContainsString('.s .y', $r['css'], '평면 규칙은 유지');
        }
    }

    public function testDangerousPayloadInConditionRejected(): void
    {
        $r = $this->s->sanitizeWithReport(
            '@media (min-width: 768px) and url(evil) { .x { color: red; } }',
            's'
        );

        $this->assertStringNotContainsString('@media', $r['css']);
    }

    public function testNestedAtRuleInsideMediaDropsBlock(): void
    {
        $r = $this->s->sanitizeWithReport(
            '@media (min-width: 768px) { @media (max-width: 900px) { .x { color: red; } } }',
            's'
        );

        $this->assertSame('', trim(str_replace('/* mublo-generated */', '', $r['css'])));
        $this->assertNotEmpty($r['warnings']);
    }

    public function testOtherAtRulesStillVoidEntireCss(): void
    {
        $r = $this->s->sanitizeWithReport(
            '@import url("https://evil.test/x.css"); .x { color: red; } @media (min-width: 768px) { .y { color: blue; } }',
            's'
        );

        $this->assertSame('', $r['css'], '@import가 남아 있으면 전체 폐기 (기존 보수성 유지)');
    }

    public function testPropertyWhitelistAppliesInsideMedia(): void
    {
        $r = $this->s->sanitizeWithReport(
            '@media (min-width: 768px) { .x { position: fixed; color: red; } }',
            's'
        );

        $this->assertStringNotContainsString('position', $r['css']);
        $this->assertStringContainsString('color: red', $r['css']);
    }

    public function testNewExpressionPropertiesAllowed(): void
    {
        $r = $this->s->sanitizeWithReport(
            '.hero { background: linear-gradient(135deg, #667eea, #764ba2); transition: transform 0.2s ease; }'
            . ' .hero:hover { transform: translateY(-2px); }'
            . ' .card { aspect-ratio: 16/9; object-fit: cover; cursor: pointer; text-shadow: 0 1px 2px rgba(0,0,0,.3); }',
            's'
        );

        $this->assertStringContainsString('linear-gradient', $r['css'], 'P2: 그라디언트');
        $this->assertStringContainsString('transition: transform', $r['css'], 'P2: transition');
        $this->assertStringContainsString('transform: translateY', $r['css'], 'P2: transform');
        $this->assertStringContainsString('aspect-ratio', $r['css']);
        $this->assertStringContainsString('object-fit', $r['css']);
        $this->assertStringContainsString('cursor: pointer', $r['css']);
        $this->assertStringContainsString('text-shadow', $r['css']);
        $this->assertSame([], $r['warnings']);
    }

    public function testCssVariableValuesAreRejected(): void
    {
        // 제품 정책 (2026-07-14): AI 생성 CSS는 디자인 토큰(var())을 쓰지 않는다 —
        // 프롬프트 지시가 새더라도 새니타이저가 기계적으로 차단한다
        $r = $this->s->sanitizeWithReport(
            '.hero { background-color: var(--color-bg-secondary); color: #1a1a2e; padding: 2rem; }',
            's'
        );

        $this->assertStringNotContainsString('var(', $r['css']);
        $this->assertStringNotContainsString('background-color', $r['css'], 'var() 선언은 통째로 제외');
        $this->assertStringContainsString('color: #1a1a2e', $r['css'], '리터럴 값은 유지');
        $this->assertNotEmpty($r['warnings']);
    }

    public function testBackgroundWithUrlStillBlocked(): void
    {
        $r = $this->s->sanitizeWithReport('.x { background: url(https://evil.test/x.png); color: red; }', 's');

        $this->assertStringNotContainsString('url', $r['css'], 'background 허용돼도 url() 값은 차단');
        $this->assertStringContainsString('color: red', $r['css']);
    }

    public function testMediaBlockCountCap(): void
    {
        $css = str_repeat('@media (min-width: 768px) { .x { color: red; } } ', 15);
        $r = $this->s->sanitizeWithReport($css, 's');

        $this->assertSame(12, substr_count($r['css'], '@media'), '블록 수 상한 12');
        $this->assertContains('@media 블록이 너무 많아 초과분을 제외했습니다.', $r['warnings']);
    }
}
