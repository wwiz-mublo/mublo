<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\Repository;

use Mublo\Infrastructure\Database\Database;
use Mublo\Repository\BaseRepository;

/**
 * PaymentTransactionRepository
 *
 * shop_payment_transactions 테이블 CRUD
 *
 * 책임:
 * - 결제·환불 트랜잭션 기록
 * - 주문별 트랜잭션 이력 조회
 * - 환불 누적 금액 집계
 */
class PaymentTransactionRepository extends BaseRepository
{
    protected string $table = 'shop_payment_transactions';
    protected string $primaryKey = 'transaction_id';

    public function __construct(Database $db)
    {
        parent::__construct($db);
    }

    /**
     * 트랜잭션 레코드 생성
     */
    public function createTransaction(array $data): int
    {
        if (!isset($data['created_at'])) {
            $data['created_at'] = date('Y-m-d H:i:s');
        }
        if (!isset($data['updated_at'])) {
            $data['updated_at'] = date('Y-m-d H:i:s');
        }

        return $this->getDb()->table($this->table)->insert($data);
    }

    /**
     * 주문별 트랜잭션 이력 (최신순)
     */
    public function getByOrderNo(string $orderNo): array
    {
        return $this->getDb()->table($this->table)
            ->where('order_no', '=', $orderNo)
            ->orderBy('created_at', 'DESC')
            ->get();
    }

    /** 현재 도메인의 주문에 속한 트랜잭션만 조회한다. */
    public function getByOrderNoInDomain(int $domainId, string $orderNo): array
    {
        return $this->getDb()->select(
            "SELECT pt.*
             FROM {$this->table} pt
             INNER JOIN shop_orders o ON o.order_no = pt.order_no
             WHERE pt.order_no = ? AND o.domain_id = ?
             ORDER BY pt.created_at DESC",
            [$orderNo, $domainId]
        );
    }

    /**
     * 주문의 성공 결제 원거래 조회.
     *
     * 결제 완료 후처리 재시도에서 PAYMENT 행을 중복 생성하지 않기 위한 원장 가드다.
     */
    public function findSuccessfulPayment(string $orderNo): ?array
    {
        return $this->getDb()->selectOne(
            "SELECT * FROM {$this->table} "
            . "WHERE order_no = ? AND transaction_type = 'PAYMENT' "
            . "AND transaction_status = 'SUCCESS' ORDER BY transaction_id ASC LIMIT 1",
            [$orderNo]
        );
    }

    /**
     * 주문의 환불 누적 금액
     *
     * transaction_type IN (CANCEL, PARTIAL_CANCEL) AND transaction_status = 'SUCCESS'
     */
    public function getTotalRefunded(string $orderNo): int
    {
        $rows = $this->getDb()->select(
            "SELECT COALESCE(SUM(cancel_amount), 0) AS total "
            . "FROM {$this->table} "
            . "WHERE order_no = ? "
            . "AND transaction_type IN ('CANCEL', 'PARTIAL_CANCEL') "
            . "AND transaction_status = 'SUCCESS'",
            [$orderNo]
        );

        return (int) ($rows[0]['total'] ?? 0);
    }

    /**
     * 트랜잭션 상태 업데이트
     */
    public function updateStatus(int $transactionId, string $status, array $extra = []): bool
    {
        $data = array_merge($extra, [
            'transaction_status' => $status,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $affected = $this->getDb()->table($this->table)
            ->where('transaction_id', '=', $transactionId)
            ->update($data);

        return $affected > 0;
    }
}
