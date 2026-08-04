<?php

namespace Tests\Unit\Service\Block;

use Mublo\Core\Block\BlockRegistry;
use Mublo\Service\Block\BlockKitExporter;
use PHPUnit\Framework\TestCase;

/**
 * 블록 킷 1.1 스택 지원 (계획 13.4).
 *
 * exporter 는 DB 접근이 필요하므로 여기서는 계약 상수·형식 판정 규칙과
 * applier 버전 검증(1.0/1.1 수용, 상위 버전 거부)을 단위 수준에서 고정한다.
 * 실 round-trip 은 통합 테스트(BlockKitRepositoryTest 계열)와 단계 7 회귀가
 * 담당한다.
 */
class BlockKitStackTest extends TestCase
{
    protected function setUp(): void
    {
        BlockRegistry::reset();
        BlockRegistry::hasContentType('html');
    }

    protected function tearDown(): void
    {
        BlockRegistry::reset();
    }

    public function testFormatVersionConstants(): void
    {
        // 조건부 버전 정책 (계획 9.1): single 전용 1.0 / 스택 포함 1.1
        $this->assertSame('1.0', BlockKitExporter::KIT_FORMAT_VERSION);
        $this->assertSame('1.1', BlockKitExporter::KIT_FORMAT_VERSION_STACK);
    }

    public function testApplierRejectsUnknownHigherFormatVersion(): void
    {
        $applier = $this->makeApplier();

        $kit = $this->minimalKit();
        $kit['format_version'] = '9.9';

        $result = $applier->dryRun($kit, ['domain_id' => 1]);

        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('9.9', implode(' ', $result['errors']));
    }

    public function testApplierAcceptsMissingAndKnownVersions(): void
    {
        $applier = $this->makeApplier();

        foreach ([null, '1.0', '1.1'] as $version) {
            $kit = $this->minimalKit();
            if ($version !== null) {
                $kit['format_version'] = $version;
            }
            $result = $applier->dryRun($kit, ['domain_id' => 1]);
            $versionErrors = array_filter(
                $result['errors'],
                static fn (string $e) => str_contains($e, '형식 버전')
            );
            $this->assertSame([], array_values($versionErrors), "버전 {$version} 이 거부되었다");
        }
    }

    public function testStackKitColumnNormalizesContentsThroughKitImportContext(): void
    {
        $applier = $this->makeApplier();

        $kit = $this->minimalKit();
        $kit['format_version'] = '1.1';
        $kit['rows'][0]['columns'] = [[
            'content_mode' => 'stack',
            'pc_content_gap' => 16,
            // 이중 표현 scalar (구버전용) — 신규 importer 는 contents 를 권위로 사용
            'content_type' => 'html',
            'content_config' => ['html' => '<p>mirror</p>'],
            'contents' => [
                ['content_type' => 'html', 'content_config' => ['html' => '<p>a</p>'], 'is_active' => 1],
                ['content_type' => 'ghost_ext', 'content_kind' => 'PLUGIN', 'is_active' => 1],
            ],
        ]];

        $result = $applier->dryRun($kit, ['domain_id' => 1]);

        $this->assertSame([], $result['errors']);
        $column = $result['normalized_rows'][0]['columns'][0];
        $this->assertSame('stack', $column['content_mode']);
        $this->assertCount(2, $column['contents']);
        // 미설치 확장 타입 보존 (kit_import 의 allowUnresolvedExtension)
        $this->assertSame('ghost_ext', $column['contents'][1]['content_type']);
        // 스택 칸의 scalar 는 서버 미러 소유 — 제출값은 정규화에서 제거
        $this->assertArrayNotHasKey('content_type', $column);
    }

    public function testDryRunTraversesStackSecurityAndSetupMetadata(): void
    {
        $applier = $this->makeApplier();
        $kit = $this->minimalKit();
        $kit['format_version'] = '1.1';
        $kit['contains_script'] = false;
        $kit['rows'][0]['columns'] = [[
            'content_mode' => 'stack',
            'contents' => [
                [
                    'content_type' => 'html',
                    'title_config' => ['pc_image' => '/storage/domain/1/title.webp'],
                    'content_config' => [
                        'slides' => [['html' => '<img src="/storage/domain/1/body.webp">']],
                        'js' => 'console.log("stack child")',
                    ],
                ],
                [
                    'content_type' => 'ghost_ext',
                    'content_kind' => 'PLUGIN',
                    'kit_hint' => 'configure me',
                ],
            ],
        ]];

        $result = $applier->dryRun($kit, ['domain_id' => 1]);

        $this->assertContains(
            'contains_script=false로 표시된 블록 킷에 실제 JS 콘텐츠가 포함되어 있습니다.',
            $result['errors']
        );
        $this->assertTrue($result['summary']['contains_script']);
        $this->assertContains('ghost_ext', $result['summary']['missing_block_types']);
        $this->assertStringContainsString('업로드 이미지 경로', implode(' ', $result['warnings']));

        $setups = $result['summary']['needs_setup'];
        $this->assertSame('image_missing', $setups[0]['reason']);
        $this->assertSame(0, $setups[0]['content_index']);
        $this->assertSame('extension_missing', $setups[1]['reason']);
        $this->assertSame(1, $setups[1]['content_index']);

        $normalizedContent = $result['normalized_rows'][0]['columns'][0]['contents'][0];
        $this->assertArrayNotHasKey('pc_image', $normalizedContent['title_config']);
    }

    private function makeApplier(): \Mublo\Service\Block\BlockKitApplier
    {
        return new \Mublo\Service\Block\BlockKitApplier(
            new \Mublo\Service\Block\BlockContentSanitizer(),
            $this->createMock(\Mublo\Repository\Block\BlockRowRepository::class),
            $this->createMock(\Mublo\Repository\Block\BlockColumnRepository::class),
            $this->createMock(\Mublo\Repository\Block\BlockPageRepository::class),
            $this->createMock(\Mublo\Service\Block\BlockRenderService::class),
            $this->createMock(\Mublo\Service\Domain\DomainSettingsService::class),
            $this->createMock(\Mublo\Service\System\InstallIdProvider::class),
            new \Mublo\Service\Extension\ExtensionCompatibility(),
            new \Mublo\Service\Block\BlockColumnPayloadNormalizer(
                new \Mublo\Service\Block\BlockContentSanitizer(),
                new \Mublo\Service\Block\BlockSkinService()
            ),
            new \Mublo\Service\Block\MainScreenComposition()
        );
    }

    /** @return array<string, mixed> */
    private function minimalKit(): array
    {
        return [
            'format' => BlockKitExporter::KIT_FORMAT,
            'name' => 'test kit',
            'target' => ['scope' => 'position', 'position' => 'index', 'menu' => ''],
            'rows' => [[
                'width_type' => 1,
                'column_count' => 1,
                'columns' => [[
                    'content_type' => 'html',
                    'content_config' => ['html' => '<p>hi</p>'],
                ]],
            ]],
        ];
    }
}
