<?php
declare(strict_types=1);
namespace Mublo\Repository\AI;

use Mublo\Infrastructure\Database\Database;
use Mublo\Infrastructure\Database\DatabaseManager;

class AiGenerationRecordRepository
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? DatabaseManager::getInstance()->connect();
    }

    public function create(array $data): int
    {
        return $this->db->insert(
            'INSERT INTO domain_ai_generation_records
                (domain_id, member_id, row_id, column_index, mode, provider, model, prompt,
                 asset_ids_json, result_html, result_css, result_js, notes, status, error_message)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $data['domain_id'], $data['member_id'] ?? null, $data['row_id'], $data['column_index'],
                $data['mode'], $data['provider'], $data['model'], $data['prompt'],
                json_encode($data['asset_ids'] ?? [], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                $data['result_html'] ?? null, $data['result_css'] ?? null, $data['result_js'] ?? null,
                $data['notes'] ?? null, $data['status'] ?? 'success', $data['error_message'] ?? null,
            ]
        );
    }

    public function recent(int $domainId, int $limit = 20): array
    {
        $limit = max(1, min(50, $limit));
        return $this->db->select(
            "SELECT record_id, row_id, column_index, mode, provider, model, prompt, asset_ids_json,
                    notes, status, created_at
               FROM domain_ai_generation_records WHERE domain_id = ?
              ORDER BY created_at DESC, record_id DESC LIMIT {$limit}",
            [$domainId]
        );
    }

    /**
     * 특정 mode들의 최근 이력 (프레임 편집 이력용 — 결과물 제외 목록)
     *
     * 계약 검증 실패(invalid)도 포함한다 — 자동 반영되지 않은 결과를 운영자가
     * 이력에서 확인·수동 수정할 수 있어야 한다 (개선 계획 §11).
     *
     * @param string[] $modes 예: ['frame_header']
     */
    public function recentByModes(int $domainId, array $modes, int $limit = 10): array
    {
        if ($modes === []) return [];
        $limit = max(1, min(50, $limit));
        $placeholders = implode(', ', array_fill(0, count($modes), '?'));

        return $this->db->select(
            "SELECT record_id, mode, provider, model, prompt, notes, status, created_at
               FROM domain_ai_generation_records
              WHERE domain_id = ? AND status IN ('success', 'invalid') AND mode IN ({$placeholders})
              ORDER BY created_at DESC, record_id DESC LIMIT {$limit}",
            array_merge([$domainId], array_values($modes))
        );
    }

    /**
     * 단건 조회 (결과물 포함) — "이 결과에서 이어서" 복원용. 도메인 스코프 강제.
     */
    public function find(int $domainId, int $recordId): ?array
    {
        $rows = $this->db->select(
            'SELECT record_id, mode, prompt, result_html, result_css, result_js, notes, created_at
               FROM domain_ai_generation_records
              WHERE domain_id = ? AND record_id = ? LIMIT 1',
            [$domainId, $recordId]
        );

        return $rows[0] ?? null;
    }
}
