<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\Repository;

use Mublo\Infrastructure\Database\Database;
use Mublo\Infrastructure\Database\DatabaseException;

class ClaimRepository
{
    public function __construct(private Database $db)
    {
    }

    public function transaction(callable $callback): mixed
    {
        try {
            return $this->db->transaction($callback);
        } catch (DatabaseException $exception) {
            // Database::transaction은 원 예외를 감싼다. 서비스 검증 오류는 메시지를
            // 보존하고, 실제 DB 오류만 DatabaseException으로 유지한다.
            if ($exception->getPrevious() instanceof \DomainException) {
                throw $exception->getPrevious();
            }
            throw $exception;
        }
    }

    public function createClaim(array $data): int
    {
        $data['created_at'] ??= date('Y-m-d H:i:s');
        $data['updated_at'] ??= date('Y-m-d H:i:s');
        return $this->db->table('shop_returns')->insert($data);
    }

    public function createExchangeItem(array $data): int
    {
        $data['created_at'] ??= date('Y-m-d H:i:s');
        $data['updated_at'] ??= date('Y-m-d H:i:s');
        return $this->db->table('shop_exchange_items')->insert($data);
    }

    public function lockOrderDetailInDomain(int $domainId, int $detailId): ?array
    {
        return $this->db->selectOne(
            'SELECT d.* FROM shop_order_details d INNER JOIN shop_orders o ON o.order_no = d.order_no '
            . 'WHERE d.order_detail_id = ? AND o.domain_id = ? FOR UPDATE',
            [$detailId, $domainId]
        ) ?: null;
    }

    public function findInDomain(int $domainId, int $claimId): ?array
    {
        return $this->selectClaim($domainId, $claimId, false);
    }

    public function findForUpdate(int $domainId, int $claimId): ?array
    {
        return $this->selectClaim($domainId, $claimId, true);
    }

    private function selectClaim(int $domainId, int $claimId, bool $forUpdate): ?array
    {
        $sql = "SELECT r.*, d.goods_id AS source_goods_id, d.goods_name AS source_goods_name,
                       d.goods_image AS source_goods_image, d.option_mode AS source_option_mode,
                       d.option_id AS source_option_id, d.option_code AS source_option_code,
                       d.option_name AS source_option_name, d.option_price AS source_option_price,
                       d.quantity AS ordered_quantity, d.status AS current_item_status,
                       d.stock_deducted AS source_stock_deducted,
                       e.exchange_item_id, e.goods_id AS target_goods_id,
                       e.option_mode AS target_option_mode, e.option_id AS target_option_id,
                       e.option_code AS target_option_code, e.option_name AS target_option_name,
                       e.option_price AS target_option_price, e.quantity AS exchange_quantity,
                       e.stock_reservation_status, e.stock_reserved_at, e.stock_released_at,
                       o.recipient_name, o.recipient_phone, o.shipping_zip,
                       o.shipping_address1, o.shipping_address2, o.shipping_breakdown
                FROM shop_returns r
                INNER JOIN shop_order_details d ON d.order_detail_id = r.order_detail_id
                INNER JOIN shop_orders o ON o.order_no = r.order_no AND o.domain_id = r.domain_id
                LEFT JOIN shop_exchange_items e ON e.return_id = r.return_id
                WHERE r.domain_id = ? AND r.return_id = ?";
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        return $this->db->selectOne($sql, [$domainId, $claimId]) ?: null;
    }

    public function updateClaim(int $domainId, int $claimId, array $data): bool
    {
        if ($data === []) {
            return false;
        }
        $data['updated_at'] = date('Y-m-d H:i:s');
        return $this->db->table('shop_returns')
            ->where('domain_id', '=', $domainId)
            ->where('return_id', '=', $claimId)
            ->update($data) > 0;
    }

    public function updateExchangeItem(int $claimId, array $data): bool
    {
        if ($data === []) {
            return false;
        }
        $data['updated_at'] = date('Y-m-d H:i:s');
        return $this->db->table('shop_exchange_items')
            ->where('return_id', '=', $claimId)
            ->update($data) > 0;
    }

    public function compareAndSetStatus(int $domainId, int $claimId, string $from, string $to, array $extra = []): bool
    {
        $extra['return_status'] = $to;
        $extra['updated_at'] = date('Y-m-d H:i:s');
        return $this->db->table('shop_returns')
            ->where('domain_id', '=', $domainId)
            ->where('return_id', '=', $claimId)
            ->where('return_status', '=', $from)
            ->update($extra) > 0;
    }

    public function addLog(int $claimId, int $domainId, string $from, string $to, string $changedBy, ?int $changedById, string $reason = '', array $metadata = []): int
    {
        return $this->db->table('shop_claim_logs')->insert([
            'return_id' => $claimId,
            'domain_id' => $domainId,
            'prev_status' => $from,
            'new_status' => $to,
            'changed_by' => $changedBy,
            'changed_by_id' => $changedById,
            'reason' => $reason !== '' ? $reason : null,
            'metadata' => $metadata !== [] ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function getLogs(int $domainId, int $claimId): array
    {
        return $this->db->select(
            'SELECT * FROM shop_claim_logs WHERE domain_id = ? AND return_id = ? ORDER BY claim_log_id ASC',
            [$domainId, $claimId]
        );
    }

    /** @param ?string $returnType null 이면 교환·반품을 모두 돌려준다 */
    public function getByOrderNo(int $domainId, string $orderNo, ?string $returnType = null): array
    {
        $typeSql = $returnType !== null ? ' AND r.return_type = ?' : '';
        $params = $returnType !== null ? [$domainId, $orderNo, $returnType] : [$domainId, $orderNo];
        return $this->db->select(
            "SELECT r.*, e.option_name AS target_option_name, e.quantity AS exchange_quantity,
                    e.stock_reservation_status
             FROM shop_returns r
             LEFT JOIN shop_exchange_items e ON e.return_id = r.return_id
             WHERE r.domain_id = ? AND r.order_no = ?{$typeSql}
             ORDER BY r.return_id DESC",
            $params
        );
    }

    /** @param ?string $returnType null 이면 교환·반품을 모두 돌려준다 */
    public function getActiveByDetailId(int $domainId, int $detailId, ?string $returnType = null): array
    {
        $typeSql = $returnType !== null ? ' AND r.return_type = ?' : '';
        $params = $returnType !== null ? [$domainId, $detailId, $returnType] : [$domainId, $detailId];
        return $this->db->select(
            "SELECT r.*, e.quantity AS exchange_quantity, e.option_name AS target_option_name
             FROM shop_returns r
             LEFT JOIN shop_exchange_items e ON e.return_id = r.return_id
             WHERE r.domain_id = ? AND r.order_detail_id = ?{$typeSql}
               AND r.return_status NOT IN ('COMPLETED', 'REFUSED', 'CANCELLED', 'CLOSED')
             ORDER BY r.return_id DESC",
            $params
        );
    }

    public function getActiveQuantityForUpdate(int $domainId, int $detailId): int
    {
        $row = $this->db->selectOne(
            "SELECT COALESCE(SUM(COALESCE(e.quantity, r.quantity)), 0) AS claimed_quantity
             FROM shop_returns r
             LEFT JOIN shop_exchange_items e ON e.return_id = r.return_id
             WHERE r.domain_id = ? AND r.order_detail_id = ?
               AND r.return_status NOT IN ('REFUSED', 'CANCELLED', 'CLOSED')
             ",
            [$domainId, $detailId]
        );
        return (int) ($row['claimed_quantity'] ?? 0);
    }

    /** 같은 상품에 다른 유형의 클레임이 살아 있는지 (교환과 반품을 동시에 진행할 수는 없다). */
    public function hasBlockingClaimOfOtherType(int $domainId, int $detailId, string $returnType): bool
    {
        $row = $this->db->selectOne(
            "SELECT return_id
             FROM shop_returns
             WHERE domain_id = ? AND order_detail_id = ? AND return_type <> ?
               AND return_status NOT IN ('REFUSED', 'CANCELLED', 'CLOSED')
             LIMIT 1",
            [$domainId, $detailId, $returnType]
        );
        return $row !== null && $row !== false;
    }

    public function hasCompletedClaim(int $domainId, int $detailId, string $returnType): bool
    {
        $row = $this->db->selectOne(
            "SELECT return_id FROM shop_returns
             WHERE domain_id = ? AND order_detail_id = ? AND return_type = ?
               AND return_status = 'COMPLETED'
             LIMIT 1",
            [$domainId, $detailId, $returnType]
        );
        return $row !== null && $row !== false;
    }

    public function list(int $domainId, array $filters, int $page = 1, int $perPage = 20): array
    {
        $where = ['r.domain_id = ?'];
        $params = [$domainId];
        if (!empty($filters['return_type'])) {
            $where[] = 'r.return_type = ?';
            $params[] = (string) $filters['return_type'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'r.return_status = ?';
            $params[] = (string) $filters['status'];
        }
        if (!empty($filters['keyword'])) {
            $where[] = '(r.order_no LIKE ? OR d.goods_name LIKE ?)';
            $keyword = '%' . trim((string) $filters['keyword']) . '%';
            $params[] = $keyword;
            $params[] = $keyword;
        }
        $whereSql = implode(' AND ', $where);
        $totalRow = $this->db->selectOne(
            "SELECT COUNT(*) AS cnt FROM shop_returns r INNER JOIN shop_order_details d ON d.order_detail_id = r.order_detail_id WHERE {$whereSql}",
            $params
        );
        $total = (int) ($totalRow['cnt'] ?? 0);
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;
        $items = $this->db->select(
            "SELECT r.*, d.goods_name AS source_goods_name, d.option_name AS source_option_name,
                    e.option_name AS target_option_name, e.quantity AS exchange_quantity,
                    e.stock_reservation_status
             FROM shop_returns r
             INNER JOIN shop_order_details d ON d.order_detail_id = r.order_detail_id
             LEFT JOIN shop_exchange_items e ON e.return_id = r.return_id
             WHERE {$whereSql}
             ORDER BY r.return_id DESC LIMIT ? OFFSET ?",
            array_merge($params, [$perPage, $offset])
        );
        return [
            'items' => $items,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
        ];
    }
}
