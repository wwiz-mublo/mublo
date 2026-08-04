<?php
declare(strict_types=1);
namespace Mublo\Plugin\Manual\Repository;

use Mublo\Infrastructure\Database\Database;

/**
 * 매뉴얼 플러그인 도메인별 설정.
 * 설정은 플러그인이 소유한다 — 코어 domain_configs 에 두지 않는다.
 */
class ManualConfigRepository
{
    private const DEFAULTS = [
        'skin_name' => 'basic',
    ];

    private string $table = 'plugin_manual_configs';

    public function __construct(private Database $db)
    {
    }

    /**
     * 설정 행 조회.
     *
     * 이 테이블은 플러그인 배포 후 추가된 마이그레이션(004)이라, 기존 설치본에서는
     * 관리자가 설치를 실행하기 전까지 존재하지 않는다. 그 사이 프론트가 죽지 않도록
     * 조회 실패는 "미설정"으로 간주해 기본값 폴백에 맡긴다.
     */
    public function findByDomainId(int $domainId): ?array
    {
        try {
            return $this->db->table($this->table)
                ->where('domain_id', '=', $domainId)
                ->first() ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** 설정 조회 (미설정 항목은 기본값으로 채워서 반환) */
    public function getConfig(int $domainId): array
    {
        $row = $this->findByDomainId($domainId) ?? [];
        return array_merge(self::DEFAULTS, array_intersect_key($row, self::DEFAULTS));
    }

    /** 도메인의 스킨명 (미설정 시 basic) */
    public function getSkinName(int $domainId): string
    {
        return $this->findByDomainId($domainId)['skin_name'] ?? self::DEFAULTS['skin_name'];
    }

    public function upsert(int $domainId, array $data): bool
    {
        $payload = array_intersect_key($data, self::DEFAULTS);
        if (empty($payload)) {
            return false;
        }

        $payload['updated_at'] = date('Y-m-d H:i:s');

        if ($this->findByDomainId($domainId)) {
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
