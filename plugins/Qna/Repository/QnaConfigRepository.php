<?php
declare(strict_types=1);
namespace Mublo\Plugin\Qna\Repository;

use Mublo\Infrastructure\Database\Database;

/**
 * QnA 플러그인 도메인별 설정.
 * 설정은 플러그인이 소유한다 — 코어 domain_configs 에 두지 않는다.
 */
class QnaConfigRepository
{
    private const DEFAULTS = [
        'skin_name'        => 'basic',
        'require_login'    => 1,
        'default_secret'   => 1,
        'allow_secret'     => 1,
        'allow_guest'      => 0,
        'use_category'     => 0,
        'notify_email'     => 0,
        'allow_file'       => 1,
        'file_max_mb'      => 5,
        'file_max_count'   => 2,
        'file_allowed_ext' => 'jpg,jpeg,png,gif,pdf,zip,docx,xlsx,pptx,hwp,hwpx',
    ];

    private string $table = 'plugin_qna_configs';

    public function __construct(private Database $db)
    {
    }

    public function findByDomainId(int $domainId): ?array
    {
        return $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->first() ?: null;
    }

    /** 설정 조회 (미설정 항목은 기본값으로 채워서 반환) */
    public function getConfig(int $domainId): array
    {
        $row = $this->findByDomainId($domainId) ?? [];
        return array_merge(self::DEFAULTS, array_intersect_key($row, self::DEFAULTS));
    }

    public function getSkinName(int $domainId): string
    {
        return $this->findByDomainId($domainId)['skin_name'] ?? self::DEFAULTS['skin_name'];
    }

    public function upsert(int $domainId, array $data): bool
    {
        $payload = array_intersect_key($data, self::DEFAULTS);
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
