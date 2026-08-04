<?php
declare(strict_types=1);

namespace Mublo\Plugin\Widget\Repository;

use Mublo\Infrastructure\Database\Database;

class WidgetItemRepository
{
    private string $table = 'plugin_widget_items';

    public function __construct(private Database $db)
    {
    }

    public function getList(int $domainId, int $limit = 50, int $offset = 0, string $position = '', string $keyword = ''): array
    {
        $query = $this->db->table($this->table)
            ->where('domain_id', '=', $domainId);

        if ($position !== '') {
            $query->where('position', '=', $position);
        }

        if ($keyword !== '') {
            $query->where('title', 'LIKE', "%{$keyword}%");
        }

        return $query
            ->orderBy('position', 'ASC')
            ->orderBy('sort_order', 'ASC')
            ->orderBy('item_id', 'DESC')
            ->limit($limit)
            ->offset($offset)
            ->get();
    }

    public function countByDomain(int $domainId, string $position = '', string $keyword = ''): int
    {
        $query = $this->db->table($this->table)
            ->where('domain_id', '=', $domainId);

        if ($position !== '') {
            $query->where('position', '=', $position);
        }

        if ($keyword !== '') {
            $query->where('title', 'LIKE', "%{$keyword}%");
        }

        return $query->count();
    }

    public function findById(int $domainId, int $itemId): ?array
    {
        $row = $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('item_id', '=', $itemId)
            ->first();

        return $row ?: null;
    }

    /**
     * 프론트용: 활성 아이템을 position별로 그룹핑
     */
    public function findActiveGrouped(int $domainId): array
    {
        $items = $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('is_active', '=', 1)
            ->orderBy('sort_order', 'ASC')
            ->orderBy('item_id', 'ASC')
            ->get();

        $grouped = ['left' => [], 'right' => [], 'mobile' => []];
        foreach ($items as $item) {
            $pos = $item['position'] ?? 'right';
            if (isset($grouped[$pos])) {
                $grouped[$pos][] = $item;
            }
        }

        return $grouped;
    }

    public function create(int $domainId, array $data): int
    {
        $now = date('Y-m-d H:i:s');

        return (int) $this->db->table($this->table)->insert([
            'domain_id' => $domainId,
            'position' => $data['position'] ?? 'right',
            'item_type' => $data['item_type'] ?? 'link',
            'title' => $data['title'] ?? '',
            'icon_image' => $data['icon_image'] ?? null,
            'link_url' => $data['link_url'] ?? null,
            'link_target' => $data['link_target'] ?? '_blank',

            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'is_active' => (int) ($data['is_active'] ?? 1),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function update(int $domainId, int $itemId, array $data): bool
    {
        $affected = $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('item_id', '=', $itemId)
            ->update([
                'position' => $data['position'] ?? 'right',
                'item_type' => $data['item_type'] ?? 'link',
                'title' => $data['title'] ?? '',
                'icon_image' => $data['icon_image'] ?? null,
                'link_url' => $data['link_url'] ?? null,
                'link_target' => $data['link_target'] ?? '_blank',
    
                'sort_order' => (int) ($data['sort_order'] ?? 0),
                'is_active' => (int) ($data['is_active'] ?? 1),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        return $affected >= 0;
    }

    public function delete(int $domainId, int $itemId): bool
    {
        return $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('item_id', '=', $itemId)
            ->delete() > 0;
    }

    public function updateSortOrder(int $domainId, int $itemId, int $order): bool
    {
        return $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('item_id', '=', $itemId)
            ->update(['sort_order' => $order]) >= 0;
    }
}
