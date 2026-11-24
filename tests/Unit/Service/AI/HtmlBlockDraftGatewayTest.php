<?php
declare(strict_types=1);

namespace Tests\Unit\Service\AI;

use Mublo\Repository\AI\AiUsageRepository;
use Mublo\Service\AI\AiHtmlSanitizer;
use Mublo\Service\AI\DomainAiConfigService;
use Mublo\Service\AI\HtmlBlockAiService;
use Mublo\Service\AI\HtmlBlockDraftGateway;
use Mublo\Service\AI\HtmlBlockPromptBuilder;
use Mublo\Service\AI\HtmlBlockVisibleCopyFilter;
use Mublo\Service\AI\Provider\AiProviderFactory;
use Mublo\Service\AI\ResponsiveCssAuditor;
use Mublo\Service\AI\ScopedCssSanitizer;
use Mublo\Service\AI\TrustedBlockBehaviorBuilder;
use PHPUnit\Framework\TestCase;

/** 몰 밖 AI가 만든 HTML 블록도 관리자 AI와 같은 정제·스코프 제한·검사를 거치고, 스크립트는 만들지 않는다. */
final class HtmlBlockDraftGatewayTest extends TestCase
{
    private HtmlBlockDraftGateway $gateway;

    protected function setUp(): void
    {
        $factory = $this->createMock(AiProviderFactory::class);
        $factory->expects($this->never())->method('make');
        $this->gateway = new HtmlBlockDraftGateway(new HtmlBlockAiService(
            $this->createMock(DomainAiConfigService::class),
            $this->createMock(AiUsageRepository::class),
            $factory,
            new ScopedCssSanitizer(),
            new HtmlBlockPromptBuilder(),
            new AiHtmlSanitizer(),
            new TrustedBlockBehaviorBuilder(),
            new ResponsiveCssAuditor(),
            new HtmlBlockVisibleCopyFilter()
        ));
    }

    public function testPromptsUseTheAdminRulesWithAStaticOnlySchema(): void
    {
        $prompts = $this->gateway->prompts(1, '봄맞이 프로모션 배너');

        $this->assertNotSame('', $prompts['system']);
        $this->assertStringContainsString('봄맞이 프로모션 배너', $prompts['user']);
        $this->assertStringContainsString('static block', $prompts['user']);
        $this->assertSame(['html', 'css', 'notes'], $prompts['schema']['required']);
        $this->assertArrayNotHasKey('behavior', $prompts['schema']['properties']);
    }

    public function testFinalizeSanitizesScopesAndNeverAddsScript(): void
    {
        $result = $this->gateway->finalize(1, 'html_draft:abc', '프로모션', [
            'html' => '<section class="ai-promo" onclick="steal()"><h2>봄 세일</h2><script>alert(1)</script></section>',
            'css' => '.ai-promo { padding: 24px; position: fixed; }',
            'notes' => '배너',
        ]);

        $scope = HtmlBlockAiService::scopeFor('html_draft:abc');
        $this->assertStringStartsWith('<div class="' . $scope . '">', $result['html']);
        $this->assertStringNotContainsString('onclick', $result['html']);
        $this->assertStringNotContainsString('<script', $result['html']);
        $this->assertStringContainsString('.' . $scope . ' .block-promo', $result['css']);
        $this->assertStringNotContainsString('position: fixed', $result['css']);
        $this->assertArrayHasKey('status', $result['audit']);
        $this->assertArrayNotHasKey('js', $result);
    }

    public function testModifyingAGeneratedBlockStartsFromItsUnscopedContent(): void
    {
        $first = $this->gateway->finalize(1, 'html_draft:one', '배너', ['html' => '<h2 class="title">A</h2>', 'css' => '.title { color: #111; }']);
        $prompts = $this->gateway->prompts(1, '제목을 B로', ['html' => $first['html'], 'css' => $first['css']]);

        $this->assertStringContainsString('<h2 class=\"title\">A</h2>', $prompts['user']);
        $this->assertStringNotContainsString(HtmlBlockAiService::scopeFor('html_draft:one'), $prompts['user']);
    }

    public function testEmptyResultsAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->gateway->finalize(1, 'html_draft:x', '배너', ['html' => ' ', 'css' => '']);
    }
}
