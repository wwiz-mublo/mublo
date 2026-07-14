<?php
namespace Tests\Unit\Service\AI;

use Mublo\Infrastructure\AI\AiHttpClient;
use Mublo\Service\AI\Provider\AnthropicMessagesProvider;
use Mublo\Service\AI\Provider\GeminiInteractionsProvider;
use Mublo\Service\AI\Provider\OpenAiResponsesProvider;
use PHPUnit\Framework\TestCase;

class ProviderAttachmentTest extends TestCase
{
    public function testOpenAiUsesImageAndFileContentBlocks(): void
    {
        $payload = null;
        $http = $this->createMock(AiHttpClient::class);
        $http->method('post')->willReturnCallback(function ($url, $headers, $body) use (&$payload) {
            $payload = $body;
            return ['output' => [['content' => [['text' => $this->resultJson()]]]]];
        });
        (new OpenAiResponsesProvider($http))->generate('key', 'model', 'system', 'user', $this->attachments());
        $types = array_column($payload['input'][0]['content'], 'type');
        $this->assertContains('input_image', $types);
        $this->assertContains('input_file', $types);
        $this->assertStringContainsString('UNTRUSTED_REFERENCE_FILE', $payload['input'][0]['content'][3]['text']);
        $this->assertPortableSchema($payload['text']['format']['schema']);
    }

    public function testAnthropicUsesImageAndPdfBlocks(): void
    {
        $payload = null;
        $http = $this->createMock(AiHttpClient::class);
        $http->method('post')->willReturnCallback(function ($url, $headers, $body) use (&$payload) {
            $payload = $body;
            return ['content' => [['type' => 'text', 'text' => $this->resultJson()]]];
        });
        (new AnthropicMessagesProvider($http))->generate('key', 'model', 'system', 'user', $this->attachments());
        $types = array_column($payload['messages'][0]['content'], 'type');
        $this->assertContains('image', $types);
        $this->assertContains('document', $types);
        $this->assertStringContainsString('UNTRUSTED_REFERENCE_FILE', $payload['messages'][0]['content'][3]['text']);
        $this->assertSame(16000, $payload['max_tokens']);
        $this->assertPortableSchema($payload['output_config']['format']['schema']);
        $properties = $payload['output_config']['format']['schema']['properties'];
        $this->assertArrayNotHasKey('js', $properties);
        $this->assertSame(
            ['slider', 'tabs', 'accordion'],
            $properties['behavior']['properties']['types']['items']['enum']
        );
    }

    public function testGeminiUsesImageAndDocumentItems(): void
    {
        $payload = null;
        $http = $this->createMock(AiHttpClient::class);
        $http->method('post')->willReturnCallback(function ($url, $headers, $body) use (&$payload) {
            $payload = $body;
            return ['output_text' => $this->resultJson()];
        });
        (new GeminiInteractionsProvider($http))->generate('key', 'model', 'system', 'user', $this->attachments());
        $types = array_column($payload['input'], 'type');
        $this->assertContains('image', $types);
        $this->assertContains('document', $types);
        $this->assertStringContainsString('UNTRUSTED_REFERENCE_FILE', $payload['input'][3]['text']);
        $this->assertPortableSchema($payload['response_format']['schema']);
    }

    public function testAnthropicReportsOutputLimitBeforeDecodingPartialJson(): void
    {
        $http = $this->createMock(AiHttpClient::class);
        $http->method('post')->willReturn([
            'stop_reason' => 'max_tokens',
            'content' => [['type' => 'text', 'text' => '{"html":"incomplete']],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('출력 한도에서 잘렸습니다');
        (new AnthropicMessagesProvider($http))->generate('key', 'model', 'system', 'user');
    }

    public function testAnthropicReportsSafetyRefusalExplicitly(): void
    {
        $http = $this->createMock(AiHttpClient::class);
        $http->method('post')->willReturn([
            'stop_reason' => 'refusal',
            'content' => [],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('안전 정책에 따라');
        (new AnthropicMessagesProvider($http))->generate('key', 'model', 'system', 'user');
    }

    public function testAutoplaySecondsAreClampedAfterProviderResponse(): void
    {
        $http = $this->createMock(AiHttpClient::class);
        $http->method('post')->willReturn([
            'output_text' => $this->resultJson(999),
        ]);

        $result = (new GeminiInteractionsProvider($http))->generate('key', 'model', 'system', 'user');

        $this->assertSame(30, $result['behavior']['autoplay_seconds']);
    }

    public function testBehaviorTypesAreFilteredAndDeduplicatedAfterProviderResponse(): void
    {
        $http = $this->createMock(AiHttpClient::class);
        $http->method('post')->willReturn([
            'output_text' => $this->resultJson(0, ['tabs', 'tabs', 'unsupported', 'accordion']),
        ]);

        $result = (new GeminiInteractionsProvider($http))->generate('key', 'model', 'system', 'user');

        $this->assertSame(['tabs', 'accordion'], $result['behavior']['types']);
    }

    private function attachments(): array
    {
        $path = __FILE__;
        return [
            ['kind' => 'image', 'extension' => 'png', 'mime_type' => 'image/png', 'path' => $path, 'name' => 'reference.png', 'text' => ''],
            ['kind' => 'document', 'extension' => 'pdf', 'mime_type' => 'application/pdf', 'path' => $path, 'name' => 'guide.pdf', 'text' => ''],
            ['kind' => 'document', 'extension' => 'docx', 'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'path' => $path, 'name' => 'guide.docx', 'text' => 'reference text'],
        ];
    }

    private function resultJson(int $autoplaySeconds = 0, array $behaviorTypes = []): string
    {
        return json_encode([
            'html' => '<section></section>', 'css' => '', 'notes' => '',
            'behavior' => ['types' => $behaviorTypes, 'autoplay_seconds' => $autoplaySeconds],
        ], JSON_THROW_ON_ERROR);
    }

    private function assertPortableSchema(array $schema): void
    {
        $walk = function (array $node) use (&$walk): void {
            $this->assertArrayNotHasKey('minimum', $node);
            $this->assertArrayNotHasKey('maximum', $node);
            foreach ($node as $value) {
                if (is_array($value)) {
                    $walk($value);
                }
            }
        };

        $walk($schema);
    }
}
