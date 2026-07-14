<?php

namespace Mublo\Plugin\SendonTalk\Repository;

use Mublo\Infrastructure\Database\Database;

class SendonTalkConfigRepository
{
    private string $table = 'plugin_sendon_talk_configs';

    public function __construct(private Database $db) {}

    public function getConfig(int $domainId): ?array
    {
        return $this->db->table($this->table)->where('domain_id', '=', $domainId)->first() ?: null;
    }

    public function saveConfig(int $domainId, array $data): bool
    {
        $existing = $this->getConfig($domainId);
        $data['updated_at'] = date('Y-m-d H:i:s');

        if ($existing) {
            return $this->db->table($this->table)->where('config_id', '=', $existing['config_id'])->update($data) >= 0;
        }

        $data['domain_id'] = $domainId;
        $data['created_at'] = date('Y-m-d H:i:s');
        return $this->db->table($this->table)->insert($data) > 0;
    }
}
