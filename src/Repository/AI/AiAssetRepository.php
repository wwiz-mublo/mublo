<?php
declare(strict_types=1);
namespace Mublo\Repository\AI;

use Mublo\Infrastructure\Database\Database;
use Mublo\Infrastructure\Database\DatabaseManager;

class AiAssetRepository
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? DatabaseManager::getInstance()->connect();
    }

    public function listByDomain(int $domainId, int $limit = 100): array
    {
        $limit = max(1, min(200, $limit));
        return $this->db->select(
            "SELECT asset_id, domain_id, parent_asset_id, kind, title, original_name, extension,
                    mime_type, file_size, storage_path, sha256, extracted_text, metadata_json,
                    created_by, created_at, updated_at
               FROM domain_ai_assets WHERE domain_id = ? ORDER BY created_at DESC LIMIT {$limit}",
            [$domainId]
        );
    }

    public function find(int $domainId, int $assetId): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM domain_ai_assets WHERE domain_id = ? AND asset_id = ?',
            [$domainId, $assetId]
        );
    }

    public function findByHash(int $domainId, string $sha256): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM domain_ai_assets WHERE domain_id = ? AND sha256 = ?',
            [$domainId, $sha256]
        );
    }

    public function findMany(int $domainId, array $assetIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $assetIds), fn (int $id) => $id > 0)));
        if (!$ids) return [];
        $marks = implode(',', array_fill(0, count($ids), '?'));
        return $this->db->select(
            "SELECT * FROM domain_ai_assets WHERE domain_id = ? AND asset_id IN ({$marks})",
            array_merge([$domainId], $ids)
        );
    }

    public function create(array $data): int
    {
        return $this->db->insert(
            'INSERT INTO domain_ai_assets
                (domain_id, parent_asset_id, kind, title, original_name, extension, mime_type,
                 file_size, storage_path, sha256, extracted_text, metadata_json, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $data['domain_id'], $data['parent_asset_id'] ?? null, $data['kind'], $data['title'],
                $data['original_name'], $data['extension'], $data['mime_type'], $data['file_size'],
                $data['storage_path'], $data['sha256'], $data['extracted_text'] ?? null,
                $data['metadata_json'] ?? null, $data['created_by'] ?? null,
            ]
        );
    }

    public function delete(int $domainId, int $assetId): bool
    {
        return $this->db->execute(
            'DELETE FROM domain_ai_assets WHERE domain_id = ? AND asset_id = ?',
            [$domainId, $assetId]
        ) > 0;
    }
}
