<?php

namespace Mublo\Plugin\SendonTalk\Repository;

use Mublo\Infrastructure\Database\Database;

class SendonTalkLogRepository
{
    private string $table = 'plugin_sendon_talk_logs';

    public function __construct(private Database $db) {}

    public function create(int $domainId, array $data): int
    {
        $data['domain_id'] = $domainId;
        $data['created_at'] = date('Y-m-d H:i:s');
        return (int) $this->db->table($this->table)->insert($data);
    }

    public function getList(int $domainId, int $limit = 20, int $offset = 0): array
    {
        return $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->orderBy('log_id', 'DESC')
            ->limit($limit)->offset($offset)->get();
    }

    public function count(int $domainId): int
    {
        return $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->count();
    }
}
