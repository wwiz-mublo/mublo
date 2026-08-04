<?php
namespace Tests\Unit\Service\AI;

use Mublo\Service\AI\HtmlBlockPromptBuilder;
use PHPUnit\Framework\TestCase;

class HtmlBlockPromptBuilderTest extends TestCase
{
    public function testCreatePromptContainsEditableBlockContract(): void
    {
        $prompt = (new HtmlBlockPromptBuilder())->build('create', '카드 세 개를 만들어줘');
        $this->assertStringContainsString('HTML fragment only', $prompt['system']);
        $this->assertStringContainsString('Never use JavaScript', $prompt['system']);
        $this->assertStringContainsString('responsive mobile-first', $prompt['system']);
        $this->assertStringContainsString('LAYOUT-WIDTH RESPONSIVENESS', $prompt['system']);
        $this->assertStringContainsString('full-width, beside a page sidebar', $prompt['system']);
        $this->assertStringContainsString('ACTUAL BLOCK WIDTH with cqi', $prompt['system']);
        $this->assertStringContainsString('not only heroes', $prompt['system']);
        $this->assertStringContainsString('Do not insert manual <br> in headings', $prompt['system']);
        $this->assertStringNotContainsString('2.5vw', $prompt['system']);
        $this->assertStringContainsString('The only @rule allowed is @media', $prompt['system']);
        $this->assertStringContainsString('grid-auto-flow', $prompt['system']);
        $this->assertStringContainsString('.mublo-slider-track', $prompt['system']);
        $this->assertStringContainsString('.mublo-tab-list', $prompt['system']);
        $this->assertStringContainsString('.mublo-accordion-trigger', $prompt['system']);
        $this->assertStringContainsString('may combine trusted behaviors', $prompt['system']);
        $this->assertStringContainsString('must never use "ai"', $prompt['system']);
        $this->assertStringContainsString('VISIBLE COPY DISCIPLINE', $prompt['system']);
        $this->assertStringContainsString('Put any generation summary only in notes', $prompt['system']);
        $this->assertStringContainsString('Default autoplay_seconds to 5', $prompt['system']);
        $this->assertStringContainsString('Use 0 only when the user explicitly asks', $prompt['system']);
        $this->assertStringContainsString('USER_REQUEST_JSON:', $prompt['user']);
        $this->assertStringNotContainsString('CURRENT_HTML_JSON:', $prompt['user']);
    }

    public function testModifyPromptRequiresMinimalChangeAndIncludesCurrentContent(): void
    {
        $prompt = (new HtmlBlockPromptBuilder())->build('modify', '제목만 바꿔줘', '<h2>기존</h2>', '.title { color:red; }');
        $this->assertStringContainsString('smallest coherent change', $prompt['user']);
        $this->assertStringContainsString('CURRENT_HTML_JSON:', $prompt['user']);
        $this->assertStringContainsString('<h2>기존</h2>', $prompt['user']);
        $this->assertStringContainsString('CURRENT_CSS_JSON:', $prompt['user']);
    }

    public function testHeroSliderRequestTreatsEachRequestedCardAsACompleteHeroSlide(): void
    {
        $prompt = (new HtmlBlockPromptBuilder())->build(
            'create',
            '3개의 카드로 hero를 만들어주고, 스와이프 슬라이드가 적용되게 해줘'
        );

        $this->assertStringContainsString('REQUEST-SPECIFIC SLIDER CONTRACT', $prompt['system']);
        $this->assertStringContainsString('Each card/item means one complete hero slide', $prompt['system']);
        $this->assertStringContainsString('Create exactly 3 direct .mublo-slide children', $prompt['system']);
        $this->assertStringContainsString('Use slider_preset "hero"', $prompt['system']);
    }
}
