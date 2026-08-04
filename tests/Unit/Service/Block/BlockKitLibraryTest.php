<?php

namespace Tests\Unit\Service\Block;

use Mublo\Core\Result\Result;
use Mublo\Entity\Block\BlockKit;
use Mublo\Entity\Block\BlockKitApplication;
use Mublo\Infrastructure\Database\Database;
use Mublo\Repository\Block\BlockKitApplicationRepository;
use Mublo\Repository\Block\BlockKitRepository;
use Mublo\Service\Block\BlockKitApplier;
use Mublo\Service\Block\BlockKitLibrary;
use Mublo\Service\Block\BlockKitScreenshot;
use Mublo\Service\Domain\DomainSettingsService;
use PHPUnit\Framework\TestCase;

/**
 * 보관 시 목록용 컬럼을 어디서 뜨는가 — 이것이 이 서비스의 계약이다.
 *
 * contains_script 같은 배지를 블록 킷이 적어 온 값에서 뜨면, 블록 킷이 거짓말했을 때 목록에
 * "스크립트 없음" 이 붙는다. 실측값(BlockKitApplier 의 dry-run 요약)만 써야 한다.
 */
class BlockKitLibraryTest extends TestCase
{
    /** @var array<string, mixed>|null 리포지토리가 받은 INSERT 페이로드 */
    private ?array $inserted = null;

    /**
     * @param array<string, mixed> $validation applier 가 돌려줄 dry-run 결과
     */
    private function makeLibrary(array $validation): BlockKitLibrary
    {
        $repository = $this->createMock(BlockKitRepository::class);
        $repository->method('create')->willReturnCallback(function (array $row) {
            $this->inserted = $row;

            return 42;
        });

        $applier = $this->createMock(BlockKitApplier::class);
        $applier->method('dryRunFromJson')->willReturn($validation);

        return new BlockKitLibrary(
            $repository,
            $applier,
            $this->createMock(BlockKitScreenshot::class),
            $this->createMock(BlockKitApplicationRepository::class),
            $this->createMock(DomainSettingsService::class)
        );
    }

    /** @param array<string, mixed> $summary */
    private function passingValidation(array $summary = []): array
    {
        return [
            'ok' => true,
            'errors' => [],
            'warnings' => [],
            'summary' => $summary + ['contains_script' => false, 'row_count' => 0, 'column_count' => 0],
        ];
    }

    // ========================================
    // 블록 킷의 주장을 믿지 않는다
    // ========================================

    /**
     * 블록 킷은 contains_script=false 라고 적었지만, dry-run 이 실제로 js 를 발견했다.
     * 목록 배지는 실측값을 따라야 한다. 아니면 "스크립트 없음" 배지가 거짓말한다.
     */
    public function testContainsScriptComesFromMeasurementNotFromTheKit(): void
    {
        $library = $this->makeLibrary($this->passingValidation(['contains_script' => true]));

        $library->store(1, json_encode(['name' => '블록 킷', 'contains_script' => false]));

        $this->assertSame(1, $this->inserted['contains_script']);
    }

    /** 반대 방향도 같다 — 블록 킷이 true 라고 적어도 실측이 false 면 false 다. */
    public function testKitCannotFalselyClaimToContainScript(): void
    {
        $library = $this->makeLibrary($this->passingValidation(['contains_script' => false]));

        $library->store(1, json_encode(['contains_script' => true]));

        $this->assertSame(0, $this->inserted['contains_script']);
    }

    /** 행/칸 수도 블록 킷의 주장이 아니라 dry-run 이 센 값이다. */
    public function testRowAndColumnCountsComeFromTheSummary(): void
    {
        $library = $this->makeLibrary($this->passingValidation(['row_count' => 3, 'column_count' => 8]));

        $library->store(1, json_encode(['rows' => []]));

        $this->assertSame(3, $this->inserted['row_count']);
        $this->assertSame(8, $this->inserted['column_count']);
    }

    // ========================================
    // 검증 실패는 보관하지 않는다
    // ========================================

    /** 열리지 않는 블록 킷이 목록에 쌓이면 안 된다. */
    public function testInvalidKitIsNotStored(): void
    {
        $library = $this->makeLibrary([
            'ok' => false,
            'errors' => ['include 블록은 블록 킷에서 허용되지 않습니다.'],
            'warnings' => [],
            'summary' => [],
        ]);

        $result = $library->store(1, json_encode(['rows' => []]));

        $this->assertFalse($result['ok']);
        $this->assertNull($result['kit_id']);
        $this->assertNull($this->inserted, 'INSERT 가 일어나면 안 된다');
    }

    public function testValidKitReturnsItsNewId(): void
    {
        $library = $this->makeLibrary($this->passingValidation());

        $this->assertSame(42, $library->store(1, json_encode(['name' => '블록 킷']))['kit_id']);
    }

    // ========================================
    // 컬럼 길이
    // ========================================

    /**
     * 블록 킷 이름은 저작자가 정한다. DB 가 잘라 주기를 기대하면 strict 모드에서
     * INSERT 가 통째로 실패한다. 저장 전에 자른다.
     */
    public function testOverlongTextIsTruncatedToColumnWidth(): void
    {
        $library = $this->makeLibrary($this->passingValidation());

        $library->store(1, json_encode([
            'name' => str_repeat('가', 200),
            'description' => str_repeat('나', 900),
        ]));

        $this->assertSame(100, mb_strlen($this->inserted['kit_name']));
        $this->assertSame(500, mb_strlen($this->inserted['kit_description']));
    }

    /** 한글이 바이트 중간에서 잘리면 깨진 문자가 저장된다. mb_substr 이어야 한다. */
    public function testTruncationDoesNotBreakMultibyteCharacters(): void
    {
        $library = $this->makeLibrary($this->passingValidation());

        $library->store(1, json_encode(['name' => str_repeat('한', 150)]));

        $this->assertSame(str_repeat('한', 100), $this->inserted['kit_name']);
    }

    // ========================================
    // target 정규화
    // ========================================

    /** page 블록 킷은 position 컬럼을 채우지 않는다. 섞이면 목록 라벨이 거짓이 된다. */
    public function testPageKitLeavesPositionColumnsNull(): void
    {
        $library = $this->makeLibrary($this->passingValidation());

        $library->store(1, json_encode([
            'target' => ['kind' => 'page', 'page_code' => 'about', 'position' => 'index'],
        ]));

        $this->assertSame(BlockKit::TARGET_PAGE, $this->inserted['target_kind']);
        $this->assertSame('about', $this->inserted['target_page_code']);
        $this->assertNull($this->inserted['target_position'], 'page 블록 킷이 position 을 들고 있으면 안 된다');
    }

    public function testPositionKitLeavesPageCodeNull(): void
    {
        $library = $this->makeLibrary($this->passingValidation());

        $library->store(1, json_encode([
            'target' => ['kind' => 'position', 'position' => 'index', 'menu_code' => 'shop', 'page_code' => 'about'],
        ]));

        $this->assertSame(BlockKit::TARGET_POSITION, $this->inserted['target_kind']);
        $this->assertSame('index', $this->inserted['target_position']);
        $this->assertSame('shop', $this->inserted['target_menu_code']);
        $this->assertNull($this->inserted['target_page_code']);
    }

    public function testMainScreenKitIsStoredAsScreenInsteadOfPosition(): void
    {
        $library = $this->makeLibrary($this->passingValidation());

        $library->store(1, json_encode([
            'target' => ['kind' => 'screen', 'screen' => 'main'],
        ]));

        $this->assertSame(BlockKit::TARGET_SCREEN, $this->inserted['target_kind']);
        $this->assertNull($this->inserted['target_position']);
        $this->assertNull($this->inserted['target_page_code']);
    }

    /** target 이 없거나 이상하면 position 으로 본다 — 블록 킷의 kind 를 그대로 컬럼에 넣지 않는다. */
    public function testUnknownTargetKindFallsBackToPosition(): void
    {
        $library = $this->makeLibrary($this->passingValidation());

        $library->store(1, json_encode(['target' => ['kind' => '<script>']]));

        $this->assertSame(BlockKit::TARGET_POSITION, $this->inserted['target_kind']);
    }

    /** export_mode 도 두 값만 허용한다. */
    public function testUnknownExportModeFallsBackToDistribution(): void
    {
        $library = $this->makeLibrary($this->passingValidation());

        $library->store(1, json_encode(['export_mode' => 'evil']));

        $this->assertSame('distribution', $this->inserted['export_mode']);
    }

    /** 이름 없는 블록 킷도 목록에 뜨긴 해야 한다. */
    public function testMissingNameGetsAPlaceholder(): void
    {
        $library = $this->makeLibrary($this->passingValidation());

        $library->store(1, json_encode(['rows' => []]));

        $this->assertSame('이름 없는 블록 킷', $this->inserted['kit_name']);
        $this->assertSame('1.0.0', $this->inserted['kit_version']);
        $this->assertSame('', $this->inserted['kit_description']);
    }

    /** 본문은 받은 그대로 저장한다. 내려받기가 원본을 돌려줘야 한다. */
    public function testKitJsonIsStoredVerbatim(): void
    {
        $library = $this->makeLibrary($this->passingValidation());
        $json = json_encode(['name' => '블록 킷', 'rows' => []], JSON_UNESCAPED_UNICODE);

        $library->store(1, $json);

        $this->assertSame($json, $this->inserted['kit_json']);
    }

    /** source_type 도 두 값만 허용한다. */
    public function testUnknownSourceTypeFallsBackToUpload(): void
    {
        $library = $this->makeLibrary($this->passingValidation());

        $library->store(1, json_encode([]), 'evil');

        $this->assertSame(BlockKit::SOURCE_UPLOAD, $this->inserted['source_type']);
    }

    // ========================================
    // 적용과 이력 원자성
    // ========================================

    public function testApplyCreatesHistoryInsideApplierTransactionHook(): void
    {
        $kit = [
            'format' => 'mublo-starter-kit',
            'target' => ['kind' => 'position', 'position' => 'index'],
            'rows' => [],
        ];
        $stored = BlockKit::fromArray([
            'kit_id' => 5,
            'domain_id' => 1,
            'kit_json' => json_encode($kit),
        ]);

        $repository = $this->createMock(BlockKitRepository::class);
        $repository->method('findWithJson')->with(1, 5)->willReturn($stored);

        $insideApply = false;
        $applier = $this->createMock(BlockKitApplier::class);
        $applier->expects($this->once())->method('apply')->willReturnCallback(
            function (
                int $domainId,
                array $payload,
                string $mode,
                bool $applySettings,
                bool $allowScripts,
                callable $beforeCommit
            ) use (&$insideApply): array {
                $insideApply = true;
                $summary = [
                    'target_kind' => 'position',
                    'position' => 'index',
                    'created_rows' => 2,
                ];
                $beforeCommit($summary);
                $insideApply = false;

                return ['ok' => true, 'errors' => [], 'warnings' => [], 'summary' => $summary];
            }
        );

        $applications = $this->createMock(BlockKitApplicationRepository::class);
        $applications->expects($this->once())->method('create')->willReturnCallback(
            function (array $row) use (&$insideApply): int {
                $this->assertTrue($insideApply, '적용 이력은 Applier 커밋 전 훅 안에서 생성되어야 한다');
                $this->assertSame(1, $row['domain_id']);
                $this->assertSame(5, $row['kit_id']);
                $this->assertSame(2, $row['created_row_count']);
                return 19;
            }
        );

        $library = new BlockKitLibrary(
            $repository,
            $applier,
            $this->createMock(BlockKitScreenshot::class),
            $applications,
            $this->createMock(DomainSettingsService::class)
        );

        $result = $library->apply(1, 5, BlockKitApplier::MODE_APPEND, false, 77);

        $this->assertTrue($result['ok']);
        $this->assertSame(19, $result['application_id']);
    }

    // ========================================
    // 되돌리기 (설계 10.3)
    // ========================================

    /**
     * @param array<string, mixed>|null $snapshot decodeSnapshot() 이 돌려줄 값
     */
    private function makeLibraryForRollback(
        ?array $snapshot,
        ?DomainSettingsService $settings = null,
        ?BlockKitApplicationRepository $applications = null
    ): BlockKitLibrary {
        $application = $snapshot === null ? null : BlockKitApplication::fromArray([
            'application_id' => 7,
            'domain_id' => 1,
            'site_config_snapshot' => json_encode($snapshot),
        ]);

        if ($applications === null) {
            $applications = $this->createMock(BlockKitApplicationRepository::class);
            $applications->method('claimSnapshotForRollback')->willReturn(1);
        }
        $applications->method('findWithSnapshot')->willReturn($application);
        $db = $this->createMock(Database::class);
        $db->method('transaction')->willReturnCallback(static fn (callable $callback): mixed => $callback());
        $applications->method('getDb')->willReturn($db);

        return new BlockKitLibrary(
            $this->createMock(BlockKitRepository::class),
            $this->createMock(BlockKitApplier::class),
            $this->createMock(BlockKitScreenshot::class),
            $applications,
            $settings ?? $this->createMock(DomainSettingsService::class)
        );
    }

    /** 되돌리기는 적용 시점의 site_config 를 그대로 다시 저장한다. */
    public function testRollbackRestoresTheSnapshot(): void
    {
        $snapshot = ['layout_type' => 'left', 'use_main_layout' => 1];

        $settings = $this->createMock(DomainSettingsService::class);
        $settings->expects($this->once())
            ->method('saveSettings')
            ->with(1, ['site' => $snapshot])
            ->willReturn(Result::success());

        $result = $this->makeLibraryForRollback($snapshot, $settings)->rollback(1, 7);

        $this->assertTrue($result['ok']);
        $this->assertSame($snapshot, $result['restored']);
    }

    /**
     * 두 번째 되돌리기는 첫 번째가 복원한 값이 아니라 원래 스냅샷을 다시 쓴다.
     * 그 사이 운영자가 레이아웃을 바꿨다면 그 변경이 조용히 지워진다. 그래서 한 번만이다.
     */
    public function testRollbackClearsTheSnapshotSoItCannotRunTwice(): void
    {
        $settings = $this->createMock(DomainSettingsService::class);
        $settings->method('saveSettings')->willReturn(Result::success());

        $applications = $this->createMock(BlockKitApplicationRepository::class);
        $applications->expects($this->once())->method('claimSnapshotForRollback')->with(1, 7)->willReturn(1);

        $this->makeLibraryForRollback(['layout_type' => 'left'], $settings, $applications)->rollback(1, 7);
    }

    /** 블록 킷이 site_config 를 건드리지 않았으면 되돌릴 것이 없다. */
    public function testRollbackWithoutSnapshotFails(): void
    {
        $application = BlockKitApplication::fromArray(['application_id' => 7, 'site_config_snapshot' => null]);

        $applications = $this->createMock(BlockKitApplicationRepository::class);
        $applications->method('findWithSnapshot')->willReturn($application);
        $applications->expects($this->never())->method('claimSnapshotForRollback');

        $settings = $this->createMock(DomainSettingsService::class);
        $settings->expects($this->never())->method('saveSettings');

        $library = new BlockKitLibrary(
            $this->createMock(BlockKitRepository::class),
            $this->createMock(BlockKitApplier::class),
            $this->createMock(BlockKitScreenshot::class),
            $applications,
            $settings
        );

        $this->assertFalse($library->rollback(1, 7)['ok']);
    }

    /** 없는 이력(다른 도메인 것 포함)은 되돌릴 수 없다. */
    public function testRollbackOfUnknownApplicationFails(): void
    {
        $result = $this->makeLibraryForRollback(null)->rollback(1, 7);

        $this->assertFalse($result['ok']);
        $this->assertSame([], $result['restored']);
    }

    /** 설정 저장이 실패하면 스냅샷을 지우지 않는다. 다시 시도할 수 있어야 한다. */
    public function testFailedSaveKeepsTheSnapshotForRetry(): void
    {
        $settings = $this->createMock(DomainSettingsService::class);
        $settings->method('saveSettings')->willReturn(Result::failure('DB 오류'));

        $applications = $this->createMock(BlockKitApplicationRepository::class);
        $applications->expects($this->once())->method('claimSnapshotForRollback')->with(1, 7)->willReturn(1);

        $result = $this->makeLibraryForRollback(['layout_type' => 'left'], $settings, $applications)->rollback(1, 7);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('DB 오류', $result['errors'][0]);
    }

    public function testConcurrentRollbackClaimPreventsSecondSettingsWrite(): void
    {
        $settings = $this->createMock(DomainSettingsService::class);
        $settings->expects($this->never())->method('saveSettings');

        $applications = $this->createMock(BlockKitApplicationRepository::class);
        $applications->expects($this->once())->method('claimSnapshotForRollback')->with(1, 7)->willReturn(0);

        $result = $this->makeLibraryForRollback(
            ['layout_type' => 'left'],
            $settings,
            $applications
        )->rollback(1, 7);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('이미 되돌렸을 수 있습니다', $result['errors'][0]);
    }

    /** 손상된 스냅샷 JSON 이 예외로 번지지 않는다. */
    public function testCorruptSnapshotIsTreatedAsAbsent(): void
    {
        $application = BlockKitApplication::fromArray([
            'application_id' => 7,
            'site_config_snapshot' => '{broken',
        ]);

        $applications = $this->createMock(BlockKitApplicationRepository::class);
        $applications->method('findWithSnapshot')->willReturn($application);

        $library = new BlockKitLibrary(
            $this->createMock(BlockKitRepository::class),
            $this->createMock(BlockKitApplier::class),
            $this->createMock(BlockKitScreenshot::class),
            $applications,
            $this->createMock(DomainSettingsService::class)
        );

        $this->assertFalse($library->rollback(1, 7)['ok']);
        $this->assertFalse($application->canRollback());
    }
}
