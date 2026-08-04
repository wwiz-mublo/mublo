<?php
namespace Tests\Unit\Service\AI;

use Mublo\Service\AI\Provider\AbstractJsonHtmlProvider;
use PHPUnit\Framework\TestCase;

/**
 * behavior 정규화 (개선 계획 §6.4) — slider_preset은 hero/cards/gallery만,
 * slider 없으면 none, 잘못된 값은 hero. 자유 형식 Swiper 옵션은 통과하지 않는다.
 */
class ProviderBehaviorNormalizeTest extends TestCase
{
    private function providerReturning(array $structured): AbstractJsonHtmlProvider
    {
        return new class($structured) extends AbstractJsonHtmlProvider {
            public function __construct(private array $structured) {}

            public function generateStructured(
                string $apiKey,
                string $model,
                string $systemPrompt,
                string $userPrompt,
                array $responseSchema,
                array $attachments = [],
            ): array {
                return $this->structured;
            }
        };
    }

    private function behaviorOf(array $behavior): array
    {
        $result = $this->providerReturning([
            'html' => '<div>x</div>', 'css' => '', 'notes' => '', 'behavior' => $behavior,
        ])->generate('key', 'model', 'system', 'user');

        return $result['behavior'];
    }

    public function testSchemaOffersLimitedSliderPresets(): void
    {
        $provider = $this->providerReturning(['html' => '<div>x</div>']);
        $schema = (new \ReflectionMethod($provider, 'schema'))->invoke($provider);

        $preset = $schema['properties']['behavior']['properties']['slider_preset'];
        $this->assertSame(['none', 'hero', 'cards', 'gallery'], $preset['enum']);
        // OpenAI strict 모드는 모든 속성이 required여야 한다
        $this->assertContains('slider_preset', $schema['properties']['behavior']['required']);
    }

    public function testValidPresetPassesThrough(): void
    {
        $behavior = $this->behaviorOf(['types' => ['slider'], 'autoplay_seconds' => 5, 'slider_preset' => 'gallery']);
        $this->assertSame('gallery', $behavior['slider_preset']);
    }

    public function testMissingOrInvalidPresetNormalizesToHeroWhenSliderPresent(): void
    {
        $missing = $this->behaviorOf(['types' => ['slider'], 'autoplay_seconds' => 0]);
        $this->assertSame('hero', $missing['slider_preset']);

        $invalid = $this->behaviorOf([
            'types' => ['slider'], 'autoplay_seconds' => 0,
            'slider_preset' => '{"slidesPerView":9,"loop":true}',
        ]);
        $this->assertSame('hero', $invalid['slider_preset']);
    }

    public function testPresetIsNoneWithoutSliderType(): void
    {
        $static = $this->behaviorOf(['types' => [], 'autoplay_seconds' => 0, 'slider_preset' => 'cards']);
        $this->assertSame('none', $static['slider_preset']);

        $tabs = $this->behaviorOf(['types' => ['tabs'], 'autoplay_seconds' => 0, 'slider_preset' => 'hero']);
        $this->assertSame('none', $tabs['slider_preset']);

        $absent = $this->providerReturning(['html' => '<div>x</div>'])
            ->generate('key', 'model', 'system', 'user');
        $this->assertSame(['types' => [], 'autoplay_seconds' => 0, 'slider_preset' => 'none'], $absent['behavior']);
    }
}
