<?php
declare(strict_types=1);

namespace Mublo\Plugin\SendonSms\Repository;

use Mublo\Infrastructure\Database\Database;

class SendonSmsTemplateRepository
{
    private string $table = 'plugin_sendon_sms_templates';

    public function __construct(private Database $db)
    {
    }

    public function getByChannel(int $domainId, int $channelId, int $limit = 20, int $offset = 0): array
    {
        return $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('channel_id', '=', $channelId)
            ->orderBy('template_id', 'DESC')
            ->limit($limit)
            ->offset($offset)
            ->get();
    }

    public function countByChannel(int $domainId, int $channelId): int
    {
        return $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('channel_id', '=', $channelId)
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
            'channel_id' => $data['channel_id'] ?? 0,
            'template_code' => $data['template_code'] ?? '',
            'template_name' => $data['template_name'] ?? '',
            'message_type' => $data['message_type'] ?? 'SMS',
            'title' => $data['title'] ?? '',
            'message_body' => $data['message_body'] ?? '',
            'image_ids' => $data['image_ids'] ?? null,
            'variable_schema' => $data['variable_schema'] ?? null,
            'admin_recipients' => $data['admin_recipients'] ?? '',
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
                'channel_id' => $data['channel_id'] ?? 0,
                'template_code' => $data['template_code'] ?? '',
                'template_name' => $data['template_name'] ?? '',
                'message_type' => $data['message_type'] ?? 'SMS',
                'title' => $data['title'] ?? '',
                'message_body' => $data['message_body'] ?? '',
                'image_ids' => $data['image_ids'] ?? null,
                'variable_schema' => $data['variable_schema'] ?? null,
                'admin_recipients' => $data['admin_recipients'] ?? '',
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
