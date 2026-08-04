<?php

namespace Tests\Unit\Core\Block;

use InvalidArgumentException;
use Mublo\Core\Block\BlockRegistry;
use PHPUnit\Framework\TestCase;

class BlockRegistryCapabilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        BlockRegistry::reset();
    }

    protected function tearDown(): void
    {
        BlockRegistry::reset();
        parent::tearDown();
    }

    public function testExplicitCapabilitiesAreExposedToAdminAsOneNormalizedContract(): void
    {
        BlockRegistry::registerContentType(
            type: 'capability-test',
            kind: 'PLUGIN',
            title: 'Capability test',
            rendererClass: 'MissingRendererIsAllowedInThisTest',
            options: [
                'skipValidation' => true,
                'skinBasePath' => '/legacy/skin/path',
                'hasItems' => true,
                'hasStyle' => true,
                'adminScript' => '/admin.js',
                'capabilities' => [
                    'skin' => false,
                    'items' => false,
                    'count' => true,
                    'style' => false,
                    'aos' => false,
                    'customConfig' => true,
                ],
            ],
        );

        $option = $this->findOption('capability-test');

        $this->assertSame([
            'skin' => false,
            'items' => false,
            'count' => true,
            'style' => false,
            'aos' => false,
            'customConfig' => true,
        ], $option['capabilities']);
        // 구 소비자를 제거하기 전까지 기존 필드도 유지한다.
        $this->assertTrue($option['hasItems']);
        $this->assertTrue($option['hasStyle']);
    }

    public function testLegacyOptionsReceiveBackwardCompatibleCapabilities(): void
    {
        BlockRegistry::registerContentType(
            type: 'legacy-test',
            kind: 'PLUGIN',
            title: 'Legacy test',
            rendererClass: 'MissingRendererIsAllowedInThisTest',
            options: [
                'skipValidation' => true,
                'skinBasePath' => '/skins',
                'hasStyle' => true,
            ],
        );

        $this->assertSame([
            'skin' => true,
            'items' => false,
            'count' => true,
            'style' => true,
            'aos' => true,
            'customConfig' => false,
        ], $this->findOption('legacy-test')['capabilities']);
    }

    public function testUnknownCapabilityIsRejectedAtRegistration(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown');

        BlockRegistry::registerContentType(
            type: 'invalid-capability',
            kind: 'PLUGIN',
            title: 'Invalid capability',
            rendererClass: 'MissingRendererIsAllowedInThisTest',
            options: [
                'skipValidation' => true,
                'capabilities' => ['unknown' => true],
            ],
        );
    }

    public function testCapabilityValuesMustBeBoolean(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('bool');

        BlockRegistry::registerContentType(
            type: 'invalid-capability-value',
            kind: 'PLUGIN',
            title: 'Invalid capability value',
            rendererClass: 'MissingRendererIsAllowedInThisTest',
            options: [
                'skipValidation' => true,
                'capabilities' => ['count' => 1],
            ],
        );
    }

    public function testCoreTypesDeclareTheirAdminUiCapabilitiesExplicitly(): void
    {
        $expected = [
            'html' => [false, false, false, false, true, false],
            'image' => [false, false, false, true, true, false],
            'movie' => [false, false, false, false, true, false],
            'outlogin' => [true, false, false, false, true, false],
            'menu' => [true, true, false, false, true, false],
            'include' => [false, false, false, false, false, false],
        ];

        foreach ($expected as $type => $values) {
            $this->assertSame(array_combine(BlockRegistry::CAPABILITY_KEYS, $values), $this->findOption($type)['capabilities']);
        }
    }

    public function testCapabilitiesHelperReturnsTheCompleteSchema(): void
    {
        $this->assertSame([
            'skin' => true,
            'items' => false,
            'count' => true,
            'style' => false,
            'aos' => true,
            'customConfig' => false,
        ], BlockRegistry::capabilities(
            skin: true,
            items: false,
            count: true,
            style: false,
            aos: true,
            customConfig: false,
        ));
    }

    public function testCoreTypesExposeEditorAdaptersInsteadOfRequiringTypeKnowledge(): void
    {
        $expected = [
            'html' => ['adapter' => 'html', 'mode' => 'modal'],
            'image' => ['adapter' => 'image', 'mode' => 'modal'],
            'movie' => ['adapter' => 'movie', 'mode' => 'modal'],
            'outlogin' => ['adapter' => 'outlogin', 'mode' => 'inline'],
            'menu' => ['adapter' => 'menu', 'mode' => 'inline'],
            'include' => ['adapter' => 'include', 'mode' => 'inline'],
        ];

        foreach ($expected as $type => $editor) {
            $this->assertSame($editor, $this->findOption($type)['editor']);
        }
    }

    public function testInvalidEditorMetadataIsRejectedAtRegistration(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('mode');

        BlockRegistry::registerContentType(
            type: 'invalid-editor',
            kind: 'PLUGIN',
            title: 'Invalid editor',
            rendererClass: 'MissingRendererIsAllowedInThisTest',
            options: [
                'skipValidation' => true,
                'editor' => ['adapter' => 'example', 'mode' => 'popup'],
            ],
        );
    }

    private function findOption(string $type): array
    {
        foreach (BlockRegistry::getContentTypeOptions() as $option) {
            if ($option['value'] === $type) {
                return $option;
            }
        }

        self::fail("Missing content type option: {$type}");
    }
}
