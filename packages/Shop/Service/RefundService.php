<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\Service;

use Mublo\Core\Result\Result;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Core\Event\EventInterface;
use Mublo\Packages\Shop\Repository\PaymentTransactionRepository;
use Mublo\Packages\Shop\Repository\OrderRepository;
use Mublo\Contract\Balance\BalanceGatewayInterface;

/**
 * RefundService
 *
 * 환불 비즈니스 로직
 *
 * 책임:
 * - 환불 금액 검증 (부분/전액)
 * - PG 결제 취소 연동 (PaymentService)
 * - shop_payment_transactions 기록
 * - shop_order_logs 환불 로그
 *
 * 금지:
 * - Request/Response 처리
 * - 주문 상태 전이 (OrderService 담당)
 */
class RefundService
{
    private PaymentService $paymentService;
    private PaymentTransactionRepository $txnRepository;
    private OrderRepository $orderRepository;
    private OrderStateResolver $stateResolver;
    private ?EventDispatcher $eventDispatcher;
    private ?BalanceGatewayInterface $balanceManager;

    public function __construct(
        PaymentService $paymentService,
        PaymentTransactionRepository $txnRepository,
        OrderRepository $orderRepository,
        OrderStateResolver $stateResolver,
        ?EventDispatcher $eventDispatcher = null,
        ?BalanceGatewayInterface $balanceManager = null
    ) {
        $this->paymentService = $paymentService;
        $this->txnRepository = $txnRepository;
        $this->orderRepository = $orderRepository;
        $this->stateResolver = $stateResolver;
        $this->eventDispatcher = $eventDispatcher;
        $this->balanceManager = $balanceManager;
    }

    /**
     * 주문 환불 처리 (관리자)
     *
     * @param string $orderNo 주문번호
     * @param int $amount 환불 금액
     * @param string $refundMethod 환불 방법: PG_CANCEL | BANK | POINT
     * @param string $reason 환불 사유
     * @param int $domainId 도메인 ID
     * @param int $staffId 처리 담당자 ID
     * @param array $bankInfo 무통장 환불 시: [bank, account, holder]
     * @return Result
     */
    public function processRefund(
        string $orderNo,
        int $amount,
        string $refundMethod,
        string $reason,
        int $domainId,
        int $staffId = 0,
        array $bankInfo = []
    ): Result {
        // 주문 확인
        $order = $this->orderRepository->find($orderNo);
        if (!$order) {
            return Result::failure('주문을 찾을 수 없습니다.');
        }

        // 도메인 경계 검증
        if ((int) ($order->getDomainId() ?? 0) !== $domainId) {
            return Result::failure('주문을 찾을 수 없습니다.');
        }

        // 금액 검증
        if ($amount <= 0) {
            return Result::failure('환불 금액은 0보다 커야 합니다.');
        }

        $memberId = $order->getMemberId();

        // POINT 환불은 회원 주문에만 가능하다(비회원은 포인트 지갑이 없음).
        // 지급 수단(BalanceGatewayInterface)이 없으면 조용히 미지급되므로 사전에 막는다.
        if ($refundMethod === 'POINT') {
            if ($memberId <= 0) {
                return Result::failure('포인트 환불은 회원 주문에만 가능합니다.');
            }
            if ($this->balanceManager === null) {
                return Result::failure('포인트 지급 모듈이 없어 포인트 환불을 진행할 수 없습니다.');
            }
        }

        $totalPaid = $order->getFinalAmount();

        // 초과환불 방지(원자화): 주문 행을 잠그고(FOR UPDATE) 환불 가능액을 잠금 상태에서
        // 재조회·검증한 뒤 기록까지 한 트랜잭션으로 처리한다. 이 가드가 없으면 동시 환불
        // 두 건이 같은 refundable을 읽어 둘 다 통과 → 결제액 초과 환불이 발생한다(특히
        // PG의 이중취소 거부 백스톱이 없는 BANK/POINT 수동환불).
        // PG_CANCEL 외부호출은 관리자 환불의 낮은 동시성상 잠금 구간 안에서 수행한다.
        $db = $this->txnRepository->getDb();

        $result = $db->transaction(function () use (
            $db, $order, $orderNo, $amount, $refundMethod, $reason, $domainId, $staffId, $bankInfo, $totalPaid
        ) {
            // 주문 행 잠금 — 같은 주문의 동시 환불을 직렬화
            $db->select('SELECT order_no FROM shop_orders WHERE order_no = ? FOR UPDATE', [$orderNo]);

            // 잠금 후 권위 재조회
            $totalRefunded = $this->txnRepository->getTotalRefunded($orderNo);
            $refundable = $totalPaid - $totalRefunded;

            if ($amount > $refundable) {
                return Result::failure(
                    '환불 가능 금액을 초과했습니다. (환불 가능: '
                    . number_format($refundable) . '원)'
                );
            }

            // 트랜잭션 유형 결정
            $txnType = ($amount >= $refundable) ? 'CANCEL' : 'PARTIAL_CANCEL';

            // PG 취소 (원결제 취소)
            $pgResult = [];
            if ($refundMethod === 'PG_CANCEL') {
                $pgKey = $order->getPaymentGateway() ?? '';
                if (empty($pgKey)) {
                    return Result::failure('결제 수단 정보가 없어 PG 취소를 진행할 수 없습니다.');
                }

                // 결제 트랜잭션에서 pg_tid 조회
                $pgTid = $this->findPgTid($orderNo);
                if (empty($pgTid)) {
                    // pg_tid가 없으면 order_no를 대체 사용 (TestPay 등)
                    $pgTid = $orderNo;
                }

                // 잠금 상태에서 재조회한 환불 가능액을 함께 넘긴다 — PG 가 전체취소인지
                // 부분취소인지 가르는 근거다(계약: remainingAmount).
                $cancelResult = $this->paymentService->cancelPayment(
                    $pgKey, $pgTid, $amount, $reason, $refundable
                );
                if ($cancelResult->isFailure()) {
                    return Result::failure('PG 결제 취소 실패: ' . $cancelResult->getMessage());
                }

                $pgResult = $cancelResult->getData();
            }

            // 환불 방법 라벨
            $methodLabel = match ($refundMethod) {
                'PG_CANCEL' => '원결제 취소',
                'BANK' => '무통장 환불',
                'POINT' => '포인트 환불',
                default => $refundMethod,
            };

            // shop_payment_transactions 기록
            $txnData = [
                'order_no' => $orderNo,
                'domain_id' => $domainId,
                'pg_key' => ($refundMethod === 'PG_CANCEL') ? ($order->getPaymentGateway() ?? '') : 'manual',
                'pg_tid' => $pgResult['transaction_id'] ?? null,
                'pg_approval_no' => $pgResult['approval_no'] ?? null,
                'pg_response' => !empty($pgResult) ? json_encode($pgResult) : null,
                'payment_method' => $order->getPaymentMethod()->value ?? '',
                'amount' => 0,
                'transaction_type' => $txnType,
                'transaction_status' => 'SUCCESS',
                'cancel_amount' => $amount,
                'cancel_reason' => $reason,
                'cancelled_at' => date('Y-m-d H:i:s'),
                'admin_memo' => "{$methodLabel} ({$amount}원)",
            ];

            // 무통장 환불 시 은행 정보 메모에 추가
            if ($refundMethod === 'BANK' && !empty($bankInfo['bank'])) {
                $txnData['admin_memo'] .= " / {$bankInfo['bank']} {$bankInfo['account']} {$bankInfo['holder']}";
            }

            $txnId = $this->txnRepository->createTransaction($txnData);

            // shop_order_logs 기록
            $this->orderRepository->insertOrderLog([
                'order_no' => $orderNo,
                'prev_status' => '',
                'prev_status_label' => '',
                'new_status' => '',
                'new_status_label' => '',
                'change_type' => 'PAYMENT',
                'changed_by' => 'STAFF',
                'staff_id' => $staffId,
                'reason' => '환불 ' . number_format($amount) . "원 ({$methodLabel}): {$reason}",
            ]);

            $newTotalRefunded = $totalRefunded + $amount;

            return Result::success('환불이 처리되었습니다.', [
                'transaction_id' => $txnId,
                'refund_amount' => $amount,
                'refund_method' => $refundMethod,
                'total_refunded' => $newTotalRefunded,
                'remaining' => $totalPaid - $newTotalRefunded,
            ]);
        });

        // POINT 환불: 환불액만큼 회원 포인트를 실제로 지급한다.
        // BalanceGatewayInterface::adjust는 자체 트랜잭션을 열므로(중첩 불가) 환불 기록 커밋 이후에 수행하며,
        // 멱등키(환불 트랜잭션 id 기준)로 재시도/중복 지급을 방지한다.
        if ($result->isSuccess() && $refundMethod === 'POINT') {
            $txnId = (int) $result->get('transaction_id', 0);
            $credit = $this->balanceManager->adjust([
                'member_id'       => $memberId,
                'domain_id'       => $domainId,
                'amount'          => $amount, // +지급
                'source_type'     => 'package',
                'source_name'     => 'Shop',
                'action'          => 'refund_point',
                'message'         => "주문 {$orderNo} 환불 포인트 지급",
                'reference_type'  => 'order',
                'reference_id'    => $orderNo,
                'admin_id'        => $staffId ?: null,
                'memo'            => $reason,
                'idempotency_key' => "shop_refund_point_{$txnId}",
            ]);

            if ($credit->isFailure()) {
                // 환불은 기록됐으나 포인트 지급 실패 — 조용히 넘기지 않고 운영자에게 노출한다.
                // 복구는 retryPointCredit() (주문 상세의 "포인트 재지급" — 멱등이라 언제 눌러도 안전).
                error_log("[CRITICAL][SHOP][REFUND] 포인트 환불 지급 실패: order={$orderNo} txn={$txnId} error={$credit->getMessage()}");
                return Result::success(
                    '환불이 기록되었으나 포인트 지급에 실패했습니다. 주문 상세의 [포인트 재지급]으로 재시도할 수 있습니다.',
                    array_merge($result->getData(), [
                        'point_credit_failed' => true,
                        'point_credit_error'  => $credit->getMessage(),
                    ])
                );
            }
        }

        return $result;
    }

    /**
     * POINT 환불의 포인트 지급 재시도.
     *
     * processRefund 는 환불 기록을 커밋한 뒤 포인트를 지급하므로, 지급이 실패하면
     * 환불 기록만 남는다(CRITICAL 로그). 이 메서드는 **같은 멱등키**
     * (shop_refund_point_{txnId})로 지급을 다시 시도한다 — 이미 지급된 건이면
     * 원장 UNIQUE 가 멱등 응답을 돌려주므로 언제, 몇 번을 눌러도 이중 지급이 없다.
     */
    public function retryPointCredit(int $domainId, string $orderNo, int $txnId, int $staffId = 0): Result
    {
        if ($this->balanceManager === null) {
            return Result::failure('포인트 지급 모듈이 없습니다.');
        }

        $order = $this->orderRepository->find($orderNo);
        if (!$order || (int) ($order->getDomainId() ?? 0) !== $domainId) {
            return Result::failure('주문을 찾을 수 없습니다.');
        }

        $memberId = $order->getMemberId();
        if ($memberId <= 0) {
            return Result::failure('포인트 환불은 회원 주문에만 가능합니다.');
        }

        // 대상 환불 트랜잭션 검증 (도메인 스코프 + POINT 환불 판별)
        $txn = null;
        foreach ($this->txnRepository->getByOrderNoInDomain($domainId, $orderNo) as $t) {
            if ((int) ($t['transaction_id'] ?? 0) === $txnId) {
                $txn = $t;
                break;
            }
        }
        if ($txn === null) {
            return Result::failure('환불 거래를 찾을 수 없습니다.');
        }

        $isPointRefund = ($txn['pg_key'] ?? '') === 'manual'
            && str_starts_with((string) ($txn['admin_memo'] ?? ''), '포인트 환불')
            && ($txn['transaction_status'] ?? '') === 'SUCCESS';
        if (!$isPointRefund) {
            return Result::failure('포인트 환불 거래가 아닙니다.');
        }

        $amount = (int) ($txn['cancel_amount'] ?? 0);
        if ($amount <= 0) {
            return Result::failure('환불 금액이 없습니다.');
        }

        $credit = $this->balanceManager->adjust([
            'member_id'       => $memberId,
            'domain_id'       => $domainId,
            'amount'          => $amount,
            'source_type'     => 'package',
            'source_name'     => 'Shop',
            'action'          => 'refund_point',
            'message'         => "주문 {$orderNo} 환불 포인트 지급",
            'reference_type'  => 'order',
            'reference_id'    => $orderNo,
            'admin_id'        => $staffId ?: null,
            'memo'            => (string) ($txn['cancel_reason'] ?? ''),
            'idempotency_key' => "shop_refund_point_{$txnId}",
        ]);

        if ($credit->isFailure()) {
            return Result::failure('포인트 지급 재시도 실패: ' . $credit->getMessage());
        }

        return Result::success(
            !empty($credit->get('idempotent')) ? '이미 지급된 환불 포인트입니다.' : '환불 포인트가 지급되었습니다.',
            $credit->getData()
        );
    }

    /**
     * 외부 webhook 기반 환불 기록 (PG가 직접 통보한 취소·환불 알림 처리)
     *
     * PG 측에서 이미 취소 처리가 끝난 상태이므로 PG cancel API는 재호출하지 않고
     * shop_payment_transactions와 주문 로그에만 반영한다. 멱등성 보장:
     * 같은 (pg_tid + transaction_type) 조합이 이미 SUCCESS로 남아 있으면 skip.
     *
     * @param string $orderNo   주문번호
     * @param int    $amount    이번 webhook이 알린 취소 금액 (>0). full cancel은 잔여 결제액과 같아야 함
     * @param string $pgKey     PG 키 (예: 'payapp')
     * @param string $pgTid     PG 트랜잭션 ID (멱등 키로도 사용)
     * @param string $reason    사유 (PG 콜백 메모 등)
     * @param int    $domainId  도메인 ID
     * @param bool   $fullCancel 전체 취소 여부 — true면 잔여 결제액과 일치하지 않아도 CANCEL로 기록
     */
    public function recordExternalRefund(
        string $orderNo,
        int $amount,
        string $pgKey,
        string $pgTid,
        string $reason,
        int $domainId,
        bool $fullCancel = false
    ): Result {
        $order = $this->orderRepository->find($orderNo);
        if (!$order) {
            return Result::failure('주문을 찾을 수 없습니다.');
        }

        if ((int) ($order->getDomainId() ?? 0) !== $domainId) {
            return Result::failure('주문 도메인이 일치하지 않습니다.');
        }

        if ($amount <= 0) {
            return Result::failure('환불 금액이 올바르지 않습니다.');
        }

        $totalPaid = $order->getFinalAmount();

        // 초과/중복 환불 방지(원자화): 주문 행을 FOR UPDATE로 잠그고, 잠금 하에서
        // 환불가능액 재조회 + 멱등 스캔 + 기록을 한 트랜잭션으로 처리한다. 락이 없으면
        // 동일 webhook 두 건이 같은 refundable/멱등결과를 읽어 둘 다 통과 → 이중 환불 기록.
        $db = $this->txnRepository->getDb();

        return $db->transaction(function () use (
            $db, $order, $orderNo, $amount, $pgKey, $pgTid, $reason, $domainId, $fullCancel, $totalPaid
        ) {
            $db->select('SELECT order_no FROM shop_orders WHERE order_no = ? FOR UPDATE', [$orderNo]);

            $totalRefunded = $this->txnRepository->getTotalRefunded($orderNo);
            $refundable = $totalPaid - $totalRefunded;

            if ($refundable <= 0) {
                // 이미 전액 환불 완료 — 동일 webhook 재수신으로 간주
                return Result::success('이미 전액 환불 처리된 주문입니다.', ['already_refunded' => true]);
            }

            // 잔여액 초과분은 잔여액만큼만 기록 (정합성 위배 — 아래 운영로그로 노출)
            $amount = min($amount, $refundable);
            $txnType = ($fullCancel || $amount >= $refundable) ? 'CANCEL' : 'PARTIAL_CANCEL';

            // 멱등 가드(잠금 하): 같은 pgTid + txnType 조합이 이미 SUCCESS이면 재기록 안 함
            foreach ($this->txnRepository->getByOrderNo($orderNo) as $existing) {
                if (($existing['pg_tid'] ?? '') === $pgTid
                    && ($existing['transaction_type'] ?? '') === $txnType
                    && ($existing['transaction_status'] ?? '') === 'SUCCESS'
                ) {
                    return Result::success('이미 기록된 환불 webhook입니다.', ['duplicate' => true]);
                }
            }

            $txnId = $this->txnRepository->createTransaction([
                'order_no' => $orderNo,
                'domain_id' => $domainId,
                'pg_key' => $pgKey,
                'pg_tid' => $pgTid,
                'pg_approval_no' => null,
                'pg_response' => null,
                'payment_method' => $order->getPaymentMethod()->value ?? '',
                'amount' => 0,
                'transaction_type' => $txnType,
                'transaction_status' => 'SUCCESS',
                'cancel_amount' => $amount,
                'cancel_reason' => $reason,
                'cancelled_at' => date('Y-m-d H:i:s'),
                'admin_memo' => "PG webhook 환불 ({$amount}원)",
            ]);

            $this->orderRepository->insertOrderLog([
                'order_no' => $orderNo,
                'prev_status' => '',
                'prev_status_label' => '',
                'new_status' => '',
                'new_status_label' => '',
                'change_type' => 'PAYMENT',
                'changed_by' => 'SYSTEM',
                'staff_id' => 0,
                'reason' => '[PG 환불 통보] ' . number_format($amount) . "원 — 주문 상태 확인 필요(재고·포인트·쿠폰 복구는 취소 확정 시): {$reason}",
            ]);

            // 운영 로그로 시끄럽게 — 환불 후 미확정 출고(환불된 상품 배송) 방지를 위해 운영자 주의 환기.
            // 상태 전이(취소 확정)는 운영자 몫이므로 여기서 자동 취소하지 않는다.
            error_log("[SHOP][REFUND] 외부 PG 환불 통보 주문={$orderNo} 금액={$amount} 유형={$txnType} — 주문 상태/출고 확인 필요");

            $newTotalRefunded = $totalRefunded + $amount;

            return Result::success('환불이 기록되었습니다.', [
                'transaction_id' => $txnId,
                'refund_amount' => $amount,
                'total_refunded' => $newTotalRefunded,
                'remaining' => $totalPaid - $newTotalRefunded,
                'full_cancel' => $txnType === 'CANCEL',
            ]);
        });
    }

    /**
     * 주문의 환불 가능 금액 조회
     */
    public function getRefundableAmount(string $orderNo): Result
    {
        $order = $this->orderRepository->find($orderNo);
        if (!$order) {
            return Result::failure('주문을 찾을 수 없습니다.');
        }

        $totalPaid = $order->getFinalAmount();
        $totalRefunded = $this->txnRepository->getTotalRefunded($orderNo);

        return Result::success('', [
            'total_paid' => $totalPaid,
            'total_refunded' => $totalRefunded,
            'refundable' => $totalPaid - $totalRefunded,
        ]);
    }

    /**
     * 주문의 결제 트랜잭션 이력
     */
    public function getTransactionHistory(string $orderNo): array
    {
        return $this->txnRepository->getByOrderNo($orderNo);
    }

    /**
     * 결제 트랜잭션에서 PG TID 조회
     */
    private function findPgTid(string $orderNo): string
    {
        $transactions = $this->txnRepository->getByOrderNo($orderNo);

        foreach ($transactions as $txn) {
            if (($txn['transaction_type'] ?? '') === 'PAYMENT'
                && ($txn['transaction_status'] ?? '') === 'SUCCESS'
                && !empty($txn['pg_tid'])
            ) {
                return $txn['pg_tid'];
            }
        }

        return '';
    }
}
