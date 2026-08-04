<?php
declare(strict_types=1);
namespace Mublo\Service\Block;

use Mublo\Entity\Block\BlockKit;
use Mublo\Entity\Block\BlockKitApplication;
use Mublo\Repository\Block\BlockKitApplicationRepository;
use Mublo\Repository\Block\BlockKitRepository;
use Mublo\Service\Domain\DomainSettingsService;

/**
 * 블록 킷 보관소.
 *
 * 업로드하거나 내보낸 블록 킷을 DB 에 보관하고, 목록·상세로 되돌려 준다(설계 10).
 *
 * ## 블록 킷이 주장하는 값을 믿지 않는다
 *
 * block_kits 의 목록용 컬럼(contains_script, row_count 등)은 kit_json 의 사본이다.
 * 그 사본을 블록 킷이 적어 온 값에서 뜨면, 블록 킷이 `contains_script: false` 라고 거짓말했을 때
 * 목록에 "스크립트 없음" 배지가 붙는다. 그래서 **BlockKitApplier 가 실측한 값**만 쓴다.
 *
 * 이름·설명·저작자처럼 실측할 수 없는 값은 블록 킷에서 가져오되, 컬럼 길이에 맞춰 자른다.
 * DB 가 잘라 주기를 기대하면 strict 모드에서 INSERT 가 통째로 실패한다.
 */
class BlockKitLibrary
{
    /** 컬럼 길이. 초과분은 저장 전에 자른다. */
    private const LIMITS = [
        'kit_name' => 100,
        'kit_description' => 500,
        'kit_version' => 20,
        'kit_author' => 100,
        'kit_author_url' => 255,
    ];

    public function __construct(
        private BlockKitRepository $repository,
        private BlockKitApplier $applier,
        private BlockKitScreenshot $screenshot,
        private BlockKitApplicationRepository $applicationRepository,
        private DomainSettingsService $domainSettingsService
    ) {
    }

    /**
     * 블록 킷 JSON 을 검증하고 보관한다.
     *
     * 검증에 실패하면 보관하지 않는다 — 목록에 열리지 않는 블록 킷이 쌓이면 안 된다.
     *
     * @return array{ok: bool, errors: string[], warnings: string[], kit_id: int|null, summary: array<string, mixed>}
     */
    public function store(int $domainId, string $json, string $sourceType = BlockKit::SOURCE_UPLOAD): array
    {
        $validation = $this->applier->dryRunFromJson($json);

        if (!$validation['ok']) {
            return $validation + ['kit_id' => null];
        }

        $kit = json_decode($json, true);
        if (!is_array($kit)) {
            // dryRunFromJson 이 통과시켰다면 여기 올 수 없다. 그래도 방어한다.
            return ['ok' => false, 'errors' => ['유효한 JSON 블록 킷이 아닙니다.'], 'warnings' => [], 'kit_id' => null, 'summary' => []];
        }

        $kitId = $this->repository->create($this->toRow($domainId, $kit, $json, $validation, $sourceType));

        // 썸네일 파일 이름이 kit_id 로 정해지므로 INSERT 이후에야 구울 수 있다.
        // 실패해도 블록 킷은 보관된 채로 둔다 — 목록에 "스크린샷 없음" 이 뜰 뿐이다(설계 4.7-③).
        if ($kitId !== null) {
            $path = $this->screenshot->store($kit['screenshot'] ?? null, $domainId, $kitId);
            if ($path !== null) {
                $this->repository->updateScreenshotPath($kitId, $path);
            }
        }

        return $validation + ['kit_id' => $kitId];
    }

    /**
     * @param array{keyword?: string, target_kind?: string, contains_script?: string} $filters
     * @return BlockKit[]
     */
    public function list(int $domainId, int $limit = 100, int $offset = 0, array $filters = []): array
    {
        $kits = $this->repository->findAllByDomain($domainId, $limit, $offset, $filters);

        // 썸네일 자가 복구(#39): 과거에 WebP 미지원 GD 등으로 굽기에 실패해
        // screenshot_path 가 비어 있는 킷을 목록 조회 시점에 한 번 재시도한다.
        // 번들 킷으로 한정한다 — 개수가 유한하고(시작 킷·플러그인 킷) 킷 JSON 에
        // 스크린샷이 반드시 있다. 업로드 킷까지 넓히면 "스크린샷 없는 킷"의
        // kit_json(최대 2 MiB)을 목록을 열 때마다 다시 읽게 된다.
        $healed = false;
        foreach ($kits as $kit) {
            if ($kit->getSourceType() !== BlockKit::SOURCE_BUNDLED || $kit->getScreenshotPath()) {
                continue;
            }

            $withJson = $this->repository->findWithJson($domainId, $kit->getKitId());
            $data = json_decode((string) $withJson?->getKitJson(), true);
            $path = $this->screenshot->store(is_array($data) ? ($data['screenshot'] ?? null) : null, $domainId, $kit->getKitId());
            if ($path !== null) {
                $this->repository->updateScreenshotPath($kit->getKitId(), $path);
                $healed = true;
            }
        }

        return $healed
            ? $this->repository->findAllByDomain($domainId, $limit, $offset, $filters)
            : $kits;
    }

    /**
     * @param array{keyword?: string, target_kind?: string, contains_script?: string} $filters
     */
    public function count(int $domainId, array $filters = []): int
    {
        return $this->repository->countByDomain($domainId, $filters);
    }

    public function find(int $domainId, int $kitId): ?BlockKit
    {
        return $this->repository->findByDomain($domainId, $kitId);
    }

    public function findWithJson(int $domainId, int $kitId): ?BlockKit
    {
        return $this->repository->findWithJson($domainId, $kitId);
    }

    /**
     * 소프트 삭제. 썸네일 파일은 함께 지운다.
     *
     * 순서가 중요하다. DB 를 먼저 지운다 — 파일부터 지우면 DB 삭제가 실패했을 때
     * 목록에 이미지 없는 블록 킷이 남는다. 반대 순서로 실패하면 고아 파일 하나가 남는데,
     * 그건 아무도 참조하지 않는 몇 KiB 다.
     */
    public function delete(int $domainId, int $kitId): bool
    {
        $kit = $this->repository->findByDomain($domainId, $kitId);
        if ($kit === null) {
            return false;
        }

        if ($this->repository->softDelete($domainId, $kitId) === 0) {
            return false;
        }

        $this->screenshot->remove($kit->getScreenshotPath());

        return true;
    }

    /**
     * 보관된 블록 킷을 적용하고 이력을 남긴다.
     *
     * 적용이 실패하면 이력을 남기지 않는다 — 일어나지 않은 일이다.
     *
     * @return array<string, mixed> applier 의 결과 + application_id
     */
    public function apply(
        int $domainId,
        int $kitId,
        string $mode,
        bool $applySiteSettings,
        ?int $appliedBy = null,
        bool $allowScripts = true
    ): array {
        $stored = $this->repository->findWithJson($domainId, $kitId);
        if ($stored === null || !$stored->hasJson()) {
            return ['ok' => false, 'errors' => ['블록 킷을 찾을 수 없습니다.'], 'warnings' => [], 'summary' => []];
        }

        $kit = $stored->decodeJson();
        if ($kit === null) {
            return ['ok' => false, 'errors' => ['보관된 블록 킷이 손상되었습니다.'], 'warnings' => [], 'summary' => []];
        }

        $applicationId = null;
        $result = $this->applier->apply(
            $domainId,
            $kit,
            $mode,
            $applySiteSettings,
            $allowScripts,
            function (array $summary) use ($domainId, $kitId, $mode, $appliedBy, &$applicationId): void {
                $applicationId = $this->applicationRepository->create(
                    $this->toApplicationRow($domainId, $kitId, $mode, $summary, $appliedBy)
                );
                if ($applicationId === null || $applicationId <= 0) {
                    throw new \RuntimeException('블록 킷 적용 이력을 기록하지 못했습니다.');
                }
            }
        );
        if (!$result['ok']) {
            return $result;
        }

        $result['application_id'] = $applicationId;

        return $result;
    }

    /** @return BlockKitApplication[] */
    public function history(int $domainId, int $kitId, int $limit = 50): array
    {
        return $this->applicationRepository->findAllByKit($domainId, $kitId, $limit);
    }

    /**
     * 적용 시점의 site_config 로 되돌린다.
     *
     * **블록 행은 되돌리지 않는다.** 적용 후 운영자가 편집했을 수 있고, 그것까지 지우면
     * "되돌리기" 가 아니라 파괴다. 되돌리는 것은 블록 킷이 건드린 레이아웃 설정뿐이다.
     *
     * 한 번만 되돌릴 수 있다. 두 번째 되돌리기는 첫 번째가 복원한 값이 아니라 원래
     * 스냅샷을 다시 쓰므로, 그 사이 운영자의 변경을 조용히 지운다.
     *
     * @return array{ok: bool, errors: string[], restored: array<string, mixed>}
     */
    public function rollback(int $domainId, int $applicationId): array
    {
        $application = $this->applicationRepository->findWithSnapshot($domainId, $applicationId);
        if ($application === null) {
            return ['ok' => false, 'errors' => ['적용 이력을 찾을 수 없습니다.'], 'restored' => []];
        }

        $snapshot = $application->decodeSnapshot();
        if ($snapshot === null) {
            return [
                'ok' => false,
                'errors' => ['되돌릴 설정이 없습니다. 이 적용은 사이트 설정을 바꾸지 않았거나 이미 되돌렸습니다.'],
                'restored' => [],
            ];
        }

        try {
            return $this->applicationRepository->getDb()->transaction(function () use (
                $domainId,
                $applicationId,
                $snapshot
            ): array {
                // 조건부 선점: 동시에 두 요청이 같은 스냅샷을 읽어도 한 요청만 1행을 갱신한다.
                // 뒤의 설정 저장이 실패하면 트랜잭션 롤백으로 스냅샷도 복구되어 재시도할 수 있다.
                if ($this->applicationRepository->claimSnapshotForRollback($domainId, $applicationId) !== 1) {
                    return [
                        'ok' => false,
                        'errors' => ['되돌릴 설정이 없습니다. 이 적용은 이미 되돌렸을 수 있습니다.'],
                        'restored' => [],
                    ];
                }

                $result = $this->domainSettingsService->saveSettings($domainId, ['site' => $snapshot]);
                if ($result->isFailure()) {
                    throw new \RuntimeException($result->getMessage());
                }

                return ['ok' => true, 'errors' => [], 'restored' => $snapshot];
            });
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'errors' => ['사이트 설정을 되돌리지 못했습니다: ' . $e->getMessage()],
                'restored' => [],
            ];
        }
    }

    /**
     * @param array<string, mixed> $summary applier 가 돌려준 요약
     * @return array<string, mixed>
     */
    private function toApplicationRow(int $domainId, int $kitId, string $mode, array $summary, ?int $appliedBy): array
    {
        $targetKind = match ($summary['target_kind'] ?? null) {
            BlockKit::TARGET_PAGE => BlockKit::TARGET_PAGE,
            BlockKit::TARGET_SCREEN => BlockKit::TARGET_SCREEN,
            default => BlockKit::TARGET_POSITION,
        };

        $snapshot = $summary['site_config_snapshot'] ?? null;

        return [
            'kit_id' => $kitId,
            'domain_id' => $domainId,
            'apply_mode' => $mode === BlockKitApplier::MODE_REPLACE
                ? BlockKitApplier::MODE_REPLACE
                : BlockKitApplier::MODE_APPEND,

            'target_kind' => $targetKind,
            'target_position' => $targetKind === BlockKit::TARGET_POSITION
                ? $this->nullableText($summary['position'] ?? null, 20)
                : null,
            'target_menu_code' => $targetKind === BlockKit::TARGET_POSITION
                ? $this->nullableText($summary['menu_code'] ?? null, 50)
                : null,
            'target_page_code' => $targetKind === BlockKit::TARGET_PAGE
                ? $this->nullableText($summary['page_code'] ?? null, 50)
                : null,

            'created_row_count' => (int) ($summary['created_rows'] ?? 0),

            // 블록 킷이 site_config 를 건드렸을 때만 스냅샷이 있다. 없으면 되돌릴 것도 없다.
            'site_config_snapshot' => is_array($snapshot) ? json_encode($snapshot, JSON_UNESCAPED_UNICODE) : null,

            'applied_by' => $appliedBy,
        ];
    }

    /**
     * @param array<string, mixed> $kit
     * @param array<string, mixed> $validation
     * @return array<string, mixed>
     */
    private function toRow(int $domainId, array $kit, string $json, array $validation, string $sourceType): array
    {
        $target = is_array($kit['target'] ?? null) ? $kit['target'] : [];
        $summary = $validation['summary'] ?? [];

        $targetKind = match ($target['kind'] ?? null) {
            BlockKit::TARGET_PAGE => BlockKit::TARGET_PAGE,
            BlockKit::TARGET_SCREEN => BlockKit::TARGET_SCREEN,
            default => BlockKit::TARGET_POSITION,
        };

        return [
            'domain_id' => $domainId,

            'kit_name' => $this->text($kit['name'] ?? null, 'kit_name') ?: '이름 없는 블록 킷',
            'kit_description' => $this->text($kit['description'] ?? null, 'kit_description') ?? '',
            'kit_version' => $this->text($kit['version'] ?? null, 'kit_version') ?: '1.0.0',
            'kit_author' => $this->text($kit['author'] ?? null, 'kit_author') ?? '',
            'kit_author_url' => $this->text($kit['author_url'] ?? null, 'kit_author_url') ?? '',

            'target_kind' => $targetKind,
            'target_position' => $targetKind === BlockKit::TARGET_POSITION
                ? $this->nullableText($target['position'] ?? null, 20)
                : null,
            'target_menu_code' => $targetKind === BlockKit::TARGET_POSITION
                ? $this->nullableText($target['menu_code'] ?? null, 50)
                : null,
            'target_page_code' => $targetKind === BlockKit::TARGET_PAGE
                ? $this->nullableText($target['page_code'] ?? null, 50)
                : null,

            'export_mode' => ($kit['export_mode'] ?? null) === 'clone' ? 'clone' : 'distribution',

            // 블록 킷의 주장이 아니라 실측값이다(9.4). 배지가 거짓말하면 안 된다.
            'contains_script' => !empty($summary['contains_script']) ? 1 : 0,
            'row_count' => (int) ($summary['row_count'] ?? 0),
            'column_count' => (int) ($summary['column_count'] ?? 0),

            'kit_json' => $json,
            'source_type' => $sourceType === BlockKit::SOURCE_EXPORT ? BlockKit::SOURCE_EXPORT : BlockKit::SOURCE_UPLOAD,
        ];
    }

    /**
     * 컬럼 길이에 맞춰 자른다. 문자열이 아니면 null.
     *
     * mb_substr 을 쓴다 — 한글 이름이 바이트 중간에서 잘리면 깨진 문자가 저장된다.
     * 컬럼은 VARCHAR(n) 이고 utf8mb4 이므로 n 은 바이트가 아니라 문자 수다.
     */
    private function text(mixed $value, string $column): ?string
    {
        return $this->nullableText($value, self::LIMITS[$column]);
    }

    private function nullableText(mixed $value, int $limit): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $limit);
    }
}
