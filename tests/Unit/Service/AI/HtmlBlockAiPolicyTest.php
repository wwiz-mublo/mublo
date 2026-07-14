<?php

namespace Tests\Unit\Service\AI;

use Mublo\Service\AI\HtmlBlockAiPolicy;
use PHPUnit\Framework\TestCase;

class HtmlBlockAiPolicyTest extends TestCase
{
    public function testHeroSliderIntentOverridesCardsPreset(): void
    {
        $behavior = HtmlBlockAiPolicy::enforceRequestBehavior(
            ['types' => ['slider'], 'autoplay_seconds' => 5, 'slider_preset' => 'cards'],
            '3개의 카드로 hero를 만들어주고, 슬라이드가 적용되게 해줘'
        );

        $this->assertSame('hero', $behavior['slider_preset']);
        $this->assertSame(3, HtmlBlockAiPolicy::requestedHeroSlideCount(
            '3개의 카드로 hero를 만들어주고, 슬라이드가 적용되게 해줘'
        ));
    }

    public function testOrdinaryCardSliderKeepsCardsPreset(): void
    {
        $behavior = HtmlBlockAiPolicy::enforceRequestBehavior(
            ['types' => ['slider'], 'autoplay_seconds' => 0, 'slider_preset' => 'cards'],
            '서비스 장점 카드 3개를 슬라이드로 만들어줘'
        );

        $this->assertSame('cards', $behavior['slider_preset']);
        $this->assertSame(5, $behavior['autoplay_seconds']);
        $this->assertNull(HtmlBlockAiPolicy::requestedHeroSlideCount('서비스 장점 카드 3개를 슬라이드로 만들어줘'));
    }

    public function testExplicitManualSliderDisablesDefaultAutoplay(): void
    {
        $behavior = HtmlBlockAiPolicy::enforceRequestBehavior(
            ['types' => ['slider'], 'autoplay_seconds' => 5, 'slider_preset' => 'hero'],
            'hero 슬라이드를 만들되 자동 전환 없이 수동으로 넘기게 해줘'
        );

        $this->assertSame(0, $behavior['autoplay_seconds']);
    }

    public function testExplicitProviderAutoplayIntervalIsPreserved(): void
    {
        $behavior = HtmlBlockAiPolicy::enforceRequestBehavior(
            ['types' => ['slider'], 'autoplay_seconds' => 8, 'slider_preset' => 'hero'],
            'hero 슬라이드를 만들어줘'
        );

        $this->assertSame(8, $behavior['autoplay_seconds']);
    }

    public function testHeroWithoutSliderDoesNotChangeBehavior(): void
    {
        $behavior = HtmlBlockAiPolicy::enforceRequestBehavior(
            ['types' => [], 'autoplay_seconds' => 0, 'slider_preset' => 'none'],
            'hero 카드 3개를 만들어줘'
        );

        $this->assertSame('none', $behavior['slider_preset']);
    }
}
