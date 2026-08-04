<?php

namespace Tests\Unit\Service\Block;

use Mublo\Infrastructure\Database\Database;
use Mublo\Repository\Block\BlockColumnRepository;
use Mublo\Repository\Block\BlockPageRepository;
use Mublo\Repository\Block\BlockRowRepository;
use Mublo\Service\Block\BlockContentSanitizer;
use Mublo\Service\Block\BlockColumnPayloadNormalizer;
use Mublo\Service\Block\BlockSkinService;
use Mublo\Service\Block\BlockKitApplier;
use Mublo\Service\Block\BlockRenderService;
use Mublo\Service\Block\MainScreenComposition;
use Mublo\Core\App\Application;
use Mublo\Core\Event\Block\BlockPageMenuSyncEvent;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Service\Domain\DomainSettingsService;
use Mublo\Service\Extension\ExtensionCompatibility;
use Mublo\Service\System\InstallIdProvider;
use PHPUnit\Framework\TestCase;

class BlockKitApplierTest extends TestCase
{
    private function makeApplier(
        ?BlockRowRepository $rowRepository = null,
        ?BlockColumnRepository $columnRepository = null,
        ?BlockRenderService $renderService = null,
        ?DomainSettingsService $domainSettingsService = null,
        ?InstallIdProvider $installIdProvider = null,
        ?BlockPageRepository $pageRepository = null,
        ?EventDispatcher $eventDispatcher = null
    ): BlockKitApplier {
        return new BlockKitApplier(
            new BlockContentSanitizer(),
            $rowRepository ?? $this->createMock(BlockRowRepository::class),
            $columnRepository ?? $this->createMock(BlockColumnRepository::class),
            $pageRepository ?? $this->createMock(BlockPageRepository::class),
            $renderService ?? $this->createMock(BlockRenderService::class),
            $domainSettingsService ?? $this->createMock(DomainSettingsService::class),
            $installIdProvider ?? $this->createMock(InstallIdProvider::class),
            new ExtensionCompatibility(),
            new BlockColumnPayloadNormalizer(new BlockContentSanitizer(), new BlockSkinService()),
            new MainScreenComposition(),
            $eventDispatcher
        );
    }
    public function testDryRunSanitizesHtmlAndWarnsForMissingContentType(): void
    {
        $applier = $this->makeApplier();

        $result = $applier->dryRun([
            'format' => 'mublo-starter-kit',
            'contains_script' => false,
            'rows' => [[
                'columns' => [[
                    'content_type' => 'not_installed',
                    'content_kind' => 'PACKAGE',
                    'content_config' => [
                        'html' => '<p onclick="alert(1)">x</p><script>alert(1)</script>',
                    ],
                ]],
            ]],
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame(['not_installed'], $result['summary']['missing_block_types']);
        $this->assertStringContainsString('설치되지 않은 블록 타입입니다: not_installed', $result['warnings'][0]);
        $this->assertSame(
            '<p onclick="alert(1)">x</p><script>alert(1)</script>',
            $result['normalized_rows'][0]['columns'][0]['content_config']['html']
        );
    }

    public function testDryRunReturnsNormalizerAdjustmentWarnings(): void
    {
        $result = $this->makeApplier()->dryRun([
            'format' => 'mublo-starter-kit',
            'contains_script' => false,
            'rows' => [[
                'columns' => [[
                    'content_mode' => 'stack',
                    'pc_content_gap' => 999,
                    'contents' => [['content_type' => 'html']],
                ]],
            ]],
        ]);

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString(
            'pc_content_gap 값을 0~200 범위로 조정했습니다',
            implode(' ', $result['warnings'])
        );
    }

    /**
     * 블록 킷에 provider 가 있으면 "무엇을 설치해야 하는지" 이름으로 말할 수 있어야 한다.
     * 칸은 여전히 자리를 지킨다 — 확장을 설치하면 되살아난다(설계 6.3).
     *
     * @param string $kind PACKAGE|PLUGIN
     * @param string $label 사용자에게 보여줄 확장 이름
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('providerLabelCases')]
    public function testMissingExtensionWarningNamesTheProvider(string $kind, string $label): void
    {
        $result = $this->makeApplier()->dryRun($this->kitRequiring('product_list', $kind, 'Shop'));

        $this->assertTrue($result['ok'], '확장이 없어도 블록 킷 자체는 적용 가능해야 한다');
        $this->assertSame(
            ["'product_list' 블록을 표시하려면 {$label}를 설치해야 합니다."],
            $result['warnings']
        );

        // 칸은 살아남아 체크리스트에 오른다. 확장을 설치하면 그대로 되살아난다.
        $setup = $result['summary']['needs_setup'][0];
        $this->assertSame('extension_missing', $setup['reason']);
        $this->assertSame($label, $setup['provider']);
    }

    /** @return array<string, array{string, string}> */
    public static function providerLabelCases(): array
    {
        return [
            'package' => ['PACKAGE', 'Shop 패키지'],
            'plugin' => ['PLUGIN', 'Shop 플러그인'],
        ];
    }

    /**
     * provider 는 블록 킷이 실어 온 남의 문자열이다. 관리자 화면에 그대로 찍히므로
     * 형식이 어긋나면 쓰지 않고, 이름 없는 기본 문구로 물러난다.
     *
     * @param mixed $provider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('untrustedProviderCases')]
    public function testMalformedProviderIsIgnored(mixed $provider): void
    {
        $kit = $this->kitRequiring('product_list', 'PACKAGE', 'Shop');
        $kit['requires']['block_types'][0]['provider'] = $provider;

        $result = $this->makeApplier()->dryRun($kit);

        $this->assertSame(['설치되지 않은 블록 타입입니다: product_list'], $result['warnings']);
        $this->assertNull($result['summary']['needs_setup'][0]['provider']);
    }

    /** @return array<string, array{mixed}> */
    public static function untrustedProviderCases(): array
    {
        return [
            'script tag' => ['<script>alert(1)</script>'],
            'html entity' => ['Shop"><img src=x onerror=alert(1)>'],
            'path traversal' => ['../../etc/passwd'],
            'empty' => [''],
            'too long' => [str_repeat('A', 41)],
            'not a string' => [['Shop']],
        ];
    }

    /**
     * kind 를 모르면 "무엇을" 설치하라고 말할 수 없다. 이름만 던지느니 물러난다.
     * (CORE 타입이 미설치라는 건 애초에 말이 안 되는 조합이기도 하다.)
     */
    public function testProviderIsIgnoredWhenKindIsNotAnExtension(): void
    {
        $kit = $this->kitRequiring('product_list', 'CORE', 'Shop');

        $result = $this->makeApplier()->dryRun($kit);

        $this->assertSame(['설치되지 않은 블록 타입입니다: product_list'], $result['warnings']);
        $this->assertNull($result['summary']['needs_setup'][0]['provider']);
    }

    /** requires 가 아예 없는 블록 킷도 깨지지 않는다. */
    public function testMissingRequiresFallsBackToGenericWarning(): void
    {
        $kit = $this->kitRequiring('product_list', 'PACKAGE', 'Shop');
        unset($kit['requires']);

        $result = $this->makeApplier()->dryRun($kit);

        $this->assertSame(['설치되지 않은 블록 타입입니다: product_list'], $result['warnings']);
        $this->assertNull($result['summary']['needs_setup'][0]['provider']);
    }

    // =========================================================================
    // requires.core (설계 6.3) — 차단하지 않고 경고만
    // =========================================================================

    /** 코어가 낡았으면 알려 주되, 적용은 막지 않는다. */
    public function testOutdatedCoreProducesWarningButStillApplies(): void
    {
        $result = $this->makeApplier()->dryRun($this->kitRequiringCore('>' . Application::VERSION));

        $this->assertTrue($result['ok'], '코어 버전은 적용을 차단하지 않는다');
        $this->assertStringContainsString('이 블록 킷은 코어', $result['warnings'][0]);
        $this->assertStringContainsString(Application::VERSION, $result['warnings'][0]);
    }

    /**
     * @param string $constraint 현재 코어가 만족하는 제약
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('satisfiedCoreConstraints')]
    public function testSatisfiedCoreConstraintIsSilent(string $constraint): void
    {
        $result = $this->makeApplier()->dryRun($this->kitRequiringCore($constraint));

        $this->assertSame([], $result['warnings']);
    }

    /** @return array<string, array{string}> */
    public static function satisfiedCoreConstraints(): array
    {
        return [
            'exact' => [Application::VERSION],
            'at least' => ['>=' . Application::VERSION],
            'wildcard' => ['*'],
            // 해석할 수 없는 제약은 만족으로 본다 — 파서 한계가 멀쩡한 블록 킷을 막으면 안 된다.
            'unparseable' => ['바나나'],
        ];
    }

    /** requires.core 가 없거나 빈 문자열이면 요구가 없는 것이다. */
    #[\PHPUnit\Framework\Attributes\DataProvider('absentCoreConstraints')]
    public function testAbsentCoreConstraintIsSilent(mixed $constraint): void
    {
        $kit = $this->kitRequiringCore('*');
        $kit['requires']['core'] = $constraint;

        $this->assertSame([], $this->makeApplier()->dryRun($kit)['warnings']);
    }

    /** @return array<string, array{mixed}> */
    public static function absentCoreConstraints(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'whitespace' => ['   '],
            'not a string' => [['^1.0']],
        ];
    }

    /** 제약은 블록 킷이 실어 온 남의 문자열이다. 경고창을 밀어내지 못하게 자른다. */
    public function testAbsurdlyLongConstraintIsTruncated(): void
    {
        $result = $this->makeApplier()->dryRun(
            $this->kitRequiringCore('>' . Application::VERSION . str_repeat('9', 500))
        );

        $this->assertLessThan(120, mb_strlen($result['warnings'][0]));
        $this->assertStringContainsString('…', $result['warnings'][0]);
    }

    /** @return array<string, mixed> */
    private function kitRequiringCore(string $constraint): array
    {
        return [
            'format' => 'mublo-starter-kit',
            'requires' => ['core' => $constraint],
            'rows' => [[
                'columns' => [['content_type' => 'html']],
            ]],
        ];
    }

    // =========================================================================
    // 도메인 종속 이미지 (설계 4.4)
    // =========================================================================

    /**
     * 내보내기가 걷어냈어도 블록 킷은 제3자 파일이다. 손으로 고친 블록 킷은 경로를 그대로 실어 온다.
     * 그대로 저장하면 남의 설치를 가리키는 경로가 DB 에 들어간다.
     */
    public function testDryRunStripsImagePathsCarriedByAHandEditedKit(): void
    {
        $result = $this->makeApplier()->dryRun($this->kitWithImages());

        $row = $result['normalized_rows'][0];
        $this->assertArrayNotHasKey('image', $row['background_config']);
        $this->assertSame('#fff', $row['background_config']['color']);

        $column = $row['columns'][0];
        $this->assertArrayNotHasKey('image', $column['background_config']);
        $this->assertArrayNotHasKey('pc_image', $column['title_config']);
        $this->assertSame('공지', $column['title_config']['text']);
    }

    /**
     * needs_setup 은 칸 단위라 행 배경을 실을 곳이 없다. 경고로라도 말하지 않으면
     * 운영자는 배경이 왜 비었는지 알 수 없다.
     */
    public function testStrippedRowBackgroundProducesWarning(): void
    {
        $result = $this->makeApplier()->dryRun($this->kitWithImages());

        $this->assertStringContainsString('행 배경 이미지는 블록 킷에 담기지 않습니다', $result['warnings'][0]);
        $this->assertStringContainsString('1행', $result['warnings'][0]);
    }

    /** 걷어낸 칸은 "이미지를 지정하세요" 체크리스트에 오른다. */
    public function testStrippedImageColumnLandsInNeedsSetup(): void
    {
        $result = $this->makeApplier()->dryRun($this->kitWithImages());

        $setup = $result['summary']['needs_setup'][0];
        $this->assertSame('image_missing', $setup['reason']);
        $this->assertSame(0, $setup['row_index']);
    }

    /** 내보내기가 이미 걷어내고 표시만 남긴 블록 킷도 같은 체크리스트에 올라야 한다. */
    public function testKitFlagAloneMarksColumnAsNeedingImage(): void
    {
        $result = $this->makeApplier()->dryRun([
            'format' => 'mublo-starter-kit',
            'rows' => [[
                'columns' => [['content_type' => 'html', 'kit_needs_title_image' => true]],
            ]],
        ]);

        $this->assertSame('image_missing', $result['summary']['needs_setup'][0]['reason']);
    }

    /** 같은 설치로 되돌리는 clone 백업은 경로가 유효하므로 살린다(설계 7.2). */
    public function testSameInstallCloneKeepsImagePaths(): void
    {
        $installId = $this->createMock(InstallIdProvider::class);
        $installId->method('matches')->willReturn(true);

        $kit = $this->kitWithImages();
        $kit['export_mode'] = 'clone';
        $kit['source_install'] = 'same-install';

        $result = $this->makeApplier(installIdProvider: $installId)->dryRun($kit);

        $this->assertSame(
            '/storage/a/row.jpg',
            $result['normalized_rows'][0]['background_config']['image']
        );
        $this->assertSame([], $result['summary']['needs_setup']);
    }

    /** 다른 설치의 clone 블록 킷은 경로가 죽어 있다. 배포 블록 킷과 똑같이 걷어낸다. */
    public function testForeignCloneStripsImagePathsLikeADistributionKit(): void
    {
        $kit = $this->kitWithImages();
        $kit['export_mode'] = 'clone';
        $kit['source_install'] = 'someone-else';

        $result = $this->makeApplier()->dryRun($kit);

        $this->assertArrayNotHasKey('image', $result['normalized_rows'][0]['background_config']);
    }

    /** 본문 HTML 에 박힌 업로드 경로는 걷어낼 수 없다. 깨질 것임을 알려는 준다. */
    public function testInlineUploadPathInHtmlProducesWarning(): void
    {
        $result = $this->makeApplier()->dryRun([
            'format' => 'mublo-starter-kit',
            'rows' => [[
                'columns' => [[
                    'content_type' => 'html',
                    'content_config' => ['html' => '<img src="/storage/2026/01/x.jpg">'],
                ]],
            ]],
        ]);

        $this->assertStringContainsString('1행 1칸', $result['warnings'][0]);
        $this->assertStringContainsString('깨져 보입니다', $result['warnings'][0]);
    }

    /** 업로드 경로가 없는 HTML 은 경고하지 않는다. 늑대 소년이 되면 아무도 안 읽는다. */
    public function testExternalImageUrlDoesNotWarn(): void
    {
        $result = $this->makeApplier()->dryRun([
            'format' => 'mublo-starter-kit',
            'rows' => [[
                'columns' => [[
                    'content_type' => 'html',
                    'content_config' => ['html' => '<img src="https://cdn.example.com/x.jpg">'],
                ]],
            ]],
        ]);

        $this->assertSame([], $result['warnings']);
    }

    /** kit_needs_* 는 블록 킷 전용 키다. DB 로 새어 나가면 안 된다. */
    public function testKitOnlyKeysNeverReachTheDatabasePayload(): void
    {
        $result = $this->makeApplier()->dryRun($this->kitWithImages());

        $row = $result['normalized_rows'][0];
        $this->assertArrayHasKey('kit_needs_bg_image', $row, '정규화 단계에서는 표시가 남는다');

        // 실제 INSERT 는 화이트리스트를 통과한 필드만 쓴다.
        $reflection = new \ReflectionClass(BlockKitApplier::class);
        $rowFields = $reflection->getConstant('ROW_ALLOWED_FIELDS');
        $columnFields = $reflection->getConstant('COLUMN_ALLOWED_FIELDS');

        foreach (['kit_needs_bg_image', 'kit_needs_title_image', 'kit_needs_items', 'kit_hint'] as $key) {
            $this->assertNotContains($key, $rowFields);
            $this->assertNotContains($key, $columnFields);
        }
    }

    /** @return array<string, mixed> */
    private function kitWithImages(): array
    {
        return [
            'format' => 'mublo-starter-kit',
            'rows' => [[
                'background_config' => ['color' => '#fff', 'image' => '/storage/a/row.jpg'],
                'columns' => [[
                    'content_type' => 'html',
                    'background_config' => ['image' => '/storage/a/col.jpg'],
                    'title_config' => ['text' => '공지', 'pc_image' => '/storage/a/t.png'],
                ]],
            ]],
        ];
    }

    /** @return array<string, mixed> */
    private function kitRequiring(string $type, string $kind, string $provider): array
    {
        return [
            'format' => 'mublo-starter-kit',
            'contains_script' => false,
            'requires' => [
                'block_types' => [['type' => $type, 'kind' => $kind, 'provider' => $provider]],
            ],
            'rows' => [[
                'columns' => [['content_type' => $type, 'content_kind' => $kind]],
            ]],
        ];
    }

    public function testDryRunSanitizesRegisteredHtmlContent(): void
    {
        $applier = $this->makeApplier();

        $result = $applier->dryRun([
            'format' => 'mublo-starter-kit',
            'contains_script' => false,
            'rows' => [[
                'columns' => [[
                    'content_type' => 'html',
                    'content_kind' => 'CORE',
                    'content_config' => [
                        'html' => '<p onclick="alert(1)">safe</p><script>alert(1)</script>',
                    ],
                ]],
            ]],
        ]);

        $this->assertTrue($result['ok']);
        $html = $result['normalized_rows'][0]['columns'][0]['content_config']['html'];
        $this->assertStringContainsString('safe', $html);
        $this->assertStringNotContainsStringIgnoringCase('<script', $html);
        $this->assertStringNotContainsStringIgnoringCase('onclick', $html);
    }

    public function testDryRunRejectsIncludeBlock(): void
    {
        $applier = $this->makeApplier();

        $result = $applier->dryRun([
            'format' => 'mublo-starter-kit',
            'rows' => [[
                'columns' => [[
                    'content_type' => 'include',
                    'content_kind' => 'CORE',
                ]],
            ]],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('include 블록은 블록 킷에서 허용되지 않습니다', $result['errors'][0]);
    }

    public function testDryRunRejectsFalseContainsScriptDeclaration(): void
    {
        $applier = $this->makeApplier();

        $result = $applier->dryRun([
            'format' => 'mublo-starter-kit',
            'contains_script' => false,
            'rows' => [[
                'columns' => [[
                    'content_type' => 'html',
                    'content_kind' => 'CORE',
                    'content_config' => ['js' => 'alert(1);'],
                ]],
            ]],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertTrue($result['summary']['contains_script']);
        $this->assertStringContainsString('contains_script=false', $result['errors'][0]);
    }

    public function testDryRunRejectsFalseContainsScriptDeclarationWithJsonStringConfig(): void
    {
        $applier = $this->makeApplier();

        $result = $applier->dryRun([
            'format' => 'mublo-starter-kit',
            'contains_script' => 0,
            'rows' => [[
                'columns' => [[
                    'content_type' => 'html',
                    'content_kind' => 'CORE',
                    'content_config' => json_encode(['js' => 'alert(1);']),
                ]],
            ]],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertTrue($result['summary']['contains_script']);
        $this->assertStringContainsString('contains_script=false', $result['errors'][0]);
    }

    public function testDryRunStripsDatabaseAndDomainIdentifiersFromNormalizedRows(): void
    {
        $applier = $this->makeApplier();

        $result = $applier->dryRun([
            'format' => 'mublo-starter-kit',
            'contains_script' => false,
            'rows' => [[
                'row_id' => 10,
                'domain_id' => 1,
                'columns' => [[
                    'column_id' => 20,
                    'row_id' => 10,
                    'domain_id' => 1,
                    'content_type' => 'html',
                    'content_kind' => 'CORE',
                    'content_config' => ['html' => '<p>safe</p>'],
                ]],
            ]],
        ]);

        $this->assertTrue($result['ok']);
        $this->assertArrayNotHasKey('row_id', $result['normalized_rows'][0]);
        $this->assertArrayNotHasKey('domain_id', $result['normalized_rows'][0]);
        $this->assertArrayNotHasKey('column_id', $result['normalized_rows'][0]['columns'][0]);
        $this->assertArrayNotHasKey('domain_id', $result['normalized_rows'][0]['columns'][0]);
    }

    public function testDryRunFromJsonChecksSizeBeforeDecode(): void
    {
        $applier = $this->makeApplier();

        $result = $applier->dryRunFromJson(str_repeat(' ', BlockKitApplier::MAX_KIT_BYTES + 1));

        $this->assertFalse($result['ok']);
        $this->assertSame(BlockKitApplier::MAX_KIT_BYTES + 1, $result['summary']['bytes']);
        $this->assertStringContainsString('2 MiB', $result['errors'][0]);
    }

    public function testDryRunRejectsScalarColumnsWithoutIterationWarning(): void
    {
        $applier = $this->makeApplier();

        $result = $applier->dryRun([
            'format' => 'mublo-starter-kit',
            'rows' => [['columns' => '<script>']],
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame(0, $result['summary']['column_count']);
        $this->assertStringContainsString('columns가 배열이 아닙니다', $result['errors'][0]);
    }

    public function testDryRunRejectsScalarRowsWithoutIterationWarning(): void
    {
        $applier = $this->makeApplier();

        $result = $applier->dryRun([
            'format' => 'mublo-starter-kit',
            'rows' => 'not-an-array',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame(0, $result['summary']['row_count']);
        $this->assertStringContainsString('rows 배열이 없습니다', $result['errors'][0]);
    }

    // ========================================
    // apply() — 실제 DB 반영
    // ========================================

    public function testApplyWritesToContextDomainIgnoringKitDomainId(): void
    {
        $db = $this->makeDb();
        $rowRepository = $this->makeRowRepository($db);
        $rowRepository->expects($this->once())
            ->method('create')
            ->with($this->callback(function (array $rowData) {
                // 블록 킷이 domain_id=2 를 실어 왔어도 컨텍스트 도메인(7)에만 쓴다
                $this->assertSame(7, $rowData['domain_id']);
                $this->assertSame('index', $rowData['position']);
                $this->assertNull($rowData['page_id']);
                return true;
            }))
            ->willReturn(100);

        $columnRepository = $this->createMock(BlockColumnRepository::class);
        $columnRepository->expects($this->once())
            ->method('replaceByRow')
            ->with(100, 7, $this->anything());

        $renderService = $this->createMock(BlockRenderService::class);
        $renderService->expects($this->once())->method('invalidateDomainCache')->with(7);

        $applier = $this->makeApplier($rowRepository, $columnRepository, $renderService);
        $result = $applier->apply(7, $this->makeKit(['domain_id' => 2]));

        $this->assertTrue($result['ok'], implode(' / ', $result['errors']));
        $this->assertSame(1, $result['summary']['created_rows']);
        $this->assertSame(1, $result['summary']['created_columns']);
    }

    public function testApplyStripsUnknownFieldsBeforeInsert(): void
    {
        $db = $this->makeDb();
        $rowRepository = $this->makeRowRepository($db);
        $rowRepository->method('create')
            ->with($this->callback(function (array $rowData) {
                $this->assertArrayNotHasKey('evil_column', $rowData);
                return true;
            }))
            ->willReturn(100);

        $columnRepository = $this->createMock(BlockColumnRepository::class);
        $columnRepository->method('replaceByRow')
            ->with(100, 1, $this->callback(function (array $columns) {
                $this->assertArrayNotHasKey('evil_column', $columns[0]);
                $this->assertSame('html', $columns[0]['content_type']);
                return true;
            }));

        $kit = $this->makeKit();
        $kit['rows'][0]['evil_column'] = 'DROP';
        $kit['rows'][0]['columns'][0]['evil_column'] = 'DROP';

        $result = $this->makeApplier($rowRepository, $columnRepository)->apply(1, $kit);

        $this->assertTrue($result['ok'], implode(' / ', $result['errors']));
    }

    public function testKitCannotDictateColumnOrdering(): void
    {
        $rowRepository = $this->makeRowRepository($this->makeDb());
        $rowRepository->method('create')->willReturn(100);

        $columnRepository = $this->createMock(BlockColumnRepository::class);
        $columnRepository->method('replaceByRow')
            ->with(100, 1, $this->callback(function (array $columns) {
                // 순서는 replaceByRow 가 배열 순서로 결정한다 — 블록 킷이 값을 심을 수 없다
                $this->assertArrayNotHasKey('column_index', $columns[0]);
                $this->assertArrayNotHasKey('sort_order', $columns[0]);
                return true;
            }));

        $kit = $this->makeKit();
        $kit['rows'][0]['columns'][0]['column_index'] = 99;
        $kit['rows'][0]['columns'][0]['sort_order'] = 77;

        $result = $this->makeApplier($rowRepository, $columnRepository)->apply(1, $kit);

        $this->assertTrue($result['ok'], implode(' / ', $result['errors']));
    }

    public function testReplaceModeDeletesOnlyTargetScope(): void
    {
        $db = $this->makeDb();
        $rowRepository = $this->makeRowRepository($db);
        $rowRepository->expects($this->once())
            ->method('deleteByPosition')
            ->with(1, 'top', 'shop')
            ->willReturn(3);
        $rowRepository->method('create')->willReturn(100);

        $kit = $this->makeKit();
        $kit['target'] = ['kind' => 'position', 'position' => 'top', 'menu_code' => 'shop'];

        $result = $this->makeApplier($rowRepository)->apply(1, $kit, BlockKitApplier::MODE_REPLACE);

        $this->assertTrue($result['ok'], implode(' / ', $result['errors']));
        $this->assertSame(3, $result['summary']['deleted_rows']);
        $this->assertSame('shop', $result['summary']['menu_code']);
    }

    public function testAppendModeDoesNotDeleteAnything(): void
    {
        $db = $this->makeDb();
        $rowRepository = $this->makeRowRepository($db);
        $rowRepository->expects($this->never())->method('deleteByPosition');
        $rowRepository->method('create')->willReturn(100);

        $result = $this->makeApplier($rowRepository)->apply(1, $this->makeKit());

        $this->assertTrue($result['ok'], implode(' / ', $result['errors']));
        $this->assertSame(0, $result['summary']['deleted_rows']);
    }

    public function testApplyRollsBackAndSkipsCacheInvalidationOnFailure(): void
    {
        $db = $this->createMock(Database::class);
        $db->expects($this->once())->method('beginTransaction');
        $db->expects($this->never())->method('commit');
        $db->expects($this->once())->method('rollBack');

        $rowRepository = $this->makeRowRepository($db);
        $rowRepository->method('create')->willReturn(null); // 행 생성 실패

        $renderService = $this->createMock(BlockRenderService::class);
        $renderService->expects($this->never())->method('invalidateDomainCache');

        $result = $this->makeApplier($rowRepository, null, $renderService)->apply(1, $this->makeKit());

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('블록 킷 적용에 실패했습니다', $result['errors'][0]);
    }

    public function testApplyRollsBackWhenTransactionalHistoryHookFails(): void
    {
        $db = $this->createMock(Database::class);
        $db->expects($this->once())->method('beginTransaction');
        $db->expects($this->never())->method('commit');
        $db->expects($this->once())->method('rollBack');

        $rowRepository = $this->makeRowRepository($db);
        $rowRepository->method('create')->willReturn(100);

        $renderService = $this->createMock(BlockRenderService::class);
        $renderService->expects($this->never())->method('invalidateDomainCache');

        $result = $this->makeApplier($rowRepository, null, $renderService)->apply(
            1,
            $this->makeKit(),
            BlockKitApplier::MODE_APPEND,
            false,
            true,
            static function (array $summary): void {
                throw new \RuntimeException('history insert failed');
            }
        );

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('history insert failed', $result['errors'][0]);
    }

    public function testApplyRejectsInvalidKitBeforeTouchingDatabase(): void
    {
        $rowRepository = $this->createMock(BlockRowRepository::class);
        $rowRepository->expects($this->never())->method('getDb');
        $rowRepository->expects($this->never())->method('create');

        $kit = $this->makeKit();
        $kit['contains_script'] = false;
        $kit['rows'][0]['columns'][0]['content_config'] = ['js' => 'alert(1)'];

        $result = $this->makeApplier($rowRepository)->apply(1, $kit);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('contains_script=false', $result['errors'][0]);
    }

    public function testApplyRejectsUnknownTargetKind(): void
    {
        $rowRepository = $this->createMock(BlockRowRepository::class);
        $rowRepository->expects($this->never())->method('getDb');

        $kit = $this->makeKit();
        $kit['target'] = ['kind' => 'everything'];

        $result = $this->makeApplier($rowRepository)->apply(1, $kit);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('position, page 또는 screen', $result['errors'][0]);
    }

    public function testMainScreenKitAppliesRowsPerSlotAndForcesSiteSettings(): void
    {
        $rowRepository = $this->makeRowRepository($this->makeDb());
        $slots = ['topbar', 'subhead', 'contenthead', 'index', 'contentfoot', 'right', 'subfoot'];
        $deleted = [];
        $rowRepository->expects($this->exactly(count($slots)))
            ->method('deleteByPosition')
            ->willReturnCallback(function (int $domainId, string $position, ?string $menuCode) use (&$deleted): int {
                $this->assertSame(1, $domainId);
                $this->assertNull($menuCode);
                $deleted[] = $position;
                return 1;
            });

        $inserted = [];
        $nextId = 100;
        $rowRepository->expects($this->exactly(2))
            ->method('create')
            ->willReturnCallback(function (array $row) use (&$inserted, &$nextId): int {
                $inserted[] = $row;
                return $nextId++;
            });

        $settings = $this->createMock(DomainSettingsService::class);
        $settings->expects($this->once())->method('getSiteConfig')->with(1)->willReturn([
            'site_title' => '기존 사이트',
            'layout_type' => 'full',
            'use_main_layout' => false,
        ]);
        $settings->expects($this->once())
            ->method('saveSettings')
            ->with(1, $this->callback(function (array $data): bool {
                $this->assertSame('기존 사이트', $data['site']['site_title']);
                $this->assertSame('right-sidebar', $data['site']['layout_type']);
                $this->assertTrue($data['site']['use_main_layout']);
                return true;
            }))
            ->willReturn(\Mublo\Core\Result\Result::success('ok'));

        $kit = $this->makeMainScreenKit();
        $result = $this->makeApplier($rowRepository, null, null, $settings)
            ->apply(1, $kit, BlockKitApplier::MODE_REPLACE, false);

        $this->assertTrue($result['ok'], implode(' / ', $result['errors']));
        $this->assertSame($slots, $deleted);
        $this->assertSame(['index', 'right'], array_column($inserted, 'position'));
        $this->assertSame([null, null], array_column($inserted, 'position_menu'));
        $this->assertSame('screen', $result['summary']['target_kind']);
        $this->assertSame('main', $result['summary']['screen']);
        $this->assertSame(7, $result['summary']['deleted_rows']);
        $this->assertNotNull($result['summary']['site_config_snapshot']);
    }

    public function testMainScreenKitRejectsRowsOutsideDeclaredLayoutSlots(): void
    {
        $kit = $this->makeMainScreenKit();
        $kit['rows'][0]['position'] = 'left';

        $result = $this->makeApplier()->dryRun($kit);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('target.slots', implode(' / ', $result['errors']));
    }

    // ========================================
    // 체크리스트 / position 경고
    // ========================================

    public function testNeedsSetupListsColumnsWithMissingExtension(): void
    {
        $kit = $this->makeKit();
        $kit['rows'][0]['columns'][0]['content_type'] = 'not_installed';
        $kit['rows'][0]['columns'][0]['kit_hint'] = '메인 비주얼 배너를 넣으세요.';

        $result = $this->makeApplier()->dryRun($kit);

        $this->assertCount(1, $result['summary']['needs_setup']);
        $this->assertSame('extension_missing', $result['summary']['needs_setup'][0]['reason']);
        $this->assertSame('메인 비주얼 배너를 넣으세요.', $result['summary']['needs_setup'][0]['kit_hint']);
    }

    public function testNeedsSetupUsesExporterMarkerNotHasItemsOption(): void
    {
        // 내보내기가 "참조가 있었는데 비웠다"고 표시한 칸만 설정 필요로 잡는다.
        // options['hasItems'] 는 product_auto 처럼 참조를 저장하지 않는 타입도 true 라 쓸 수 없다.
        $kit = $this->makeKit();
        $kit['rows'][0]['columns'][0]['kit_needs_items'] = true;
        $kit['rows'][0]['columns'][0]['content_items'] = null;

        $result = $this->makeApplier()->dryRun($kit);

        $this->assertCount(1, $result['summary']['needs_setup']);
        $this->assertSame('items_empty', $result['summary']['needs_setup'][0]['reason']);
    }

    public function testColumnWithoutExporterMarkerIsNotListed(): void
    {
        // 표시가 없으면 참조를 쓰지 않는 블록이므로 목록에 오르지 않는다
        $result = $this->makeApplier()->dryRun($this->makeKit());

        $this->assertSame([], $result['summary']['needs_setup']);
    }

    public function testNeedsSetupIndexesAreForcedToIntegers(): void
    {
        // 블록 킷이 rows 를 객체로 주면 foreach 키가 문자열이 된다.
        // 관리자 화면이 innerHTML 로 찍으므로 정수로 강제해야 한다.
        $kit = $this->makeKit();
        $kit['rows'] = ['<img src=x onerror=alert(1)>' => [
            'columns' => [[
                'column_index' => '<script>alert(1)</script>',
                'content_type' => 'not_installed',
            ]],
        ]];

        $result = $this->makeApplier()->dryRun($kit);

        $entry = $result['summary']['needs_setup'][0];
        $this->assertIsInt($entry['row_index']);
        $this->assertIsInt($entry['column_index']);
        $this->assertSame(0, $entry['row_index']);
        $this->assertSame(0, $entry['column_index']);
    }

    public function testKitHintIsNeverWrittenToDatabase(): void
    {
        $rowRepository = $this->makeRowRepository($this->makeDb());
        $rowRepository->method('create')->willReturn(100);

        $columnRepository = $this->createMock(BlockColumnRepository::class);
        $columnRepository->method('replaceByRow')
            ->with(100, 1, $this->callback(function (array $columns) {
                $this->assertArrayNotHasKey('kit_hint', $columns[0]);
                return true;
            }));

        $kit = $this->makeKit();
        $kit['rows'][0]['columns'][0]['kit_hint'] = '배너를 넣으세요';

        $result = $this->makeApplier($rowRepository, $columnRepository)->apply(1, $kit);

        $this->assertTrue($result['ok'], implode(' / ', $result['errors']));
    }

    public function testSidebarKitWithoutLayoutRequestWarnsAboutRendering(): void
    {
        $kit = $this->makeKit();
        $kit['target'] = ['kind' => 'position', 'position' => 'right'];

        $result = $this->makeApplier()->dryRun($kit);

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString("'right' 블록은 현재 레이아웃에서 렌더되지 않을 수 있습니다", $result['warnings'][0]);
    }

    public function testSidebarKitRequestingLayoutDoesNotWarn(): void
    {
        $kit = $this->makeKit();
        $kit['target'] = ['kind' => 'position', 'position' => 'right'];
        $kit['site_settings'] = ['site_config' => ['layout_type' => 'right-sidebar', 'use_main_layout' => true]];

        $result = $this->makeApplier()->dryRun($kit);

        $this->assertSame([], $result['warnings']);
    }

    public function testIndexKitDoesNotWarnAboutRendering(): void
    {
        $result = $this->makeApplier()->dryRun($this->makeKit());

        $this->assertSame([], $result['warnings']);
    }

    // ========================================
    // 페이지 블록 킷
    // ========================================

    public function testPageKitCreatesPageWhenCodeDoesNotExist(): void
    {
        $committed = false;
        $db = $this->createMock(Database::class);
        $db->expects($this->once())->method('beginTransaction');
        $db->expects($this->once())->method('commit')->willReturnCallback(
            static function () use (&$committed): bool {
                $committed = true;
                return true;
            }
        );

        $rowRepository = $this->makeRowRepository($db);
        $rowRepository->method('getNextSortOrderByPage')->willReturn(0);
        $rowRepository->method('create')->willReturn(100);
        $rowRepository->expects($this->never())->method('deleteByPage');

        $pageRepository = $this->createMock(BlockPageRepository::class);
        $pageRepository->method('findByCodeIncludingDeleted')->with(1, 'about')->willReturn(null);
        $pageRepository->expects($this->once())
            ->method('create')
            ->with($this->callback(function (array $page) {
                $this->assertSame(1, $page['domain_id']);
                $this->assertSame('about', $page['page_code']);
                $this->assertSame(3, $page['layout_type']);
                // 블록 킷이 실어 온 DB 식별자는 무시된다
                $this->assertArrayNotHasKey('page_id', $page);
                return true;
            }))
            ->willReturn(55);

        $dispatched = null;
        $eventDispatcher = new EventDispatcher();
        $eventDispatcher->addListener(
            BlockPageMenuSyncEvent::class,
            function (BlockPageMenuSyncEvent $event) use (&$dispatched, &$committed): void {
                $this->assertTrue($committed, '메뉴 동기화 이벤트는 킷 트랜잭션 커밋 후 발행해야 한다.');
                $dispatched = $event;
            }
        );

        $result = $this->makeApplier($rowRepository, null, null, null, null, $pageRepository, $eventDispatcher)
            ->apply(1, $this->makePageKit());

        $this->assertTrue($result['ok'], implode(' / ', $result['errors']));
        $this->assertSame('page', $result['summary']['target_kind']);
        $this->assertSame(55, $result['summary']['page_id']);
        $this->assertTrue($result['summary']['created_page']);
        $this->assertInstanceOf(BlockPageMenuSyncEvent::class, $dispatched);
        $this->assertSame(1, $dispatched->getDomainId());
        $this->assertSame(55, $dispatched->getPageId());
        $this->assertSame('about', $dispatched->getPageCode());
        $this->assertSame('회사소개', $dispatched->getPageTitle());
    }

    public function testPageKitReusesExistingPageAndReplaceDeletesOnlyItsRows(): void
    {
        $rowRepository = $this->makeRowRepository($this->makeDb());
        $rowRepository->method('getNextSortOrderByPage')->willReturn(0);
        $rowRepository->method('create')->willReturn(100);
        $rowRepository->expects($this->once())->method('deleteByPage')->with(55)->willReturn(4);

        $page = \Mublo\Entity\Block\BlockPage::fromArray([
            'page_id' => 55,
            'domain_id' => 1,
            'page_code' => 'about',
        ]);

        $pageRepository = $this->createMock(BlockPageRepository::class);
        $pageRepository->method('findByCodeIncludingDeleted')->willReturn($page);
        $pageRepository->expects($this->never())->method('create');

        $result = $this->makeApplier($rowRepository, null, null, null, null, $pageRepository)
            ->apply(1, $this->makePageKit(), BlockKitApplier::MODE_REPLACE);

        $this->assertTrue($result['ok'], implode(' / ', $result['errors']));
        $this->assertFalse($result['summary']['created_page']);
        $this->assertSame(4, $result['summary']['deleted_rows']);
    }

    public function testPageKitRowsAreBoundToPageNotPosition(): void
    {
        $rowRepository = $this->makeRowRepository($this->makeDb());
        $rowRepository->method('getNextSortOrderByPage')->willReturn(0);
        $rowRepository->expects($this->once())
            ->method('create')
            ->with($this->callback(function (array $rowData) {
                $this->assertSame(55, $rowData['page_id']);
                $this->assertNull($rowData['position']);
                $this->assertNull($rowData['position_menu']);
                return true;
            }))
            ->willReturn(100);

        $pageRepository = $this->createMock(BlockPageRepository::class);
        $pageRepository->method('findByCodeIncludingDeleted')->willReturn(\Mublo\Entity\Block\BlockPage::fromArray([
            'page_id' => 55,
            'domain_id' => 1,
            'page_code' => 'about',
        ]));

        // 블록 킷이 position 을 실어 와도 페이지 행은 페이지에 묶인다
        $kit = $this->makePageKit();
        $kit['rows'][0]['position'] = 'index';

        $result = $this->makeApplier($rowRepository, null, null, null, null, $pageRepository)->apply(1, $kit);

        $this->assertTrue($result['ok'], implode(' / ', $result['errors']));
    }

    public function testPageKitRevivesSoftDeletedPageInsteadOfDuplicateKeyInsert(): void
    {
        $rowRepository = $this->makeRowRepository($this->makeDb());
        $rowRepository->method('getNextSortOrderByPage')->willReturn(0);
        $rowRepository->method('create')->willReturn(100);

        // uk_domain_code 는 is_deleted 를 포함하지 않으므로 툼스톤이 코드를 점유한다
        $deletedPage = \Mublo\Entity\Block\BlockPage::fromArray([
            'page_id' => 55,
            'domain_id' => 1,
            'page_code' => 'about',
            'is_deleted' => 1,
        ]);

        $pageRepository = $this->createMock(BlockPageRepository::class);
        $pageRepository->method('findByCodeIncludingDeleted')->willReturn($deletedPage);
        $pageRepository->expects($this->never())->method('create');
        $pageRepository->expects($this->once())
            ->method('update')
            ->with(55, ['is_deleted' => 0]);

        $dispatched = null;
        $eventDispatcher = new EventDispatcher();
        $eventDispatcher->addListener(
            BlockPageMenuSyncEvent::class,
            static function (BlockPageMenuSyncEvent $event) use (&$dispatched): void {
                $dispatched = $event;
            }
        );

        $result = $this->makeApplier($rowRepository, null, null, null, null, $pageRepository, $eventDispatcher)
            ->apply(1, $this->makePageKit());

        $this->assertTrue($result['ok'], implode(' / ', $result['errors']));
        $this->assertFalse($result['summary']['created_page']);
        $this->assertInstanceOf(BlockPageMenuSyncEvent::class, $dispatched);
    }

    public function testReplaceModeUpdatesExistingPageSettings(): void
    {
        $rowRepository = $this->makeRowRepository($this->makeDb());
        $rowRepository->method('getNextSortOrderByPage')->willReturn(0);
        $rowRepository->method('create')->willReturn(100);
        $rowRepository->method('deleteByPage')->willReturn(2);

        $pageRepository = $this->createMock(BlockPageRepository::class);
        $pageRepository->method('findByCodeIncludingDeleted')->willReturn(
            \Mublo\Entity\Block\BlockPage::fromArray([
                'page_id' => 55,
                'domain_id' => 1,
                'page_code' => 'about',
                'layout_type' => 1,
            ])
        );
        $pageRepository->expects($this->once())
            ->method('update')
            ->with(55, $this->callback(function (array $update) {
                // 교체는 레이아웃까지 블록 킷의 것으로 바꾼다 — 왕복에서 유실되면 안 된다
                $this->assertSame(3, $update['layout_type']);
                $this->assertSame('회사소개', $update['page_title']);
                $this->assertSame(0, $update['is_deleted']);
                $this->assertArrayNotHasKey('page_code', $update);
                return true;
            }));

        $result = $this->makeApplier($rowRepository, null, null, null, null, $pageRepository)
            ->apply(1, $this->makePageKit(), BlockKitApplier::MODE_REPLACE);

        $this->assertTrue($result['ok'], implode(' / ', $result['errors']));
    }

    public function testPageKitWithoutPageTitleIsRejectedBeforeInsert(): void
    {
        $rowRepository = $this->createMock(BlockRowRepository::class);
        $rowRepository->expects($this->never())->method('getDb');

        $kit = $this->makePageKit();
        unset($kit['page']['page_title']);

        $result = $this->makeApplier($rowRepository)->apply(1, $kit);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('page.page_title이 비어 있습니다', $result['errors'][0]);
    }

    public function testPageKitNeverTouchesSiteConfig(): void
    {
        $rowRepository = $this->makeRowRepository($this->makeDb());
        $rowRepository->method('getNextSortOrderByPage')->willReturn(0);
        $rowRepository->method('create')->willReturn(100);

        $pageRepository = $this->createMock(BlockPageRepository::class);
        $pageRepository->method('findByCodeIncludingDeleted')->willReturn(\Mublo\Entity\Block\BlockPage::fromArray([
            'page_id' => 55,
            'domain_id' => 1,
            'page_code' => 'about',
        ]));

        $settings = $this->createMock(DomainSettingsService::class);
        $settings->expects($this->never())->method('saveSettings');
        $settings->expects($this->never())->method('getSiteConfig');

        $kit = $this->makePageKit();
        $kit['site_settings'] = ['site_config' => ['layout_type' => 'right-sidebar']];

        // 옵트인을 켜도 페이지 블록 킷은 site_config 를 건드리지 않는다(설계 5.6)
        $result = $this->makeApplier($rowRepository, null, null, $settings, null, $pageRepository)
            ->apply(1, $kit, BlockKitApplier::MODE_APPEND, true);

        $this->assertTrue($result['ok'], implode(' / ', $result['errors']));
        $this->assertSame([], $result['summary']['site_config_changes']);
    }

    public function testApplyRejectsUnknownMode(): void
    {
        $rowRepository = $this->createMock(BlockRowRepository::class);
        $rowRepository->expects($this->never())->method('getDb');

        $result = $this->makeApplier($rowRepository)->apply(1, $this->makeKit(), 'wipe-everything');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('지원하지 않는 적용 모드', $result['errors'][0]);
    }

    // ========================================
    // clone 블록 킷 / site_settings
    // ========================================

    public function testCloneKitFromAnotherInstallProducesWarning(): void
    {
        $installId = $this->createMock(InstallIdProvider::class);
        $installId->method('matches')->with('foreign-hash')->willReturn(false);

        $kit = $this->makeKit();
        $kit['export_mode'] = 'clone';
        $kit['source_install'] = 'foreign-hash';

        $result = $this->makeApplier(null, null, null, null, $installId)->dryRun($kit);

        $this->assertTrue($result['ok'], 'clone 블록 킷은 경고만 하고 적용은 막지 않는다');
        $this->assertStringContainsString('다른 설치에서 만든', $result['warnings'][0]);
    }

    public function testCloneKitFromSameInstallProducesNoWarning(): void
    {
        $installId = $this->createMock(InstallIdProvider::class);
        $installId->method('matches')->with('own-hash')->willReturn(true);

        $kit = $this->makeKit();
        $kit['export_mode'] = 'clone';
        $kit['source_install'] = 'own-hash';

        $result = $this->makeApplier(null, null, null, null, $installId)->dryRun($kit);

        $this->assertSame([], $result['warnings']);
    }

    public function testSiteSettingsAreMergedNotOverwritten(): void
    {
        $rowRepository = $this->makeRowRepository($this->makeDb());
        $rowRepository->method('create')->willReturn(100);

        $settings = $this->createMock(DomainSettingsService::class);
        $settings->method('getSiteConfig')->willReturn([
            'site_title' => '내 사이트',
            'editor' => 'mublo-editor',
            'layout_type' => 'full',
            'use_main_layout' => false,
        ]);
        $settings->expects($this->once())
            ->method('saveSettings')
            ->with(1, $this->callback(function (array $settings) {
                $site = $settings['site'];
                // 블록 킷이 요구한 키는 반영되고
                $this->assertSame('right-sidebar', $site['layout_type']);
                $this->assertTrue($site['use_main_layout']);
                // 블록 킷과 무관한 기존 키는 살아남는다
                $this->assertSame('내 사이트', $site['site_title']);
                $this->assertSame('mublo-editor', $site['editor']);
                return true;
            }))
            ->willReturn(\Mublo\Core\Result\Result::success('ok'));

        $kit = $this->makeKit();
        $kit['site_settings'] = ['site_config' => [
            'layout_type' => 'right-sidebar',
            'use_main_layout' => true,
        ]];

        $result = $this->makeApplier($rowRepository, null, null, $settings)
            ->apply(1, $kit, BlockKitApplier::MODE_APPEND, true);

        $this->assertTrue($result['ok'], implode(' / ', $result['errors']));
        $this->assertSame('full', $result['summary']['site_config_snapshot']['layout_type']);
        $this->assertSame(
            ['from' => 'full', 'to' => 'right-sidebar'],
            $result['summary']['site_config_changes']['layout_type']
        );
    }

    public function testSiteSettingsIgnoreKeysOutsideLayoutWhitelist(): void
    {
        $rowRepository = $this->makeRowRepository($this->makeDb());
        $rowRepository->method('create')->willReturn(100);

        $settings = $this->createMock(DomainSettingsService::class);
        $settings->method('getSiteConfig')->willReturn(['layout_type' => 'full']);
        $settings->expects($this->once())
            ->method('saveSettings')
            ->with(1, $this->callback(function (array $settings) {
                $this->assertArrayNotHasKey('primary_color', $settings['site']);
                $this->assertArrayNotHasKey('custom_head_script', $settings['site']);
                return true;
            }))
            ->willReturn(\Mublo\Core\Result\Result::success('ok'));

        $kit = $this->makeKit();
        $kit['site_settings'] = ['site_config' => [
            'layout_type' => 'right-sidebar',
            'primary_color' => '#ff0000',
            'custom_head_script' => '<script>alert(1)</script>',
        ]];

        $this->makeApplier($rowRepository, null, null, $settings)
            ->apply(1, $kit, BlockKitApplier::MODE_APPEND, true);
    }

    public function testSiteSettingsAreNotTouchedWithoutOptIn(): void
    {
        $rowRepository = $this->makeRowRepository($this->makeDb());
        $rowRepository->method('create')->willReturn(100);

        $settings = $this->createMock(DomainSettingsService::class);
        $settings->expects($this->never())->method('saveSettings');

        $kit = $this->makeKit();
        $kit['site_settings'] = ['site_config' => ['layout_type' => 'right-sidebar']];

        // 기본값은 false — 이미 우사이드를 쓰는 사이트는 설정을 건드릴 이유가 없다(설계 5.4 ⑥)
        $result = $this->makeApplier($rowRepository, null, null, $settings)->apply(1, $kit);

        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['summary']['site_config_changes']);
        $this->assertNull($result['summary']['site_config_snapshot']);
    }

    public function testFailedSettingsSaveRollsBackBlocks(): void
    {
        $db = $this->createMock(Database::class);
        $db->expects($this->once())->method('beginTransaction');
        $db->expects($this->never())->method('commit');
        $db->expects($this->once())->method('rollBack');

        $rowRepository = $this->makeRowRepository($db);
        $rowRepository->method('create')->willReturn(100);

        $settings = $this->createMock(DomainSettingsService::class);
        $settings->method('getSiteConfig')->willReturn(['layout_type' => 'full']);
        $settings->method('saveSettings')->willReturn(\Mublo\Core\Result\Result::failure('검증 실패'));

        $renderService = $this->createMock(BlockRenderService::class);
        $renderService->expects($this->never())->method('invalidateDomainCache');

        $kit = $this->makeKit();
        $kit['site_settings'] = ['site_config' => ['layout_type' => 'right-sidebar']];

        $result = $this->makeApplier($rowRepository, null, $renderService, $settings)
            ->apply(1, $kit, BlockKitApplier::MODE_APPEND, true);

        $this->assertFalse($result['ok'], '블록만 적용된 어중간한 상태를 남기지 않는다');
        $this->assertStringContainsString('사이트 설정 저장에 실패', $result['errors'][0]);
    }

    // ========================================
    // Helpers
    // ========================================

    /**
     * @param array<string, mixed> $rowExtra
     * @return array<string, mixed>
     */
    private function makeKit(array $rowExtra = []): array
    {
        return [
            'format' => 'mublo-starter-kit',
            'contains_script' => false,
            'target' => ['kind' => 'position', 'position' => 'index'],
            'rows' => [array_merge([
                'admin_title' => 'Hero',
                'column_count' => 1,
                'columns' => [[
                    'column_index' => 0,
                    'content_type' => 'html',
                    'content_kind' => 'CORE',
                    'content_config' => ['html' => '<p>safe</p>'],
                ]],
            ], $rowExtra)],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function makePageKit(): array
    {
        $kit = $this->makeKit();
        $kit['target'] = ['kind' => 'page', 'page_code' => 'about'];
        $kit['page'] = [
            'page_id' => 999, // 블록 킷이 실어 온 DB 식별자 — 무시되어야 한다
            'page_code' => 'about',
            'page_title' => '회사소개',
            'layout_type' => 3,
            'use_header' => 1,
        ];

        return $kit;
    }

    /** @return array<string, mixed> */
    private function makeMainScreenKit(): array
    {
        return [
            'format' => 'mublo-starter-kit',
            'contains_script' => false,
            'target' => [
                'kind' => 'screen',
                'screen' => 'main',
                'slots' => ['topbar', 'subhead', 'contenthead', 'index', 'contentfoot', 'right', 'subfoot'],
            ],
            'site_settings' => ['site_config' => [
                'layout_type' => 'right-sidebar',
                'use_main_layout' => true,
                'layout_right_width' => 280,
            ]],
            'rows' => [
                ['position' => 'index', 'admin_title' => 'Main', 'columns' => []],
                ['position' => 'right', 'admin_title' => 'Sidebar', 'columns' => []],
            ],
        ];
    }

    private function makeDb(): Database
    {
        $db = $this->createMock(Database::class);
        $db->method('beginTransaction');
        $db->method('commit');

        return $db;
    }

    private function makeRowRepository(Database $db): BlockRowRepository
    {
        $repository = $this->createMock(BlockRowRepository::class);
        $repository->method('getDb')->willReturn($db);
        $repository->method('getNextSortOrderByPosition')->willReturn(0);

        return $repository;
    }
}
