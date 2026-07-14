<?php

namespace Tests\Unit\Service\Block;

use Mublo\Core\Block\BlockRegistry;
use Mublo\Service\Block\BlockColumnPayloadNormalizer;
use Mublo\Service\Block\BlockColumnWriteContext;
use Mublo\Service\Block\BlockContentSanitizer;
use Mublo\Service\Block\BlockSkinService;
use PHPUnit\Framework\TestCase;

class BlockColumnPayloadNormalizerTest extends TestCase
{
    private BlockColumnPayloadNormalizer $normalizer;

    protected function setUp(): void
    {
        BlockRegistry::reset();
        BlockRegistry::hasContentType('html');
        $this->normalizer = new BlockColumnPayloadNormalizer(
            new BlockContentSanitizer(),
            new BlockSkinService()
        );
    }

    protected function tearDown(): void
    {
        BlockRegistry::reset();
    }

    public function testInteractiveWriteRejectsUnknownType(): void
    {
        $result = $this->normalizer->normalize([
            'content_type' => 'missing_extension',
            'content_kind' => 'PLUGIN',
        ], BlockColumnWriteContext::interactive(1));

        $this->assertFalse($result->isOk());
        $this->assertSame('unregistered_content_type', $result->getErrors()[0]['code']);
    }

    public function testRegisteredKindIsDerivedAndMismatchIsRejected(): void
    {
        $derived = $this->normalizer->normalize([
            'content_type' => 'html',
        ], BlockColumnWriteContext::interactive(1));

        $this->assertTrue($derived->isOk());
        $this->assertSame('CORE', $derived->getNormalizedColumns()[0]['content_kind']);

        $mismatch = $this->normalizer->normalize([
            'content_type' => 'html',
            'content_kind' => 'PLUGIN',
        ], BlockColumnWriteContext::interactive(1));

        $this->assertFalse($mismatch->isOk());
        $this->assertSame('content_kind_mismatch', $mismatch->getErrors()[0]['code']);
    }

    public function testMissingSkinIsRejected(): void
    {
        $result = $this->normalizer->normalize([
            'content_type' => 'image',
            'content_kind' => 'CORE',
            'content_skin' => 'definitely_missing',
        ], BlockColumnWriteContext::interactive(1));

        $this->assertFalse($result->isOk());
        $this->assertSame('missing_skin', $result->getErrors()[0]['code']);
    }

    public function testHtmlAndSlidesAreSanitizedAfterJsonNormalization(): void
    {
        $result = $this->normalizer->normalize([
            'content_type' => 'html',
            'content_kind' => 'CORE',
            'content_config' => json_encode([
                'html' => '<p onclick="alert(1)">safe</p><script>alert(1)</script>',
                'slides' => [['html' => '<img src="/x.png" onerror="alert(1)">']],
                'css' => '.x{color:red}</style><script>alert(1)</script>',
            ]),
            'attacker_field' => 'must be removed',
        ], BlockColumnWriteContext::interactive(1));

        $this->assertTrue($result->isOk());
        $column = $result->getNormalizedColumns()[0];
        $this->assertArrayNotHasKey('attacker_field', $column);
        $this->assertIsArray($column['content_config']);
        $this->assertStringContainsString('safe', $column['content_config']['html']);
        $this->assertStringNotContainsStringIgnoringCase('<script', $column['content_config']['html']);
        $this->assertStringNotContainsStringIgnoringCase('onclick', $column['content_config']['html']);
        $this->assertStringNotContainsStringIgnoringCase('onerror', $column['content_config']['slides'][0]['html']);
        $this->assertStringNotContainsStringIgnoringCase('</style', $column['content_config']['css']);
    }

    public function testInvalidJsonIsReportedInsteadOfSilentlyBecomingNull(): void
    {
        $result = $this->normalizer->normalize([
            'content_type' => 'html',
            'content_config' => '{not-json',
        ], BlockColumnWriteContext::interactive(1));

        $this->assertFalse($result->isOk());
        $this->assertSame('invalid_json', $result->getErrors()[0]['code']);
        $this->assertSame('content_config', $result->getErrors()[0]['field']);
    }

    public function testKitImportPreservesUnresolvedExtensionPayload(): void
    {
        $payload = [
            'content_type' => 'missing_extension',
            'content_kind' => 'PACKAGE',
            'content_config' => ['html' => '<script>extension-owned</script>'],
        ];

        $result = $this->normalizer->normalize($payload, BlockColumnWriteContext::kitImport(1));

        $this->assertTrue($result->isOk());
        $this->assertSame('unresolved_extension', $result->getWarnings()[0]['code']);
        $this->assertSame($payload, $result->getNormalizedColumns()[0]);
    }

    public function testRawJsAndIncludeFollowContextPermissions(): void
    {
        $rawJs = $this->normalizer->normalize([
            'content_type' => 'html',
            'content_config' => ['js' => 'alert(1)'],
        ], BlockColumnWriteContext::interactive(1));
        $include = $this->normalizer->normalize([
            'content_type' => 'include',
        ], BlockColumnWriteContext::interactive(1));

        $this->assertSame('raw_js_not_allowed', $rawJs->getErrors()[0]['code']);
        $this->assertSame('include_not_allowed', $include->getErrors()[0]['code']);

        $trusted = $this->normalizer->normalizeMany([
            ['content_type' => 'html', 'content_config' => ['js' => 'alert(1)']],
            ['content_type' => 'include'],
        ], BlockColumnWriteContext::internalSeed(1));

        $this->assertTrue($trusted->isOk());
    }

    public function testNormalizeManyRejectsMoreThanFourColumns(): void
    {
        $result = $this->normalizer->normalizeMany(array_fill(0, 5, [
            'content_type' => 'html',
        ]), BlockColumnWriteContext::interactive(1));

        $this->assertFalse($result->isOk());
        $this->assertSame('too_many_columns', $result->getErrors()[0]['code']);
    }

    public function testUnsafeBorderConfigValueIsRejected(): void
    {
        // 공격자가 border color 에 따옴표를 심어 style 속성 탈출 → 저장형 XSS 를 노림
        $result = $this->normalizer->normalize([
            'content_type' => 'html',
            'border_config' => json_encode([
                'width' => '1px',
                'style' => 'solid',
                'color' => '#fff" onmouseover="alert(1)',
            ]),
        ], BlockColumnWriteContext::interactive(1));

        $this->assertFalse($result->isOk());
        $this->assertContains('unsafe_style_value', array_column($result->getErrors(), 'code'));
        // 위험 config 는 저장 데이터에서 제거된다
        $this->assertNull($result->getNormalizedColumns()[0]['border_config']);
    }

    public function testUnsafeBackgroundConfigValueIsRejected(): void
    {
        $result = $this->normalizer->normalize([
            'content_type' => 'html',
            'background_config' => json_encode([
                'image' => "x'); background-image: url('//evil.example/x",
            ]),
        ], BlockColumnWriteContext::interactive(1));

        $this->assertFalse($result->isOk());
        $this->assertContains('unsafe_style_value', array_column($result->getErrors(), 'code'));
    }

    public function testLegitimateStyleConfigPasses(): void
    {
        // 정상 색상/그라데이션/경로/길이 값은 통과해야 한다(오탐 방지)
        $result = $this->normalizer->normalize([
            'content_type' => 'html',
            'background_config' => json_encode([
                'gradient' => 'linear-gradient(90deg, #ffffff 0%, #000000 100%)',
                'image' => '/storage/D1/banner/welcome.png',
                'position' => 'center center',
            ]),
            'border_config' => json_encode([
                'width' => '1px',
                'style' => 'solid',
                'color' => 'rgba(0,0,0,0.5)',
                'radius' => '8px',
            ]),
        ], BlockColumnWriteContext::interactive(1));

        $this->assertTrue($result->isOk());
        $column = $result->getNormalizedColumns()[0];
        $this->assertIsArray($column['border_config']);
        $this->assertSame('rgba(0,0,0,0.5)', $column['border_config']['color']);
        $this->assertIsArray($column['background_config']);
    }
}
