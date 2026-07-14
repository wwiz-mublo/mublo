<?php

namespace Mublo\Repository\Block;

use Mublo\Infrastructure\Database\Database;
use Mublo\Infrastructure\Database\DatabaseManager;

/** 블록 행 변경 전 스냅샷 저장소. */
final class BlockRowRevisionRepository
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? DatabaseManager::getInstance()->connect();
    }

    public function create(
        int $domainId,
        int $rowId,
        int $rowRevisionNo,
        array $snapshot,
        string $source = 'interactive',
        ?int $createdBy = null
    ): int {
        return (int) $this->db->table('block_row_revisions')->insert([
            'domain_id' => $domainId,
            'row_id' => $rowId,
            'row_revision_no' => $rowRevisionNo,
            'source' => $source,
            'snapshot_json' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'created_by' => $createdBy,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** @return array<string, mixed>|null */
    public function findScoped(int $domainId, int $revisionId): ?array
    {
        $row = $this->db->table('block_row_revisions')
            ->where('revision_id', '=', $revisionId)
            ->where('domain_id', '=', $domainId)
            ->first();
        if (!$row) {
            return null;
        }
        $row['snapshot'] = json_decode((string) $row['snapshot_json'], true);
        unset($row['snapshot_json']);
        return is_array($row['snapshot']) ? $row : null;
    }

    /** @return array<int, array<string, mixed>> */
    public function findByRow(int $domainId, int $rowId, int $limit = 20): array
    {
        return $this->db->table('block_row_revisions')
            ->select(['revision_id', 'row_id', 'row_revision_no', 'source', 'created_by', 'created_at'])
            ->where('domain_id', '=', $domainId)
            ->where('row_id', '=', $rowId)
            ->orderBy('revision_id', 'DESC')
            ->limit(max(1, min($limit, 100)))
            ->get();
    }

    /** @return array<int, array<string, mixed>> 삭제됐고 아직 복구되지 않은 행 이력 */
    public function findRestorableDeleted(int $domainId, int $limit = 50): array
    {
        $limit = max(1, min($limit, 100));
        $rows = $this->db->select(
            'SELECT r.revision_id, r.row_id, r.row_revision_no, r.source, r.snapshot_json, '
            . 'r.created_by, r.created_at FROM block_row_revisions r '
            . 'LEFT JOIN block_rows b ON b.row_id = r.row_id AND b.domain_id = r.domain_id '
            . 'WHERE r.domain_id = ? AND r.restored_at IS NULL AND b.row_id IS NULL '
            . 'AND r.revision_id = (SELECT MAX(r2.revision_id) FROM block_row_revisions r2 '
            . 'WHERE r2.domain_id = r.domain_id AND r2.row_id = r.row_id AND r2.restored_at IS NULL) '
            . 'ORDER BY r.revision_id DESC LIMIT ' . $limit,
            [$domainId]
        );

        $items = [];
        foreach ($rows as $row) {
            $snapshot = json_decode((string) ($row['snapshot_json'] ?? ''), true);
            $rowData = is_array($snapshot) && is_array($snapshot['row'] ?? null)
                ? $snapshot['row']
                : [];
            unset($row['snapshot_json']);
            $row['admin_title'] = (string) ($rowData['admin_title'] ?? '이름 없는 행');
            $row['position'] = $rowData['position'] ?? null;
            $row['page_id'] = $rowData['page_id'] ?? null;
            $row['source_label'] = match ($row['source'] ?? '') {
                'kit_replace' => '블록 킷 교체',
                'delete' => '직접 삭제',
                default => '변경 이력',
            };
            $items[] = $row;
        }

        return $items;
    }

    public function markRestored(int $domainId, int $originalRowId, int $restoredRowId): void
    {
        $this->db->table('block_row_revisions')
            ->where('domain_id', '=', $domainId)
            ->where('row_id', '=', $originalRowId)
            ->update([
                'restored_row_id' => $restoredRowId,
                'restored_at' => date('Y-m-d H:i:s'),
            ]);
    }

    /**
     * 동일한 원본 행의 삭제 이력 전체를 트랜잭션 내에서 선점한다.
     * 서로 다른 revision_id를 동시에 복구해도 행이 중복 생성되지 않는다.
     */
    public function claimRestore(int $domainId, int $originalRowId): bool
    {
        return $this->db->table('block_row_revisions')
            ->where('domain_id', '=', $domainId)
            ->where('row_id', '=', $originalRowId)
            ->whereNull('restored_at')
            ->update(['restored_at' => date('Y-m-d H:i:s')]) > 0;
    }

    /** @return array<int, array<string, mixed>> 삭제된 스냅샷(고아 이미지 정리용) */
    public function prune(int $domainId, int $rowId, int $keep = 30): array
    {
        $rows = $this->db->table('block_row_revisions')
            ->select(['revision_id', 'snapshot_json'])
            ->where('domain_id', '=', $domainId)
            ->where('row_id', '=', $rowId)
            ->orderBy('revision_id', 'DESC')
            ->limit(1000)
            ->get();
        $deletedSnapshots = [];
        foreach (array_slice($rows, max(1, $keep)) as $row) {
            $this->db->table('block_row_revisions')
                ->where('revision_id', '=', (int) $row['revision_id'])
                ->where('domain_id', '=', $domainId)
                ->delete();
            $snapshot = json_decode((string) ($row['snapshot_json'] ?? ''), true);
            if (is_array($snapshot)) {
                $deletedSnapshots[] = $snapshot;
            }
        }

        return $deletedSnapshots;
    }
}
