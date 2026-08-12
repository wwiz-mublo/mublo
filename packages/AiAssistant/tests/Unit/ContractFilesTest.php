<?php
declare(strict_types=1);

namespace Tests\AiAssistant\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ContractFilesTest extends TestCase
{
    #[DataProvider('contractFiles')]
    public function testContractJsonIsValid(string $relativePath, string $version): void
    {
        $path = dirname(__DIR__, 4) . '/docs/openapi/' . $relativePath;
        self::assertFileExists($path);
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        $actual = $relativePath === 'openapi.json'
            ? ($decoded['openapi'] ?? null)
            : ($decoded['properties']['schema_version']['const'] ?? null);
        self::assertSame($version, $actual);
    }

    /** @return iterable<string, array{string, string}> */
    public static function contractFiles(): iterable
    {
        yield 'openapi' => ['openapi.json', '3.1.0'];
        yield 'sync' => ['schemas/sync-record-v1.schema.json', 'sync-record-v1'];
        yield 'subscription' => ['schemas/subscription-v1.schema.json', 'subscription-v1'];
        yield 'crypto' => ['schemas/crypto-envelope-v1.schema.json', 'crypto-envelope-v1'];
        yield 'interaction' => ['schemas/interaction-upload-v2.schema.json', 'interaction-upload-v2'];
        yield 'interaction v3' => ['schemas/interaction-upload-v3.schema.json', 'interaction-upload-v3'];
        yield 'analysis batch create' => ['schemas/analysis-batch-create-v1.schema.json', 'analysis-batch-create-v1'];
        yield 'analysis consent' => ['schemas/analysis-consent-v1.schema.json', 'analysis-consent-v1'];
        yield 'analysis batch status' => ['schemas/analysis-batch-status-v1.schema.json', 'analysis-batch-status-v1'];
        yield 'analysis manifest' => ['schemas/analysis-manifest-v1.schema.json', 'analysis-manifest-v1'];
        yield 'worker heartbeat' => ['schemas/worker-heartbeat-v1.schema.json', 'worker-heartbeat-v1'];
        yield 'worker job v2' => ['schemas/worker-job-v2.schema.json', 'worker-job-v2'];
        yield 'worker complete v2' => ['schemas/worker-complete-v2.schema.json', 'worker-complete-v2'];
        yield 'customer directory' => ['schemas/customer-directory-v1.schema.json', 'customer-directory-v1'];
        yield 'contact permission' => ['schemas/contact-permission-v1.schema.json', 'contact-permission-v1'];
        yield 'messaging eligibility' => ['schemas/messaging-eligibility-v1.schema.json', 'messaging-eligibility-v1'];
        yield 'suppression event' => ['schemas/suppression-event-v1.schema.json', 'suppression-event-v1'];
        yield 'campaign snapshot' => ['schemas/campaign-recipient-snapshot-v1.schema.json', 'campaign-recipient-snapshot-v1'];
        yield 'campaign dispatch policy' => ['schemas/campaign-dispatch-policy-v1.schema.json', 'campaign-dispatch-policy-v1'];
        yield 'campaign dispatch preflight' => ['schemas/campaign-dispatch-preflight-v1.schema.json', 'campaign-dispatch-preflight-v1'];
        yield 'analysis' => ['schemas/analysis-result-v1.schema.json', 'analysis-result-v1'];
        yield 'analysis v2' => ['schemas/analysis-result-v2.schema.json', 'analysis-result-v2'];
        yield 'schedule' => ['schemas/schedule-message-v1.schema.json', 'schedule-message-v1'];
        yield 'schedule dispatch command' => [
            'schemas/schedule-dispatch-command-v1.schema.json',
            'schedule-dispatch-command-v1',
        ];
    }

    public function testCryptoVectorIsValidAndSelfConsistent(): void
    {
        $path = dirname(__DIR__, 4) . '/docs/contracts/crypto-vector-v1.json';
        self::assertFileExists($path);
        $vector = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('crypto-vector-v1', $vector['schema_version'] ?? null);
        self::assertSame($vector['plaintext_sha256'], hash('sha256', (string) $vector['payload_utf8']));
        self::assertSame($vector['aad_sha256'], hash('sha256', (string) $vector['aad_utf8']));
        self::assertSame($vector['interaction_set']['sha256'], hash('sha256', (string) $vector['interaction_set']['canonical_utf8']));
        self::assertSame($vector['interaction_set']['empty_sha256'], hash('sha256', ''));
        self::assertSame($vector['customer_set']['sha256'], hash('sha256', (string) $vector['customer_set']['canonical_utf8']));

        $tag = base64_decode((string) $vector['aes_256_gcm']['tag_base64'], true);
        $plaintext = openssl_decrypt(
            (string) base64_decode((string) $vector['aes_256_gcm']['ciphertext_base64'], true),
            'aes-256-gcm',
            (string) base64_decode((string) $vector['aes_256_gcm']['key_base64'], true),
            OPENSSL_RAW_DATA,
            (string) base64_decode((string) $vector['aes_256_gcm']['iv_base64'], true),
            is_string($tag) ? $tag : '',
            (string) $vector['aad_utf8']
        );
        self::assertSame($vector['payload_utf8'], $plaintext);
    }
}
