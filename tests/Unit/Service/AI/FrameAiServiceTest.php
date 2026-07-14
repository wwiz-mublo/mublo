<?php
namespace Tests\Unit\Service\AI;

use Mublo\Repository\AI\AiUsageRepository;
use Mublo\Service\AI\DomainAiConfigService;
use Mublo\Service\AI\FrameAiPromptBuilder;
use Mublo\Service\AI\FrameAiService;
use Mublo\Service\AI\Provider\AiProviderFactory;
use Mublo\Service\AI\Provider\AiProviderInterface;
use Mublo\Service\AI\ScopedCssSanitizer;
use PHPUnit\Framework\TestCase;

/**
 * 프레임 AI 서비스 테스트 — 토큰 보존·계약 검증·CSS 스코핑·JS 미생성
 */
class FrameAiServiceTest extends TestCase
{
    private const TOKENS = [
        ['name' => 'site_name', 'kind' => 'variable', 'label' => '사이트명'],
        ['name' => 'menu_main', 'kind' => 'slot', 'label' => '메인 메뉴'],
        ['name' => 'mobile_panel', 'kind' => 'slot', 'label' => '모바일 패널'],
        ['name' => 'theme_switch', 'kind' => 'slot', 'label' => '테마 스위치'],
        ['name' => 'shop.cart_count', 'kind' => 'variable', 'label' => '장바구니 수'],
    ];

    /** 헤더 계약(토글+모바일 패널)을 충족하는 유효 골격 */
    private const VALID_HEADER = '<header class="mublo-header"><h1>{{site_name}}</h1><nav>{{menu_main}}</nav>'
        . '<span>{{shop.cart_count}}</span>'
        . '<button id="mubloPanelToggle" class="t"></button></header>{{mobile_panel}}';

    private function service(array $providerResult): FrameAiService
    {
        $config = $this->createMock(DomainAiConfigService::class);
        $config->method('runtimeConfig')->willReturn([
            'provider' => 'openai', 'model' => 'gpt-4.1-mini', 'api_key' => 'secret',
            'daily_request_limit' => 50,
        ]);
        $usage = $this->createMock(AiUsageRepository::class);
        $usage->method('consume')->willReturn(true);
        $provider = $this->createMock(AiProviderInterface::class);
        $provider->method('generate')->willReturn($providerResult);
        $factory = $this->createMock(AiProviderFactory::class);
        $factory->method('make')->willReturn($provider);

        return new FrameAiService(
            $config,
            $usage,
            $factory,
            new ScopedCssSanitizer(),
            new FrameAiPromptBuilder(),
            new \Mublo\Service\AI\FrameTemplateContractValidator(),
            new \Mublo\Service\AI\ResponsiveCssAuditor()
        );
    }

    public function testValidHeaderPassesWithTokensPreserved(): void
    {
        $service = $this->service([
            'html' => self::VALID_HEADER,
            'css' => '.mublo-header { padding: 12px; }',
            'notes' => '헤더를 만들었습니다.',
            'behavior' => ['types' => [], 'autoplay_seconds' => 0],
        ]);

        $result = $service->generate(1, 'header', ['prompt' => '헤더 생성'], self::TOKENS);

        $this->assertStringContainsString('{{site_name}}', $result['html']);
        $this->assertStringContainsString('{{menu_main}}', $result['html']);
        $this->assertStringContainsString('{{mobile_panel}}', $result['html']);
        $this->assertStringContainsString('id="mubloPanelToggle"', $result['html']);
    }

    public function testInventedTokenFailsContractValidation(): void
    {
        $service = $this->service([
            'html' => self::VALID_HEADER . '<p>{{invented.token}}</p>',
            'css' => '',
            'notes' => '',
            'behavior' => ['types' => [], 'autoplay_seconds' => 0],
        ]);

        // 개선 계획 §7.2: 미등록 토큰은 조용히 소거하지 않고 검증 오류로 처리
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('등록되지 않은 템플릿 토큰');

        $service->generate(1, 'header', ['prompt' => '헤더'], self::TOKENS);
    }

    public function testHeaderWithoutMobilePairFailsValidation(): void
    {
        $service = $this->service([
            'html' => '<header class="mublo-header"><h1>{{site_name}}</h1></header>',
            'css' => '',
            'notes' => '',
            'behavior' => ['types' => [], 'autoplay_seconds' => 0],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('모바일 토글');

        $service->generate(1, 'header', ['prompt' => '헤더'], self::TOKENS);
    }

    public function testFooterWithoutThemeSwitchFailsValidation(): void
    {
        $service = $this->service([
            'html' => '<footer class="mublo-footer">{{site_name}}</footer>',
            'css' => '',
            'notes' => '',
            'behavior' => ['types' => [], 'autoplay_seconds' => 0],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('theme_switch');

        $service->generate(1, 'footer', ['prompt' => '푸터'], self::TOKENS);
    }

    public function testCssIsScopedToFramePartAndJsIsNeverReturned(): void
    {
        $service = $this->service([
            'html' => '<footer class="mublo-footer">{{site_name}}{{theme_switch}}</footer>',
            'css' => '.mublo-footer { color: #888888; position: fixed; }',
            'js' => 'alert(1)',
            'notes' => '푸터.',
            'behavior' => ['types' => [], 'autoplay_seconds' => 0],
        ]);

        $result = $service->generate(1, 'footer', ['prompt' => '푸터 생성'], self::TOKENS);

        $this->assertStringContainsString('.mublo-frame-footer', $result['css'], 'CSS는 프레임 스코프로 프리픽스');
        $this->assertStringNotContainsString('position', $result['css'], '오버레이 벡터 속성 차단');
        $this->assertArrayNotHasKey('js', $result, '프레임 AI는 JS를 반환하지 않는다');
    }

    public function testDangerousHtmlSanitizedWithFramePolicy(): void
    {
        $service = $this->service([
            'html' => '<header class="mublo-header"><script>evil()</script>'
                . '<img src="/storage/logo.png" alt="l"><img src="https://evil.test/x.png" alt="x">'
                . '<button id="mubloPanelToggle" class="t"></button></header>{{mobile_panel}}',
            'css' => '',
            'notes' => '',
            'behavior' => ['types' => [], 'autoplay_seconds' => 0],
        ]);

        $result = $service->generate(1, 'header', ['prompt' => '헤더'], self::TOKENS);

        $this->assertStringNotContainsString('<script', $result['html']);
        $this->assertStringContainsString('src="/storage/logo.png"', $result['html']);
        $this->assertStringNotContainsString('evil.test', $result['html']);
        $this->assertStringContainsString('id="mubloPanelToggle"', $result['html'], '토글 훅 id 보존');
        $this->assertStringContainsString('{{mobile_panel}}', $result['html']);
    }

    public function testEmptyCssFromModelIsWarnedInNotes(): void
    {
        $service = $this->service([
            'html' => self::VALID_HEADER,
            'css' => '',
            'notes' => '헤더.',
            'behavior' => ['types' => [], 'autoplay_seconds' => 0],
        ]);

        $result = $service->generate(1, 'header', ['prompt' => '헤더'], self::TOKENS);

        $this->assertStringContainsString('CSS를 생성하지 않았습니다', $result['notes'], 'P3: 빈 CSS 무경고 통과 방지');
    }

    public function testInvalidPartRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service(['html' => '', 'css' => '', 'notes' => '', 'behavior' => ['types' => [], 'autoplay_seconds' => 0]])
            ->generate(1, 'sidebar', ['prompt' => 'x'], []);
    }
}
