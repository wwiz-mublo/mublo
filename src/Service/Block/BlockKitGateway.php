<?php
namespace Mublo\Service\Block;

use Mublo\Contract\Block\BlockKitGatewayInterface;
use Mublo\Core\Result\Result;
use Mublo\Entity\Block\BlockKit;
use Mublo\Infrastructure\Database\Database;

/**
 * BlockKitGateway
 *
 * BlockKitGatewayInterface(안정 API)의 코어 구현.
 *
 * 확장이 배포하는 번들 킷 등록을 코어가 소유한다 — block_kits 스키마와
 * source_type='bundled' 정책이 코어 안에 머물고, 확장은 계약만 소비한다.
 * (BlockKitLibrary::store() 는 업로드/내보내기 전용이라 source_type 을
 *  upload/export 로 강제한다. 번들 등록은 시작 킷 시더(003)와 같은 규칙으로
 *  여기서 직접 INSERT 한다.)
 */
class BlockKitGateway implements BlockKitGatewayInterface
{
    public function __construct(
        private BlockKitApplier $applier,
        private BlockKitScreenshot $screenshot,
        private Database $db
    ) {
    }

    public function registerBundled(int $domainId, string $json): Result
    {
        $kit = json_decode($json, true);
        if (!is_array($kit)) {
            return Result::failure('유효한 JSON 블록 킷이 아닙니다.');
        }

        $kitName = mb_substr(trim((string) ($kit['name'] ?? '')), 0, 100);
        if ($kitName === '') {
            return Result::failure('블록 킷에 name 이 없습니다.');
        }

        // 멱등: 같은 이름의 번들 킷이 있으면 스킵 — 스크린샷 없던 등록분은 백필
        $existing = $this->db->selectOne(
            "SELECT kit_id, screenshot_path FROM block_kits
             WHERE domain_id = ? AND kit_name = ? AND source_type = ? AND is_deleted = 0",
            [$domainId, $kitName, BlockKit::SOURCE_BUNDLED]
        );

        if ($existing) {
            $kitId = (int) $existing['kit_id'];

            if (empty($existing['screenshot_path']) && !empty($kit['screenshot'])) {
                $this->db->execute(
                    'UPDATE block_kits SET kit_json = ?, updated_at = ? WHERE kit_id = ?',
                    [$json, date('Y-m-d H:i:s'), $kitId]
                );
                $this->bakeScreenshot($kitId, $domainId, $kit);

                return Result::success('기존 등록 킷에 스크린샷을 보강했습니다.', [
                    'kit_id' => $kitId,
                    'status' => 'backfilled',
                ]);
            }

            return Result::success('이미 등록된 킷입니다.', [
                'kit_id' => $kitId,
                'status' => 'skipped',
            ]);
        }

        $validation = $this->applier->dryRunFromJson($json);
        if (empty($validation['ok'])) {
            return Result::failure(implode(' / ', $validation['errors'] ?? ['블록 킷 검증에 실패했습니다.']));
        }
        $summary = $validation['summary'] ?? [];

        // target 컬럼은 BlockKitLibrary::toRow 와 같은 규칙으로 채운다
        $target = is_array($kit['target'] ?? null) ? $kit['target'] : [];
        $targetKind = match ($target['kind'] ?? null) {
            BlockKit::TARGET_PAGE => BlockKit::TARGET_PAGE,
            BlockKit::TARGET_SCREEN => BlockKit::TARGET_SCREEN,
            default => BlockKit::TARGET_POSITION,
        };

        $now = date('Y-m-d H:i:s');
        $this->db->insert(
            "INSERT INTO block_kits
                (domain_id, kit_name, kit_description, kit_version, kit_author, kit_author_url,
                 target_kind, target_position, target_menu_code, target_page_code,
                 export_mode, contains_script, row_count, column_count,
                 kit_json, screenshot_path, source_type, is_deleted, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'distribution', ?, ?, ?, ?, NULL, ?, 0, ?, ?)",
            [
                $domainId,
                $kitName,
                mb_substr((string) ($kit['description'] ?? ''), 0, 500),
                mb_substr((string) ($kit['version'] ?? '1.0.0'), 0, 20),
                mb_substr((string) ($kit['author'] ?? ''), 0, 100),
                mb_substr((string) ($kit['author_url'] ?? ''), 0, 255),
                $targetKind,
                $targetKind === BlockKit::TARGET_POSITION ? $this->nullableText($target['position'] ?? null, 20) : null,
                $targetKind === BlockKit::TARGET_POSITION ? $this->nullableText($target['menu_code'] ?? null, 50) : null,
                $targetKind === BlockKit::TARGET_PAGE ? $this->nullableText($target['page_code'] ?? null, 50) : null,
                !empty($summary['contains_script']) ? 1 : 0,
                (int) ($summary['row_count'] ?? 0),
                (int) ($summary['column_count'] ?? 0),
                $json,
                BlockKit::SOURCE_BUNDLED,
                $now,
                $now,
            ]
        );

        $kitId = (int) $this->db->lastInsertId();
        if ($kitId > 0) {
            $this->bakeScreenshot($kitId, $domainId, $kit);
        }

        return Result::success('블록 킷을 보관함에 등록했습니다.', [
            'kit_id' => $kitId > 0 ? $kitId : null,
            'status' => 'created',
        ]);
    }

    public function applyPage(int $domainId, array $kit, string $mode = self::MODE_REPLACE): Result
    {
        if (($kit['target']['kind'] ?? null) !== BlockKit::TARGET_PAGE) {
            return Result::failure('page 타깃 블록 킷이 아닙니다.');
        }

        if (!in_array($mode, [self::MODE_APPEND, self::MODE_REPLACE], true)) {
            return Result::failure("지원하지 않는 적용 모드입니다: {$mode}");
        }

        $result = $this->applier->apply($domainId, $kit, $mode);

        if (empty($result['ok'])) {
            return Result::failure(implode(' / ', $result['errors'] ?? ['블록 킷 적용에 실패했습니다.']));
        }

        return Result::success('블록 킷을 적용했습니다.', [
            'summary' => $result['summary'] ?? [],
            'warnings' => $result['warnings'] ?? [],
        ]);
    }

    /**
     * 킷 JSON 에 임베드된 스크린샷을 목록 썸네일로 굽는다.
     * 실패해도 킷 등록은 유지 — 목록에 "스크린샷 없음"이 뜰 뿐이다.
     */
    private function bakeScreenshot(int $kitId, int $domainId, array $kit): void
    {
        $path = $this->screenshot->store($kit['screenshot'] ?? null, $domainId, $kitId);
        if ($path !== null) {
            $this->db->execute(
                'UPDATE block_kits SET screenshot_path = ? WHERE kit_id = ?',
                [$path, $kitId]
            );
        }
    }

    private function nullableText(mixed $value, int $maxLength): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return mb_substr(trim($value), 0, $maxLength);
    }
}
