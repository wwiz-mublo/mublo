<?php
namespace Tests\Unit\Service\AI;

use Mublo\Service\AI\FrameAiPromptBuilder;
use Mublo\Service\AI\HtmlBlockPromptBuilder;
use PHPUnit\Framework\TestCase;

/**
 * P1 컨텍스트 주입 테스트 — 사이트 컨텍스트·스킨 클래스 사전·시드 골격
 */
class PromptSiteContextTest extends TestCase
{
    private const TOKENS = [
        ['name' => 'site_name', 'kind' => 'variable', 'label' => '사이트명'],
        ['name' => 'menu_main', 'kind' => 'slot', 'label' => '메인 메뉴'],
    ];

    public function testFramePromptIncludesSiteContextAndSkinDictionary(): void
    {
        $p = (new FrameAiPromptBuilder())->build('header', 'create', '헤더 만들어줘', '', '', self::TOKENS, [
            'site_name' => '무블로 데모',
            'primary_color' => '#ff5500',
            'skin' => 'basic',
        ]);

        $this->assertStringContainsString('SITE CONTEXT', $p['system']);
        $this->assertStringContainsString('무블로 데모', $p['system']);
        // 컬러 자율성 (개선 계획 §4): 기본색은 전달돼도 프롬프트에 주입되지 않는다
        $this->assertStringNotContainsString('#ff5500', $p['system']);
        $this->assertStringNotContainsString('brand color', $p['system']);
        $this->assertStringContainsString('SKIN CLASS DICTIONARY (skin: basic)', $p['system']);
        $this->assertStringContainsString('.mublo-header', $p['system']);
        $this->assertStringContainsString('KEEP them', $p['system']);
    }

    public function testFrameCreateIncludesSeedSkeletonModifyDoesNot(): void
    {
        $seed = '<header class="mublo-header">{{menu_main}}</header>';

        $create = (new FrameAiPromptBuilder())->build('header', 'create', '요청', '', '', self::TOKENS, ['seed_html' => $seed]);
        $this->assertStringContainsString('SEED_SKELETON_JSON', $create['user']);
        $this->assertStringContainsString('mublo-header', $create['user']);

        $modify = (new FrameAiPromptBuilder())->build('header', 'modify', '요청', '<header></header>', '', self::TOKENS, ['seed_html' => $seed]);
        $this->assertStringNotContainsString('SEED_SKELETON_JSON', $modify['user'], 'modify는 현재 편집본이 기준 — 시드 미주입');
    }

    public function testFramePromptForbidsCssVariableColors(): void
    {
        $p = (new FrameAiPromptBuilder())->build('footer', 'create', '요청', '', '', self::TOKENS);

        // 토큰 목록을 나열하면 모델이 그것을 기본값으로 삼는다 — 나열 금지 + var() 컬러 금지
        $this->assertStringContainsString('NEVER use CSS variables', $p['system']);
        $this->assertStringNotContainsString('var(--color-text)', $p['system'], '토큰 목록 나열 금지');
        $this->assertStringNotContainsString('Site name:', $p['system']);
        $this->assertStringNotContainsString('Site brand color', $p['system'], '값이 없으면 브랜드색 줄 자체가 없다');
    }

    public function testBlockPromptIncludesSiteContext(): void
    {
        $p = (new HtmlBlockPromptBuilder())->build('create', '카드 만들어줘', '', '', [
            'site_name' => '무블로 데모',
            'primary_color' => '#123456',
        ]);

        $this->assertStringContainsString('SITE CONTEXT', $p['system']);
        $this->assertStringContainsString('무블로 데모', $p['system']);
        // 컬러 자율성 (개선 계획 §4): 기본색은 전달돼도 프롬프트에 주입되지 않는다
        $this->assertStringNotContainsString('#123456', $p['system']);
        $this->assertStringNotContainsString('brand color', $p['system']);
        $this->assertStringContainsString('NEVER use CSS variables', $p['system']);
        $this->assertStringNotContainsString('var(--color-text)', $p['system'], '토큰 목록 나열 금지');
    }

    public function testBlockPromptBackwardCompatibleWithoutSite(): void
    {
        $p = (new HtmlBlockPromptBuilder())->build('create', '카드');

        $this->assertStringContainsString('SITE CONTEXT', $p['system'], '토큰 안내는 항상 포함');
        $this->assertStringNotContainsString('Site name:', $p['system']);
    }

    public function testUserColorAuthorityAndDisciplineRules(): void
    {
        $block = (new HtmlBlockPromptBuilder())->build('create', '히어로', '', '', ['primary_color' => '#123456']);

        // 컬러 자율성 (개선 계획 §4): 우선순위 = 사용자 지정 > 첨부 맥락 > AI 판단.
        // var() 컬러 금지, 명도 대비 요구, 기본색·토큰 미주입
        $this->assertStringContainsString('concrete literal color values', $block['system']);
        $this->assertStringContainsString('independent design judgment', $block['system']);
        $this->assertStringContainsString('sufficient contrast', $block['system']);
        $this->assertStringNotContainsString('Prefer these tokens over color literals', $block['system']);
        // 섹션 규율 (개선 계획 §5): one viewport 강제 제거, 용도별 기본 구성표
        $this->assertStringContainsString('SECTION DISCIPLINE', $block['system']);
        $this->assertStringContainsString('never a full landing page', $block['system']);
        $this->assertStringNotContainsString('one viewport', $block['system'], 'one viewport 고정 규칙 제거 (§5.3)');
        $this->assertStringContainsString('hero: optional eyebrow', $block['system']);
        $this->assertStringContainsString('FAQ: 4-8 questions', $block['system']);
        $this->assertStringContainsString('NOT hard limits', $block['system'], '구성표는 가이드 — 사용자 지시 우선');
        // 사실성 규율 (개선 계획 §5.1)
        $this->assertStringContainsString('FACTUAL DISCIPLINE', $block['system']);
        $this->assertStringContainsString('never fabricate', $block['system']);
        // 장식 절제: 그라디언트는 명시 요청 시에만
        $this->assertStringContainsString('DECORATION DISCIPLINE', $block['system']);
        $this->assertStringContainsString('gradient borders/outlines around cards', $block['system']);

        $frame = (new FrameAiPromptBuilder())->build('header', 'create', '헤더', '', '', self::TOKENS);
        $this->assertStringContainsString('independent design judgment', $frame['system']);
        $this->assertStringContainsString('Decoration discipline', $frame['system']);
        $this->assertStringNotContainsString('Prefer these tokens over color literals', $frame['system']);
    }
}
