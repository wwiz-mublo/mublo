<?php
namespace Tests\Unit\Service\AI;

use Mublo\Contract\AI\AiRequest;
use Mublo\Repository\AI\AiUsageRepository;
use Mublo\Service\AI\AiAssetService;
use Mublo\Service\AI\CoreAiGateway;
use Mublo\Service\AI\DomainAiConfigService;
use Mublo\Service\AI\Provider\AiProviderFactory;
use Mublo\Service\AI\Provider\AiProviderInterface;
use PHPUnit\Framework\TestCase;

class CoreAiGatewayTest extends TestCase
{
    public function testUsesDomainCredentialInternallyAndReturnsStructuredResponse(): void
    {
        $config = $this->createMock(DomainAiConfigService::class);
        $config->method('runtimeConfig')->with(7)->willReturn([
            'provider' => 'openai', 'model' => 'gpt-4.1-mini', 'api_key' => 'never-expose-me',
            'daily_request_limit' => 50,
        ]);
        $usage = $this->createMock(AiUsageRepository::class);
        $usage->method('consume')->willReturn(true);
        $provider = $this->createMock(AiProviderInterface::class);
        $provider->expects($this->once())->method('generateStructured')
            ->with(
                'never-expose-me',
                'gpt-4.1-mini',
                'Answer safely.',
                'Summarize this.',
                $this->isType('array'),
                [],
            )
            ->willReturn(['summary' => '완료']);
        $factory = $this->createMock(AiProviderFactory::class);
        $factory->method('make')->with('openai')->willReturn($provider);
        $assets = $this->createMock(AiAssetService::class);
        $assets->method('resolveForPrompt')->with(7, [11])->willReturn([]);

        $gateway = new CoreAiGateway($config, $usage, $factory, $assets);
        $response = $gateway->generate(7, new AiRequest(
            'Answer safely.',
            'Summarize this.',
            [
                'type' => 'object',
                'properties' => ['summary' => ['type' => 'string']],
                'required' => ['summary'],
                'additionalProperties' => false,
            ],
            [11],
        ));

        $this->assertSame(['summary' => '완료'], $response->getData());
        $this->assertSame('openai', $response->getProvider());
        $this->assertSame('gpt-4.1-mini', $response->getModel());
    }

    public function testAvailabilityDoesNotExposeConfiguration(): void
    {
        $config = $this->createMock(DomainAiConfigService::class);
        $config->method('publicConfig')->willReturn(['is_enabled' => true, 'api_key_configured' => true]);
        $gateway = new CoreAiGateway(
            $config,
            $this->createMock(AiUsageRepository::class),
            $this->createMock(AiProviderFactory::class),
            $this->createMock(AiAssetService::class),
        );

        $this->assertTrue($gateway->isAvailable(1));
    }
}
