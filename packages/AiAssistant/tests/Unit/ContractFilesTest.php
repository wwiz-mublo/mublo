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
        yield 'crypto' => ['schemas/crypto-envelope-v1.schema.json', 'crypto-envelope-v1'];
        yield 'interaction' => ['schemas/interaction-upload-v2.schema.json', 'interaction-upload-v2'];
        yield 'customer directory' => ['schemas/customer-directory-v1.schema.json', 'customer-directory-v1'];
        yield 'contact permission' => ['schemas/contact-permission-v1.schema.json', 'contact-permission-v1'];
        yield 'messaging eligibility' => ['schemas/messaging-eligibility-v1.schema.json', 'messaging-eligibility-v1'];
        yield 'suppression event' => ['schemas/suppression-event-v1.schema.json', 'suppression-event-v1'];
        yield 'campaign snapshot' => ['schemas/campaign-recipient-snapshot-v1.schema.json', 'campaign-recipient-snapshot-v1'];
        yield 'campaign dispatch policy' => ['schemas/campaign-dispatch-policy-v1.schema.json', 'campaign-dispatch-policy-v1'];
        yield 'campaign dispatch preflight' => ['schemas/campaign-dispatch-preflight-v1.schema.json', 'campaign-dispatch-preflight-v1'];
        yield 'analysis' => ['schemas/analysis-result-v1.schema.json', 'analysis-result-v1'];
        yield 'schedule' => ['schemas/schedule-message-v1.schema.json', 'schedule-message-v1'];
    }
}
