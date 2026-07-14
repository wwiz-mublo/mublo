<?php

namespace Mublo\Packages\Shop\Repository;

use Mublo\Infrastructure\Database\Database;
use Mublo\Infrastructure\Database\DatabaseManager;
use Mublo\Packages\Shop\Entity\MemberAddress;
use Mublo\Repository\BaseRepository;

/**
 * MemberAddress Repository
 *
 * 회원 배송지 주소록 데이터베이스 접근 담당
 */
class MemberAddressRepository extends BaseRepository
{
    protected string $table = 'shop_member_addresses';
    protected string $entityClass = MemberAddress::class;
    protected string $primaryKey = 'address_id';

    public function __construct(?Database $db = null)
    {
        $db = $db ?? DatabaseManager::getInstance()->connect();
        parent::__construct($db);
    }

    /**
     * 회원의 배송지 목록 조회 (기본배송지 우선, 최근 수정순)
     *
     * @return MemberAddress[]
     */
    public function findByMember(int $memberId, int $domainId): array
    {
        $rows = $this->getDb()->table($this->table)
            ->where('member_id', '=', $memberId)
            ->where('domain_id', '=', $domainId)
            ->orderBy('is_default', 'DESC')
            ->orderBy('updated_at', 'DESC')
            ->get();

        return $this->toEntities($rows);
    }

    /**
     * 기본 배송지 조회
     */
    public function getDefault(int $memberId, int $domainId): ?MemberAddress
    {
        $row = $this->getDb()->table($this->table)
            ->where('member_id', '=', $memberId)
            ->where('domain_id', '=', $domainId)
            ->where('is_default', '=', 1)
            ->first();

        return $row ? MemberAddress::fromArray($row) : null;
    }

    /**
     * 회원의 배송지 개수
     */
    public function countByMember(int $memberId, int $domainId): int
    {
        return $this->getDb()->table($this->table)
            ->where('member_id', '=', $memberId)
            ->where('domain_id', '=', $domainId)
            ->count();
    }

    /**
     * 기본 배송지 설정 (기존 해제 → 새로 설정)
     */
    public function setDefault(int $memberId, int $domainId, int $addressId): void
    {
        // 기존 기본 배송지 해제
        $this->getDb()->table($this->table)
            ->where('member_id', '=', $memberId)
            ->where('domain_id', '=', $domainId)
            ->where('is_default', '=', 1)
            ->update(['is_default' => 0]);

        // 새 기본 배송지 설정
        $this->getDb()->table($this->table)
            ->where('address_id', '=', $addressId)
            ->where('member_id', '=', $memberId)
            ->update(['is_default' => 1]);
    }

    /**
     * 배송지 수정
     */
    public function updateAddress(int $addressId, array $data): bool
    {
        $data['updated_at'] = date('Y-m-d H:i:s');

        $affected = $this->getDb()->table($this->table)
            ->where('address_id', '=', $addressId)
            ->update($data);

        return $affected > 0;
    }
}
