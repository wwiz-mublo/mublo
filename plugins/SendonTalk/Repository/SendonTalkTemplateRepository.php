<?php

namespace Mublo\Plugin\SendonTalk\Repository;

use Mublo\Infrastructure\Database\Database;

class SendonTalkTemplateRepository
{
    private string $table = 'plugin_sendon_talk_templates';

    public function __construct(private Database $db) {}

    public function getByDomain(int $domainId, int $limit = 50, int $offset = 0): array
    {
        return $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->orderBy('template_id', 'DESC')
            ->limit($limit)->offset($offset)->get();
    }

    public function getByChannel(int $domainId, int $channelId): array
    {
        return $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('channel_id', '=', $channelId)
            ->orderBy('template_id', 'ASC')
            ->get();
    }

    public function countByDomain(int $domainId): int
    {
        return $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->count();
    }

    public function findById(int $domainId, int $templateId): ?array
    {
        return $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('template_id', '=', $templateId)
            ->first() ?: null;
    }

    public function findByCode(int $domainId, string $templateCode): ?array
    {
        return $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('template_code', '=', $templateCode)
            ->first() ?: null;
    }

    public function findByKakaoTemplateId(int $domainId, string $kakaoTemplateId): ?array
    {
        return $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('kakao_template_id', '=', $kakaoTemplateId)
            ->first() ?: null;
    }

    public function create(int $domainId, array $data): int
    {
        $data['domain_id'] = $domainId;
        $data['created_at'] = date('Y-m-d H:i:s');
        return (int) $this->db->table($this->table)->insert($data);
    }

    public function update(int $templateId, array $data): bool
    {
        $data['updated_at'] = date('Y-m-d H:i:s');
        return $this->db->table($this->table)->where('template_id', '=', $templateId)->update($data) >= 0;
    }

    public function delete(int $domainId, int $templateId): bool
    {
        return $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('template_id', '=', $templateId)
            ->delete() > 0;
    }
}
