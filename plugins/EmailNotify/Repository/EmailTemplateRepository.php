<?php

namespace Mublo\Plugin\EmailNotify\Repository;

use Mublo\Infrastructure\Database\Database;

class EmailTemplateRepository
{
    private string $table = 'plugin_email_notify_templates';

    public function __construct(private Database $db)
    {
    }

    public function getList(int $domainId, int $limit = 50, int $offset = 0): array
    {
        return $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->orderBy('template_id', 'DESC')
            ->limit($limit)
            ->offset($offset)
            ->get();
    }

    public function countByDomain(int $domainId): int
    {
        return $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->count();
    }

    public function existsByCode(int $domainId, string $templateCode, int $excludeId = 0): bool
    {
        $query = $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('template_code', '=', $templateCode);

        if ($excludeId > 0) {
            $query->where('template_id', '!=', $excludeId);
        }

        return $query->count() > 0;
    }

    public function findById(int $domainId, int $templateId): ?array
    {
        $row = $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('template_id', '=', $templateId)
            ->first();

        return $row ?: null;
    }

    public function findByCode(int $domainId, string $templateCode): ?array
    {
        $row = $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('template_code', '=', $templateCode)
            ->first();

        return $row ?: null;
    }

    public function create(int $domainId, array $data): int
    {
        $now = date('Y-m-d H:i:s');

        return (int) $this->db->table($this->table)->insert([
            'domain_id' => $domainId,
            'template_code' => $data['template_code'] ?? '',
            'template_name' => $data['template_name'] ?? '',
            'subject' => $data['subject'] ?? '',
            'body' => $data['body'] ?? '',
            'is_active' => (int) ($data['is_active'] ?? 1),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function update(int $domainId, int $templateId, array $data): bool
    {
        $affected = $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('template_id', '=', $templateId)
            ->update([
                'template_code' => $data['template_code'] ?? '',
                'template_name' => $data['template_name'] ?? '',
                'subject' => $data['subject'] ?? '',
                'body' => $data['body'] ?? '',
                'is_active' => (int) ($data['is_active'] ?? 1),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        return $affected >= 0;
    }

    public function delete(int $domainId, int $templateId): bool
    {
        $affected = $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('template_id', '=', $templateId)
            ->delete();

        return $affected > 0;
    }
}
