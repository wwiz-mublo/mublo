<?php
declare(strict_types=1);

namespace Mublo\Plugin\SendonSms\Repository;

use Mublo\Infrastructure\Database\Database;

class SendonSmsChannelRepository
{
    private string $table = 'plugin_sendon_sms_channels';

    public function __construct(private Database $db)
    {
    }

    public function getList(int $domainId, int $limit = 20, int $offset = 0): array
    {
        return $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->orderBy('channel_id', 'DESC')
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

    public function findById(int $domainId, int $channelId): ?array
    {
        $row = $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('channel_id', '=', $channelId)
            ->first();

        return $row ?: null;
    }

    public function create(int $domainId, array $data): int
    {
        $now = date('Y-m-d H:i:s');

        return (int) $this->db->table($this->table)->insert([
            'domain_id' => $domainId,
            'channel_name' => $data['channel_name'] ?? '',
            'sender_number' => $data['sender_number'] ?? '',
            'variables' => $data['variables'] ?? null,
            'is_active' => (int) ($data['is_active'] ?? 1),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function update(int $domainId, int $channelId, array $data): bool
    {
        $affected = $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('channel_id', '=', $channelId)
            ->update([
                'channel_name' => $data['channel_name'] ?? '',
                'sender_number' => $data['sender_number'] ?? '',
                'variables' => $data['variables'] ?? null,
                'is_active' => (int) ($data['is_active'] ?? 1),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        return $affected >= 0;
    }

    public function delete(int $domainId, int $channelId): bool
    {
        $affected = $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('channel_id', '=', $channelId)
            ->delete();

        return $affected > 0;
    }
}
