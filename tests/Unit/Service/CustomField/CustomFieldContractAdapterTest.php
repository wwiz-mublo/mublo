<?php

namespace Tests\Unit\Service\CustomField;

use Mublo\Service\CustomField\CustomFieldFileHandler;
use Mublo\Service\CustomField\CustomFieldFileManager;
use Mublo\Service\CustomField\CustomFieldValueValidator;
use PHPUnit\Framework\TestCase;

final class CustomFieldContractAdapterTest extends TestCase
{
    public function testValidatorPreservesTypeRegexAndNormalizationRules(): void
    {
        $validator = new CustomFieldValueValidator();

        $this->assertTrue($validator->isEmpty('file', '__delete__'));
        $this->assertTrue($validator->validateType('email', 'user@example.com')->isSuccess());
        $this->assertTrue($validator->validatePattern('ABC-12', '/^[A-Z]+-\d+$/')->isSuccess());
        $this->assertSame('a,b', $validator->normalizeForStorage('checkbox', ['a', 'b']));
    }

    public function testFileManagerParsesMetadataWithoutExposingHandlerStatics(): void
    {
        $handler = $this->createStub(CustomFieldFileHandler::class);
        $manager = new CustomFieldFileManager($handler);
        $meta = $manager->parseFileMeta(json_encode([
            'disk' => 'secure',
            'relative_path' => 'orders/1',
            'stored_name' => 'stored.pdf',
            'original_name' => 'quote.pdf',
            'size' => 123,
        ]));

        $this->assertSame('quote.pdf', $meta['filename'] ?? null);
        $this->assertSame('secure', $meta['disk'] ?? null);
        $this->assertNull($meta['url'] ?? null);
    }
}
