<?php

namespace Mublo\Plugin\Faq\Repository;

use Mublo\Infrastructure\Database\Database;

/**
 * FAQ 플러그인 도메인별 설정 (프론트 스킨).
 * 스킨 설정은 플러그인이 소유한다 — 코어 domain_configs 에 두지 않는다.
 */
class FaqConfigRepository
{
    private string $table = 'plugin_faq_configs';

    public function __construct(private Database $db)
    {
    }

    public function findByDomainId(int $domainId): ?array
    {
        return $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->first() ?: null;
    }

    /** 도메인의 스킨명 (미설정 시 basic) */
    public function getSkinName(int $domainId): string
    {
        return $this->findByDomainId($domainId)['skin_name'] ?? 'basic';
    }

    public function upsert(int $domainId, array $data): bool
    {
        $existing = $this->findByDomainId($domainId);

        $payload = [
            'skin_name'  => $data['skin_name'] ?? 'basic',
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($existing) {
            $this->db->table($this->table)
                ->where('domain_id', '=', $domainId)
                ->update($payload);
        } else {
            $payload['domain_id'] = $domainId;
            $this->db->table($this->table)->insert($payload);
        }

        return true;
    }
}
