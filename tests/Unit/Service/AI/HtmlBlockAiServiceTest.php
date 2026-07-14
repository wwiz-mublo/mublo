<?php
namespace Tests\Unit\Service\AI;

use Mublo\Repository\AI\AiUsageRepository;
use Mublo\Service\AI\AiHtmlSanitizer;
use Mublo\Service\AI\DomainAiConfigService;
use Mublo\Service\AI\HtmlBlockAiService;
use Mublo\Service\AI\HtmlBlockVisibleCopyFilter;
use Mublo\Service\AI\HtmlBlockPromptBuilder;
use Mublo\Service\AI\Provider\AiProviderFactory;
use Mublo\Service\AI\Provider\AiProviderInterface;
use Mublo\Service\AI\ResponsiveCssAuditor;
use Mublo\Service\AI\ScopedCssSanitizer;
use Mublo\Service\AI\TrustedBlockBehaviorBuilder;
use PHPUnit\Framework\TestCase;

class HtmlBlockAiServiceTest extends TestCase
{
    public function testDisallowedCssDoesNotDiscardUsableAiResult(): void
    {
        $config = $this->createMock(DomainAiConfigService::class);
        $config->method('runtimeConfig')->willReturn([
            'provider' => 'openai', 'model' => 'gpt-4.1-mini', 'api_key' => 'secret',
            'daily_request_limit' => 50,
        ]);
        $usage = $this->createMock(AiUsageRepository::class);
        $usage->method('consume')->willReturn(true);
        $provider = $this->createMock(AiProviderInterface::class);
        $provider->method('generate')->willReturn([
            'html' => '<section class="ai-card"><h2>제목</h2></section>',
            'css' => '.ai-card { color:red; position:fixed; padding:10px; }',
            'js' => 'alert(1)',
            'notes' => '카드를 만들었습니다.',
            'behavior' => ['types' => [], 'autoplay_seconds' => 0],
        ]);
        $factory = $this->createMock(AiProviderFactory::class);
        $factory->method('make')->willReturn($provider);

        $service = new HtmlBlockAiService(
            $config,
            $usage,
            $factory,
            new ScopedCssSanitizer(),
            new HtmlBlockPromptBuilder(),
            new AiHtmlSanitizer(),
            new TrustedBlockBehaviorBuilder(),
            new ResponsiveCssAuditor(),
            new HtmlBlockVisibleCopyFilter()
        );
        $result = $service->generate(1, 2, 0, [
            'prompt' => '카드 생성', 'mode' => 'create',
            'current_html' => '', 'current_css' => '', 'current_js' => "console.log('manual');",
        ]);

        $this->assertStringContainsString('<section class="block-card">', $result['html']);
        $this->assertStringContainsString('.mublo-block-', $result['css']);
        $this->assertStringContainsString('/* mublo-layout-container */', $result['css']);
        $this->assertMatchesRegularExpression('/\.mublo-block-[a-f0-9]+ \{ container-type: inline-size; \}/', $result['css']);
        $this->assertStringContainsString('.block-card', $result['css']);
        $this->assertStringNotContainsString('class="ai-', $result['html']);
        $this->assertStringContainsString('color: red', $result['css']);
        $this->assertStringNotContainsString('position', $result['css']);
        $this->assertSame("console.log('manual');", $result['js']);
        $this->assertStringContainsString('허용되지 않는 CSS 속성', $result['notes']);
    }

    public function testTrustedTabsAreGeneratedAfterSanitizingAiHtml(): void
    {
        $config = $this->createMock(DomainAiConfigService::class);
        $config->method('runtimeConfig')->willReturn([
            'provider' => 'openai', 'model' => 'gpt-4.1-mini', 'api_key' => 'secret',
            'daily_request_limit' => 50,
        ]);
        $usage = $this->createMock(AiUsageRepository::class);
        $usage->method('consume')->willReturn(true);
        $provider = $this->createMock(AiProviderInterface::class);
        $provider->method('generate')->willReturn([
            'html' => '<section class="mublo-tabs"><div class="mublo-tab-list">'
                . '<span class="mublo-tab" onclick="evil()">첫째</span><span class="mublo-tab">둘째</span></div>'
                . '<div class="mublo-tab-panels"><article class="mublo-tab-panel">A</article>'
                . '<article class="mublo-tab-panel">B</article></div></section>',
            'css' => '.mublo-tab { padding:8px; }',
            'notes' => '탭을 만들었습니다.',
            'behavior' => ['types' => ['tabs'], 'autoplay_seconds' => 0],
        ]);
        $factory = $this->createMock(AiProviderFactory::class);
        $factory->method('make')->willReturn($provider);

        $service = new HtmlBlockAiService(
            $config,
            $usage,
            $factory,
            new ScopedCssSanitizer(),
            new HtmlBlockPromptBuilder(),
            new AiHtmlSanitizer(),
            new TrustedBlockBehaviorBuilder(),
            new ResponsiveCssAuditor(),
            new HtmlBlockVisibleCopyFilter()
        );

        $result = $service->generate(1, 3, 0, [
            'prompt' => '탭 생성', 'mode' => 'create', 'current_js' => "console.log('manual');",
        ]);

        $this->assertStringNotContainsString('onclick', $result['html']);
        $this->assertStringContainsString('class="mublo-tab"', $result['html']);
        $this->assertStringContainsString('.mublo-tabs.is-enhanced .mublo-tab-panel.is-active', $result['css']);
        $this->assertStringContainsString("console.log('manual');", $result['js']);
        $this->assertStringContainsString("setAttribute('role', 'tab')", $result['js']);
        $this->assertStringContainsString('mublo-block-behavior:start', $result['js']);
    }

    public function testSliderFallbackCssSurvivesSanitizerAndJsCallsGlobalAdapter(): void
    {
        $config = $this->createMock(DomainAiConfigService::class);
        $config->method('runtimeConfig')->willReturn([
            'provider' => 'openai', 'model' => 'gpt-4.1-mini', 'api_key' => 'secret',
            'daily_request_limit' => 50,
        ]);
        $usage = $this->createMock(AiUsageRepository::class);
        $usage->method('consume')->willReturn(true);
        $provider = $this->createMock(AiProviderInterface::class);
        $provider->method('generate')->willReturn([
            'html' => '<section class="mublo-slider"><div class="mublo-slider-track">'
                . '<article class="mublo-slide">A</article><article class="mublo-slide">B</article>'
                . '<article class="mublo-slide">C</article></div></section>',
            'css' => '.mublo-slide { padding:16px; }',
            'notes' => '슬라이드를 만들었습니다.',
            'behavior' => ['types' => ['slider'], 'autoplay_seconds' => 4, 'slider_preset' => 'cards'],
        ]);
        $factory = $this->createMock(AiProviderFactory::class);
        $factory->method('make')->willReturn($provider);

        $service = new HtmlBlockAiService(
            $config,
            $usage,
            $factory,
            new ScopedCssSanitizer(),
            new HtmlBlockPromptBuilder(),
            new AiHtmlSanitizer(),
            new TrustedBlockBehaviorBuilder(),
            new ResponsiveCssAuditor(),
            new HtmlBlockVisibleCopyFilter()
        );

        $result = $service->generate(1, 4, 0, ['prompt' => '슬라이드 생성', 'mode' => 'create']);

        // fallback CSS가 스코프·:not(.is-enhanced) 그대로 새니타이저를 통과해야 한다
        $this->assertStringContainsString('.mublo-slider:not(.is-enhanced) .mublo-slider-track', $result['css']);
        $this->assertStringContainsString('scroll-snap-type: x mandatory', $result['css']);
        // 슬라이더 실행은 전역 adapter 한 곳 — 블록 JS는 위임 호출만 담는다
        $this->assertStringContainsString('MubloSlider.init', $result['js']);
        $this->assertStringContainsString('preset: "cards"', $result['js']);
        $this->assertStringContainsString('autoplaySeconds: 4', $result['js']);
        $this->assertStringNotContainsString('setInterval', $result['js']);
        $this->assertStringNotContainsString('new Swiper', $result['js']);
    }

    public function testMetaNarrationIsNotReturnedAsVisibleHtml(): void
    {
        $config = $this->createMock(DomainAiConfigService::class);
        $config->method('runtimeConfig')->willReturn([
            'provider' => 'openai', 'model' => 'gpt-4.1-mini', 'api_key' => 'secret',
            'daily_request_limit' => 50,
        ]);
        $usage = $this->createMock(AiUsageRepository::class);
        $usage->method('consume')->willReturn(true);
        $provider = $this->createMock(AiProviderInterface::class);
        $provider->method('generate')->willReturn([
            'html' => '<section><h2>서비스 장점</h2>'
                . '<p>참고자료의 방향에 맞춰 서비스의 강점을 세 가지 카드로 간결하게 정리했습니다.</p>'
                . '<article><h3>빠른 실행</h3><p>운영 속도를 높입니다.</p></article></section>',
            'css' => '.service-card { padding:16px; }',
            'notes' => '서비스 장점을 구성했습니다.',
            'behavior' => ['types' => [], 'autoplay_seconds' => 0, 'slider_preset' => 'none'],
        ]);
        $factory = $this->createMock(AiProviderFactory::class);
        $factory->method('make')->willReturn($provider);

        $service = new HtmlBlockAiService(
            $config,
            $usage,
            $factory,
            new ScopedCssSanitizer(),
            new HtmlBlockPromptBuilder(),
            new AiHtmlSanitizer(),
            new TrustedBlockBehaviorBuilder(),
            new ResponsiveCssAuditor(),
            new HtmlBlockVisibleCopyFilter()
        );

        $result = $service->generate(1, 6, 0, ['prompt' => '서비스 장점을 카드로 만들어줘']);

        $this->assertStringNotContainsString('참고자료의 방향에 맞춰', $result['html']);
        $this->assertStringContainsString('빠른 실행', $result['html']);
        $this->assertStringContainsString('작업 과정 설명 1개를 제거했습니다.', $result['notes']);
    }

    public function testSliderDefaultsToFiveSecondAutoplayWhenUserDoesNotSpecifyIt(): void
    {
        $config = $this->createMock(DomainAiConfigService::class);
        $config->method('runtimeConfig')->willReturn([
            'provider' => 'openai', 'model' => 'gpt-4.1-mini', 'api_key' => 'secret',
            'daily_request_limit' => 50,
        ]);
        $usage = $this->createMock(AiUsageRepository::class);
        $usage->method('consume')->willReturn(true);
        $provider = $this->createMock(AiProviderInterface::class);
        $provider->method('generate')->willReturn([
            'html' => '<section class="mublo-slider"><div class="mublo-slider-track">'
                . '<article class="mublo-slide">A</article><article class="mublo-slide">B</article>'
                . '<article class="mublo-slide">C</article></div></section>',
            'css' => '.mublo-slide { padding:16px; }',
            'notes' => '슬라이드를 만들었습니다.',
            'behavior' => ['types' => ['slider'], 'autoplay_seconds' => 0, 'slider_preset' => 'hero'],
        ]);
        $factory = $this->createMock(AiProviderFactory::class);
        $factory->method('make')->willReturn($provider);

        $service = new HtmlBlockAiService(
            $config,
            $usage,
            $factory,
            new ScopedCssSanitizer(),
            new HtmlBlockPromptBuilder(),
            new AiHtmlSanitizer(),
            new TrustedBlockBehaviorBuilder(),
            new ResponsiveCssAuditor(),
            new HtmlBlockVisibleCopyFilter()
        );

        $result = $service->generate(1, 7, 0, ['prompt' => 'hero 슬라이드를 만들어줘']);

        $this->assertStringContainsString('preset: "hero"', $result['js']);
        $this->assertStringContainsString('autoplaySeconds: 5', $result['js']);
    }

    public function testHeroSliderRequestUsesOneHeroPerViewEvenWhenProviderChoosesCardsPreset(): void
    {
        $config = $this->createMock(DomainAiConfigService::class);
        $config->method('runtimeConfig')->willReturn([
            'provider' => 'openai', 'model' => 'gpt-4.1-mini', 'api_key' => 'secret',
            'daily_request_limit' => 50,
        ]);
        $usage = $this->createMock(AiUsageRepository::class);
        $usage->method('consume')->willReturn(true);
        $provider = $this->createMock(AiProviderInterface::class);
        $provider->method('generate')->willReturn([
            'html' => '<section class="mublo-slider"><div class="mublo-slider-track">'
                . '<article class="mublo-slide"><h2>Hero A</h2></article>'
                . '<article class="mublo-slide"><h2>Hero B</h2></article>'
                . '<article class="mublo-slide"><h2>Hero C</h2></article></div></section>',
            'css' => '.mublo-slide { padding:16px; }',
            'notes' => 'hero 슬라이드를 만들었습니다.',
            'behavior' => ['types' => ['slider'], 'autoplay_seconds' => 5, 'slider_preset' => 'cards'],
        ]);
        $factory = $this->createMock(AiProviderFactory::class);
        $factory->method('make')->willReturn($provider);

        $service = new HtmlBlockAiService(
            $config,
            $usage,
            $factory,
            new ScopedCssSanitizer(),
            new HtmlBlockPromptBuilder(),
            new AiHtmlSanitizer(),
            new TrustedBlockBehaviorBuilder(),
            new ResponsiveCssAuditor(),
            new HtmlBlockVisibleCopyFilter()
        );

        $result = $service->generate(1, 5, 0, [
            'prompt' => '3개의 카드로 hero를 만들어주고, 슬라이드가 적용되게 해줘',
            'mode' => 'create',
        ]);

        $this->assertSame(3, substr_count($result['html'], 'class="mublo-slide"'));
        $this->assertStringContainsString('preset: "hero"', $result['js']);
        $this->assertStringNotContainsString('preset: "cards"', $result['js']);
    }

    public function testModifyRoundTripKeepsOneTrustedLayoutContainerAndHidesItFromProvider(): void
    {
        $config = $this->createMock(DomainAiConfigService::class);
        $config->method('runtimeConfig')->willReturn([
            'provider' => 'openai', 'model' => 'gpt-4.1-mini', 'api_key' => 'secret',
            'daily_request_limit' => 50,
        ]);
        $usage = $this->createMock(AiUsageRepository::class);
        $usage->method('consume')->willReturn(true);
        $scope = 'mublo-block-' . substr(hash('sha256', '1:8:0'), 0, 12);
        $provider = $this->createMock(AiProviderInterface::class);
        $provider->method('generate')->willReturnCallback(
            function (string $apiKey, string $model, string $system, string $user): array {
                $this->assertStringNotContainsString('mublo-layout-container', $user);
                $this->assertStringNotContainsString('container-type', $user);
                $this->assertStringContainsString('.title { font-size: clamp(2rem, 6cqi, 4rem); }', $user);

                return [
                    'html' => '<section class="hero"><h2 class="title">수정 제목</h2></section>',
                    'css' => '.title { font-size: clamp(2rem, 6cqi, 4rem); }',
                    'notes' => '제목을 수정했습니다.',
                    'behavior' => ['types' => [], 'autoplay_seconds' => 0, 'slider_preset' => 'none'],
                ];
            }
        );
        $factory = $this->createMock(AiProviderFactory::class);
        $factory->method('make')->willReturn($provider);
        $service = new HtmlBlockAiService(
            $config,
            $usage,
            $factory,
            new ScopedCssSanitizer(),
            new HtmlBlockPromptBuilder(),
            new AiHtmlSanitizer(),
            new TrustedBlockBehaviorBuilder(),
            new ResponsiveCssAuditor(),
            new HtmlBlockVisibleCopyFilter()
        );
        $currentCss = "/* mublo-generated */\n/* mublo-layout-container */\n.{$scope} { container-type: inline-size; }\n"
            . ".{$scope} .title { font-size: clamp(2rem, 6cqi, 4rem); }";

        $result = $service->generate(1, 8, 0, [
            'prompt' => '제목만 수정해줘',
            'mode' => 'modify',
            'current_html' => '<div class="' . $scope . '"><section class="hero"><h2 class="title">기존 제목</h2></section></div>',
            'current_css' => $currentCss,
        ]);

        $this->assertSame(1, substr_count($result['css'], '/* mublo-layout-container */'));
        $this->assertSame(1, substr_count($result['css'], 'container-type: inline-size'));
        $this->assertStringContainsString('font-size: clamp(2rem, 6cqi, 4rem)', $result['css']);
    }
}
