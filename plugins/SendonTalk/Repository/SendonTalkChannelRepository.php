<?php
declare(strict_types=1);

namespace Mublo\Plugin\SendonTalk\Repository;

use Mublo\Infrastructure\Database\Database;

class SendonTalkChannelRepository
{
    private string $table = 'plugin_sendon_talk_channels';

    public function __construct(private Database $db) {}

    public function getList(int $domainId): array
    {
        return $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->orderBy('channel_id', 'ASC')
            ->get();
    }

    public function findById(int $domainId, int $channelId): ?array
    {
        return $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('channel_id', '=', $channelId)
            ->first() ?: null;
    }

    public function findBySendProfileId(int $domainId, string $sendProfileId): ?array
    {
        return $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('send_profile_id', '=', $sendProfileId)
            ->first() ?: null;
    }

    public function create(int $domainId, array $data): int
    {
        $now = date('Y-m-d H:i:s');
        return (int) $this->db->table($this->table)->insert([
            'domain_id'       => $domainId,
            'channel_name'    => $data['channel_name'] ?? '',
            'send_profile_id' => $data['send_profile_id'] ?? '',
            'variables'       => $data['variables'] ?? null,
            'is_active'       => (int) ($data['is_active'] ?? 1),
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);
    }

    public function update(int $domainId, int $channelId, array $data): bool
    {
        return $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('channel_id', '=', $channelId)
            ->update([
                'channel_name'    => $data['channel_name'] ?? '',
                'send_profile_id' => $data['send_profile_id'] ?? '',
                'variables'       => $data['variables'] ?? null,
                'is_active'       => (int) ($data['is_active'] ?? 1),
                'updated_at'      => date('Y-m-d H:i:s'),
            ]) >= 0;
    }

    public function delete(int $domainId, int $channelId): bool
    {
        return $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('channel_id', '=', $channelId)
            ->delete() > 0;
    }

    public function getTemplateCount(int $domainId, int $channelId): int
    {
        return $this->db->table('plugin_sendon_talk_templates')
            ->where('domain_id', '=', $domainId)
            ->where('channel_id', '=', $channelId)
            ->count();
    }
}
