<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\Repository;

use Mublo\Infrastructure\Database\Database;

/**
 * ShipmentRepository
 *
 * shop_shipments 테이블 접근
 *
 * shop_shipments 는 주문의 실제 배송(택배) 추적 정보를 저장합니다.
 * - 한 주문에 묶음 배송(order_detail_id = NULL) 또는 상품별 개별 배송이 가능합니다.
 * - invoice_no + company_id 조합으로 택배 추적 URL을 생성합니다.
 * - 배송 상태는 shop_shipments.shipment_status 로 관리하며
 *   주문 상태(shop_orders.order_status)와는 별개로 운영됩니다.
 */
class ShipmentRepository
{
    private Database $db;
    private string $table = 'shop_shipments';

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * 활성 택배사 목록 (송장 입력 드롭다운용)
     *
     * @return array<int,array{company_id:int,company_name:string,delivery_method:string,tracking_url:?string}>
     */
    public function getActiveCompanies(): array
    {
        return $this->db->select(
            "SELECT company_id, company_name, delivery_method, tracking_url
             FROM shop_delivery_companies
             WHERE is_active = 1
             -- 추적이 되는 택배사를 먼저, '기타'처럼 추적 URL 없는 항목을 뒤로.
             -- 이름 순으로만 두면 '기타'가 목록 중간에 끼어 고르기 어렵다.
             ORDER BY delivery_method ASC, (tracking_url IS NULL) ASC, company_name ASC"
        );
    }

    /**
     * 주어진 주문번호들 중 운송장이 등록된 주문번호 목록 (목록 화면 일괄 표시용 — N+1 회피)
     *
     * @param string[] $orderNos
     * @return string[] 운송장이 1건 이상 있는 order_no
     */
    public function getOrderNosWithShipments(array $orderNos): array
    {
        $orderNos = array_values(array_filter(array_map('strval', $orderNos), static fn($v) => $v !== ''));
        if ($orderNos === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($orderNos), '?'));
        $rows = $this->db->select(
            "SELECT DISTINCT order_no FROM {$this->table} WHERE claim_id IS NULL AND order_no IN ({$placeholders})",
            $orderNos
        );
        return array_column($rows, 'order_no');
    }

    public function find(int $shipmentId): ?array
    {
        return $this->db->selectOne(
            "SELECT s.*, dc.company_name, dc.tracking_url AS tracking_url_template
             FROM {$this->table} s
             LEFT JOIN shop_delivery_companies dc ON dc.company_id = s.company_id
             WHERE s.shipment_id = ?",
            [$shipmentId]
        ) ?: null;
    }

    /**
     * 주문의 운송장.
     *
     * 기본은 클레임 운송장(회수·재출고·반송)을 뺀 실제 출고 운송장만 돌려준다 —
     * 클레임 배송은 반품·교환 관리가 소유하기 때문이다.
     * 다만 고객 주문 상세는 교환 상품이 언제 오는지 볼 곳이 여기뿐이라 함께 받는다.
     */
    public function getByOrderNo(string $orderNo, bool $includeClaims = false): array
    {
        $claimSql = $includeClaims ? '' : ' AND s.claim_id IS NULL';
        return $this->db->select(
            "SELECT s.*, dc.company_name, dc.tracking_url AS tracking_url_template
             FROM {$this->table} s
             LEFT JOIN shop_delivery_companies dc ON dc.company_id = s.company_id
             WHERE s.order_no = ?{$claimSql}
             ORDER BY s.shipment_id ASC",
            [$orderNo]
        );
    }

    public function getByClaimId(int $claimId): array
    {
        return $this->db->select(
            "SELECT s.*, dc.company_name, dc.tracking_url AS tracking_url_template
             FROM {$this->table} s
             LEFT JOIN shop_delivery_companies dc ON dc.company_id = s.company_id
             WHERE s.claim_id = ? ORDER BY s.shipment_id ASC",
            [$claimId]
        );
    }

    public function getByInvoiceNo(string $invoiceNo): ?array
    {
        return $this->db->selectOne(
            "SELECT s.*, dc.company_name, dc.tracking_url AS tracking_url_template
             FROM {$this->table} s
             LEFT JOIN shop_delivery_companies dc ON dc.company_id = s.company_id
             WHERE s.invoice_no = ?",
            [$invoiceNo]
        ) ?: null;
    }

    public function create(array $data): int
    {
        $columns      = array_keys($data);
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $columnList   = implode(', ', $columns);

        return $this->db->insert(
            "INSERT INTO {$this->table} ({$columnList}) VALUES ({$placeholders})",
            array_values($data)
        );
    }

    public function update(int $shipmentId, array $data): int
    {
        $sets   = [];
        $values = [];
        foreach ($data as $col => $val) {
            $sets[]   = "`{$col}` = ?";
            $values[] = $val;
        }
        $values[] = $shipmentId;

        return $this->db->execute(
            "UPDATE {$this->table} SET " . implode(', ', $sets) . " WHERE shipment_id = ?",
            $values
        );
    }

    public function updateStatus(int $shipmentId, string $status): int
    {
        $extra = [];
        $params = [$status];

        if ($status === 'DELIVERED') {
            $extra[]  = '`delivered_at` = ?';
            $params[] = date('Y-m-d H:i:s');
        } elseif ($status === 'PICKED_UP') {
            $extra[]  = '`shipped_at` = ?';
            $params[] = date('Y-m-d H:i:s');
        }

        $setClauses = array_merge(['`shipment_status` = ?'], $extra);
        $params[]   = $shipmentId;

        return $this->db->execute(
            "UPDATE {$this->table} SET " . implode(', ', $setClauses) . " WHERE shipment_id = ?",
            $params
        );
    }

    public function delete(int $shipmentId): int
    {
        return $this->db->execute(
            "DELETE FROM {$this->table} WHERE shipment_id = ?",
            [$shipmentId]
        );
    }
}
