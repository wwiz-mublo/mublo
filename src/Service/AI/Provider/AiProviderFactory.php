<?php
namespace Mublo\Service\AI\Provider;

use Mublo\Infrastructure\AI\AiHttpClient;

class AiProviderFactory
{
    public function __construct(private AiHttpClient $http) {}
    public function make(string $provider): AiProviderInterface
    {
        return match ($provider) {
            'openai' => new OpenAiResponsesProvider($this->http),
            'anthropic' => new AnthropicMessagesProvider($this->http),
            'gemini' => new GeminiInteractionsProvider($this->http),
            default => throw new \InvalidArgumentException('지원하지 않는 AI 공급자입니다.'),
        };
    }
}
