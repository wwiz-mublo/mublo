<?php

namespace Mublo\Packages\Shop\Repository;

use Mublo\Infrastructure\Database\Database;

/**
 * 결제 완료 후처리 원장.
 *
 * 주문번호를 멱등 키로 사용하고, 짧은 lease를 가진 PROCESSING 상태로 동시 실행을 막는다.
 */
class PaymentCompletionRepository
{
    private const PROCESSING_LEASE_SECONDS = 300;

    public function __construct(private readonly Database $db)
    {
    }

    /** @param array<string, mixed> $verifyData */
    public function stage(
        string $orderNo,
        int $domainId,
        string $pgKey,
        string $pgTid,
        array $verifyData
    ): array {
        $eventId = bin2hex(random_bytes(16));
        $payload = json_encode(
            $verifyData,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );

        $this->db->execute(
            'INSERT INTO shop_payment_completions '
            . '(order_no, domain_id, event_id, pg_key, pg_tid, verify_data, status) '
            . "VALUES (?, ?, ?, ?, ?, ?, 'PENDING') "
            . 'ON DUPLICATE KEY UPDATE '
            . 'pg_key = VALUES(pg_key), pg_tid = VALUES(pg_tid), verify_data = VALUES(verify_data)',
            [$orderNo, $domainId, $eventId, $pgKey, $pgTid, $payload]
        );

        return $this->find($orderNo)
            ?? throw new \RuntimeException('결제 완료 후처리 원장을 생성하지 못했습니다.');
    }

    public function find(string $orderNo): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM shop_payment_completions WHERE order_no = ?',
            [$orderNo]
        );
    }

    /**
     * 처리권을 획득한다. 이미 완료됐거나 다른 요청의 lease가 유효하면 null을 반환한다.
     */
    public function claim(string $orderNo): ?array
    {
        return $this->db->transaction(function () use ($orderNo): ?array {
            $row = $this->db->selectOne(
                'SELECT * FROM shop_payment_completions WHERE order_no = ? FOR UPDATE',
                [$orderNo]
            );
            if ($row === null || ($row['status'] ?? '') === 'COMPLETED') {
                return null;
            }

            if (($row['status'] ?? '') === 'PROCESSING' && !$this->leaseExpired($row)) {
                return null;
            }

            $leaseToken = bin2hex(random_bytes(16));
            $this->db->execute(
                "UPDATE shop_payment_completions SET status = 'PROCESSING', "
                . 'attempts = attempts + 1, lease_token = ?, '
                . 'processing_started_at = CURRENT_TIMESTAMP, last_error = NULL '
                . 'WHERE order_no = ?',
                [$leaseToken, $orderNo]
            );
            $row['status'] = 'PROCESSING';
            $row['attempts'] = (int) ($row['attempts'] ?? 0) + 1;
            $row['lease_token'] = $leaseToken;

            return $row;
        });
    }

    public function markCompleted(string $orderNo, string $leaseToken): void
    {
        $this->db->execute(
            "UPDATE shop_payment_completions SET status = 'COMPLETED', "
            . 'completed_at = CURRENT_TIMESTAMP, lease_token = NULL, '
            . 'processing_started_at = NULL, last_error = NULL '
            . "WHERE order_no = ? AND status = 'PROCESSING' AND lease_token = ?",
            [$orderNo, $leaseToken]
        );
    }

    public function markFailed(string $orderNo, string $leaseToken, string $error): void
    {
        $this->db->execute(
            "UPDATE shop_payment_completions SET status = 'FAILED', "
            . 'lease_token = NULL, processing_started_at = NULL, last_error = ? '
            . "WHERE order_no = ? AND status = 'PROCESSING' AND lease_token = ?",
            [mb_substr($error, 0, 2000), $orderNo, $leaseToken]
        );
    }

    private function leaseExpired(array $row): bool
    {
        $startedAt = strtotime((string) ($row['processing_started_at'] ?? ''));
        if ($startedAt === false) {
            return true;
        }

        return $startedAt <= time() - self::PROCESSING_LEASE_SECONDS;
    }
}
