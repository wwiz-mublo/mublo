<?php

namespace Mublo\Repository;

use Mublo\Infrastructure\Database\Database;
use Mublo\Infrastructure\Database\DatabaseManager;

class DashboardLayoutRepository
{
    private Database $db;
    private string $table = 'admin_dashboard_layout';

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? DatabaseManager::getInstance()->connect();
    }

    /**
     * 사용자 레이아웃 조회
     *
     * @return array<int, array>
     */
    public function findByUser(int $domainId, int $userId): array
    {
        return $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('user_id', '=', $userId)
            ->get();
    }

    /**
     * 위젯 레이아웃 단건 조회
     */
    public function findByUserWidget(int $domainId, int $userId, string $widgetId): ?array
    {
        return $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('user_id', '=', $userId)
            ->where('widget_id', '=', $widgetId)
            ->first();
    }

    /**
     * 레이아웃 저장 (upsert)
     */
    public function save(int $domainId, int $userId, string $widgetId, array $data): void
    {
        $existing = $this->findByUserWidget($domainId, $userId, $widgetId);

        if ($existing) {
            $data['updated_at'] = date('Y-m-d H:i:s');
            $this->db->table($this->table)
                ->where('id', '=', $existing['id'])
                ->update($data);
        } else {
            $this->db->table($this->table)->insert(array_merge([
                'domain_id' => $domainId,
                'user_id'   => $userId,
                'widget_id' => $widgetId,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ], $data));
        }
    }

    /**
     * 사용자 레이아웃 전체 삭제 (AUTO 복귀)
     */
    public function deleteByUser(int $domainId, int $userId): int
    {
        return $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('user_id', '=', $userId)
            ->delete();
    }

    /**
     * 전체 레이아웃 일괄 저장 (MANUAL 모드 전환 시)
     *
     * @param array $entries [['widget_id' => ..., 'row' => ..., 'col' => ..., 'slot_size' => ..., 'hidden' => ...], ...]
     */
    public function saveAll(int $domainId, int $userId, array $entries): void
    {
        $this->deleteByUser($domainId, $userId);

        $now = date('Y-m-d H:i:s');
        foreach ($entries as $entry) {
            $this->db->table($this->table)->insert([
                'domain_id' => $domainId,
                'user_id'   => $userId,
                'widget_id' => $entry['widget_id'],
                'row'       => $entry['row'] ?? null,
                'col'       => $entry['col'] ?? null,
                'slot_size' => $entry['slot_size'] ?? null,
                'hidden'    => $entry['hidden'] ?? 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
