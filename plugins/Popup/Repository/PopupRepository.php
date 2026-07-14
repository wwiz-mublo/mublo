<?php

namespace Mublo\Plugin\Popup\Repository;

use Mublo\Infrastructure\Database\Database;

class PopupRepository
{
    private string $table = 'popups';

    public function __construct(private Database $db)
    {
    }

    public function findPaginated(int $domainId, int $page = 1, int $perPage = 20, string $search = ''): array
    {
        $offset = ($page - 1) * $perPage;

        $query = $this->db->table($this->table)
            ->where('domain_id', '=', $domainId);

        if ($search !== '') {
            $query->where('title', 'LIKE', "%{$search}%");
        }

        $totalItems = (clone $query)->count();

        $items = $query
            ->orderBy('sort_order', 'ASC')
            ->orderBy('popup_id', 'DESC')
            ->limit($perPage)
            ->offset($offset)
            ->get();

        return [
            'items' => $items,
            'pagination' => [
                'totalItems' => $totalItems,
                'perPage' => $perPage,
                'currentPage' => $page,
                'totalPages' => max(1, (int) ceil($totalItems / $perPage)),
            ],
        ];
    }

    public function findById(int $domainId, int $popupId): ?array
    {
        $row = $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('popup_id', '=', $popupId)
            ->first();

        return $row ?: null;
    }

    /**
     * 프론트 표시용: 활성 + 기간 내 팝업 조회 (메인 전용)
     */
    public function findActiveForPage(int $domainId): array
    {
        $today = date('Y-m-d');

        return $this->db->select(
            "SELECT * FROM {$this->table}
             WHERE domain_id = ?
               AND is_active = 1
               AND (start_date IS NULL OR start_date <= ?)
               AND (end_date IS NULL OR end_date >= ?)
             ORDER BY sort_order ASC, popup_id DESC",
            [$domainId, $today, $today]
        );
    }

    public function create(int $domainId, array $data): int
    {
        $now = date('Y-m-d H:i:s');

        return (int) $this->db->table($this->table)->insert([
            'domain_id' => $domainId,
            'title' => $data['title'] ?? '',
            'html_content' => $data['html_content'] ?? null,
            'link_url' => $data['link_url'] ?? null,
            'link_target' => $data['link_target'] ?? '_self',
            'position' => $data['position'] ?? 'center',
            'width' => (int) ($data['width'] ?? 500),
            'height' => (int) ($data['height'] ?? 0),
            'display_device' => $data['display_device'] ?? 'all',
            'start_date' => $data['start_date'] ?: null,
            'end_date' => $data['end_date'] ?: null,
            'hide_duration' => (int) ($data['hide_duration'] ?? 24),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'is_active' => (int) ($data['is_active'] ?? 1),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function update(int $domainId, int $popupId, array $data): bool
    {
        $affected = $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('popup_id', '=', $popupId)
            ->update([
                'title' => $data['title'] ?? '',
                'html_content' => $data['html_content'] ?? null,
                'link_url' => $data['link_url'] ?? null,
                'link_target' => $data['link_target'] ?? '_self',
                'position' => $data['position'] ?? 'center',
                'width' => (int) ($data['width'] ?? 500),
                'height' => (int) ($data['height'] ?? 0),
                'display_device' => $data['display_device'] ?? 'all',
                'start_date' => $data['start_date'] ?: null,
                'end_date' => $data['end_date'] ?: null,
                'hide_duration' => (int) ($data['hide_duration'] ?? 24),
                'sort_order' => (int) ($data['sort_order'] ?? 0),
                'is_active' => (int) ($data['is_active'] ?? 1),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        return $affected >= 0;
    }

    public function delete(int $domainId, int $popupId): bool
    {
        $affected = $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('popup_id', '=', $popupId)
            ->delete();

        return $affected > 0;
    }

    public function updateSortOrder(int $domainId, int $popupId, int $order): bool
    {
        $affected = $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('popup_id', '=', $popupId)
            ->update(['sort_order' => $order]);

        return $affected >= 0;
    }
}
