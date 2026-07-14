<?php
namespace Tests\Unit\Service\AI;

use Mublo\Infrastructure\AI\AiHttpClient;
use Mublo\Service\AI\Provider\OpenAiResponsesProvider;
use Mublo\Service\AI\Provider\AnthropicMessagesProvider;
use PHPUnit\Framework\TestCase;

/**
 * P3 — 출력 잘림 감지 테스트: 잘린 응답이 "형식 오류"라는 원인 불명
 * 메시지 대신 명확한 잘림 오류로 노출되어야 한다.
 */
class ProviderTruncationTest extends TestCase
{
    private function http(array $response): AiHttpClient
    {
        $http = $this->createMock(AiHttpClient::class);
        $http->method('post')->willReturn($response);

        return $http;
    }

    public function testOpenAiIncompleteMaxTokensRaisesClearError(): void
    {
        $provider = new OpenAiResponsesProvider($this->http([
            'status' => 'incomplete',
            'incomplete_details' => ['reason' => 'max_output_tokens'],
            'output' => [],
        ]));

        $this->expectExceptionMessage('출력 한도에서 잘렸습니다');
        $provider->generate('key', 'gpt-test', 'sys', 'user');
    }

    public function testOpenAiRequestIncludesOutputTokenCap(): void
    {
        $captured = null;
        $http = $this->createMock(AiHttpClient::class);
        $http->method('post')->willReturnCallback(function (string $url, array $headers, array $payload) use (&$captured): array {
            $captured = $payload;
            return ['output' => [['content' => [['text' => '{"html":"<p>x</p>","css":"","notes":"","behavior":{"types":[],"autoplay_seconds":0}}']]]]];
        });

        (new OpenAiResponsesProvider($http))->generate('key', 'gpt-test', 'sys', 'user');

        $this->assertSame(\Mublo\Core\AiConfig::maxOutputTokens(), $captured['max_output_tokens'] ?? null, 'P3: OpenAI 출력 상한 명시');
    }

    public function testAnthropicUsesConfigurableOutputTokens(): void
    {
        $captured = null;
        $http = $this->createMock(AiHttpClient::class);
        $http->method('post')->willReturnCallback(function (string $url, array $headers, array $payload) use (&$captured): array {
            $captured = $payload;
            return ['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => '{"html":"<p>x</p>","css":"","notes":"","behavior":{"types":[],"autoplay_seconds":0}}']]];
        });

        (new AnthropicMessagesProvider($http))->generate('key', 'claude-test', 'sys', 'user');

        $this->assertSame(\Mublo\Core\AiConfig::maxOutputTokens(), $captured['max_tokens'] ?? null, 'P3: 하드코딩 16000 → 설정화');
    }
}
