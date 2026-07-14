<?php

namespace Mublo\Packages\Shop\Repository;

use Mublo\Infrastructure\Database\Database;
use Mublo\Infrastructure\Database\DatabaseManager;
use Mublo\Packages\Shop\Entity\ShippingTemplate;
use Mublo\Repository\BaseRepository;

/**
 * Shipping Repository
 *
 * 배송 템플릿 데이터베이스 접근 담당
 *
 * 책임:
 * - shop_shipping_templates 테이블 CRUD
 * - shop_delivery_companies 테이블 조회
 * - ShippingTemplate Entity 반환
 *
 * 금지:
 * - 비즈니스 로직 (Service 담당)
 */
class ShippingRepository extends BaseRepository
{
    protected string $table = 'shop_shipping_templates';
    protected string $entityClass = ShippingTemplate::class;
    protected string $primaryKey = 'shipping_id';

    private string $deliveryCompaniesTable = 'shop_delivery_companies';

    public function __construct(?Database $db = null)
    {
        $db = $db ?? DatabaseManager::getInstance()->connect();
        parent::__construct($db);
    }

    /**
     * 도메인별 배송 템플릿 전체 목록
     *
     * @param int $domainId 도메인 ID
     * @return ShippingTemplate[]
     */
    public function getList(int $domainId): array
    {
        $rows = $this->getDb()->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->orderBy('shipping_id', 'ASC')
            ->get();

        return $this->toEntities($rows);
    }

    /** 현재 쇼핑몰에 속한 배송 템플릿을 조회한다. */
    public function findInDomain(int $domainId, int $shippingId, bool $activeOnly = false): ?ShippingTemplate
    {
        $query = $this->getDb()->table($this->table)
            ->where('shipping_id', '=', $shippingId)
            ->where('domain_id', '=', $domainId);

        if ($activeOnly) {
            $query->where('is_active', '=', 1);
        }

        $row = $query->first();

        return $row ? ShippingTemplate::fromArray($row) : null;
    }

    /** 현재 쇼핑몰에 속한 배송 템플릿만 수정한다. */
    public function updateInDomain(int $domainId, int $shippingId, array $data): bool
    {
        return $this->getDb()->table($this->table)
            ->where('shipping_id', '=', $shippingId)
            ->where('domain_id', '=', $domainId)
            ->update($data) !== false;
    }

    /** 현재 쇼핑몰에 속한 배송 템플릿만 삭제한다. */
    public function deleteInDomain(int $domainId, int $shippingId): bool
    {
        return $this->getDb()->table($this->table)
            ->where('shipping_id', '=', $shippingId)
            ->where('domain_id', '=', $domainId)
            ->delete() > 0;
    }

    /**
     * 여러 배송 템플릿을 사용 중인 상품 수를 배치 집계.
     *
     * @param int[] $ids shipping_id 목록
     * @return array<int, int> [shipping_id => 사용 상품 수]
     */
    public function getUsageCountsByTemplateIds(int $domainId, array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = $this->db->select(
            "SELECT shipping_template_id, COUNT(*) AS cnt
             FROM shop_products
             WHERE domain_id = ? AND shipping_template_id IN ({$placeholders})
             GROUP BY shipping_template_id",
            array_merge([$domainId], array_values($ids))
        );

        $counts = [];
        foreach ($rows as $r) {
            $counts[(int) $r['shipping_template_id']] = (int) $r['cnt'];
        }

        return $counts;
    }

    /**
     * 도메인별 활성 배송 템플릿 목록
     *
     * @param int $domainId 도메인 ID
     * @return ShippingTemplate[]
     */
    public function getActive(int $domainId): array
    {
        $rows = $this->getDb()->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('is_active', '=', 1)
            ->orderBy('shipping_id', 'ASC')
            ->get();

        return $this->toEntities($rows);
    }

    /**
     * 활성 택배사 목록 조회
     *
     * @return array 택배사 목록 (raw arrays)
     */
    public function getDeliveryCompanies(): array
    {
        return $this->getDb()->table($this->deliveryCompaniesTable)
            ->where('is_active', '=', 1)
            ->orderBy('company_id', 'ASC')
            ->get();
    }
}
