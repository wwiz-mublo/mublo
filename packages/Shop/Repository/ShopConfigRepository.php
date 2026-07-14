<?php

namespace Mublo\Packages\Shop\Repository;

use Mublo\Infrastructure\Database\Database;
use Mublo\Infrastructure\Database\DatabaseManager;
use Mublo\Repository\BaseRepository;

/**
 * ShopConfig Repository
 *
 * 쇼핑몰 설정 데이터베이스 접근 담당
 *
 * 책임:
 * - shop_config 테이블 CRUD
 * - 도메인별 쇼핑몰 설정 관리
 * - Entity 없이 raw array 반환
 *
 * 금지:
 * - 비즈니스 로직 (Service 담당)
 */
class ShopConfigRepository extends BaseRepository
{
    protected string $table = 'shop_config';
    protected string $entityClass = '';
    protected string $primaryKey = 'config_id';

    public function __construct(?Database $db = null)
    {
        $db = $db ?? DatabaseManager::getInstance()->connect();
        parent::__construct($db);
    }

    /**
     * 도메인별 쇼핑몰 설정 조회
     *
     * @param int $domainId 도메인 ID
     * @return array|null 설정 배열 또는 null
     */
    public function getConfig(int $domainId): ?array
    {
        $row = $this->getDb()->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->first();

        return $row ?: null;
    }

    /**
     * 도메인별 쇼핑몰 설정 저장 (upsert)
     *
     * 기존 설정이 있으면 업데이트, 없으면 생성
     *
     * @param int $domainId 도메인 ID
     * @param array $data 설정 데이터
     * @return bool 성공 여부
     */
    public function saveConfig(int $domainId, array $data): bool
    {
        // domain_group 자동 스냅샷 (domain_configs에서 조회)
        if (empty($data['domain_group'])) {
            $domain = $this->getDb()->table('domain_configs')
                ->where('domain_id', '=', $domainId)
                ->first();
            if ($domain && !empty($domain['domain_group'])) {
                $data['domain_group'] = $domain['domain_group'];
            }
        }

        $existing = $this->getDb()->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->first();

        if ($existing) {
            $data['updated_at'] = date('Y-m-d H:i:s');
            $affected = $this->getDb()->table($this->table)
                ->where('config_id', '=', $existing['config_id'])
                ->update($data);

            return $affected >= 0;
        }

        $data['domain_id'] = $domainId;
        $data['created_at'] = date('Y-m-d H:i:s');
        $insertId = $this->getDb()->table($this->table)->insert($data);

        return $insertId !== null;
    }

    /**
     * Entity 변환 비활성화 (raw array 사용)
     */
    protected function toEntity(array $row): object
    {
        return (object) $row;
    }

    /**
     * 생성 타임스탬프 필드명
     */
    protected function getCreatedAtField(): ?string
    {
        return 'created_at';
    }

    /**
     * 수정 타임스탬프 필드명
     */
    protected function getUpdatedAtField(): ?string
    {
        return 'updated_at';
    }
}
