<?php

namespace Mublo\Packages\Shop\Service;

use Mublo\Core\Event\EventDispatcher;
use Mublo\Core\Result\Result;
use Mublo\Packages\Shop\Event\PaymentCompletedEvent;
use Mublo\Packages\Shop\Enum\OrderAction;
use Mublo\Packages\Shop\Repository\CartRepository;
use Mublo\Packages\Shop\Repository\OrderRepository;
use Mublo\Packages\Shop\Repository\PaymentCompletionRepository;
use Mublo\Packages\Shop\Repository\PaymentTransactionRepository;

/**
 * 결제 완료의 필수 내부 후처리 단일 소유자.
 *
 * 장바구니·쿠폰·포인트 처리는 모두 멱등 Service API로 실행하고, 완료 후에만
 * 외부 확장용 PaymentCompletedEvent를 발행한다.
 */
class PaymentCompletionService
{
    public function __construct(
        private readonly PaymentCompletionRepository $completionRepository,
        private readonly PaymentTransactionRepository $transactionRepository,
        private readonly OrderRepository $orderRepository,
        private readonly CartRepository $cartRepository,
        private readonly CouponService $couponService,
        private readonly OrderPointService $orderPointService,
        private readonly EventDispatcher $eventDispatcher,
    ) {
    }

    /** @param array<string, mixed> $verifyData */
    public function stage(
        string $orderNo,
        int $domainId,
        string $pgKey,
        string $pgTid,
        array $verifyData
    ): Result {
        try {
            $row = $this->completionRepository->stage(
                $orderNo,
                $domainId,
                $pgKey,
                $pgTid,
                $verifyData
            );

            return Result::success('결제 완료 후처리가 준비되었습니다.', [
                'event_id' => (string) ($row['event_id'] ?? ''),
                'status' => (string) ($row['status'] ?? 'PENDING'),
            ]);
        } catch (\Throwable $exception) {
            error_log('[SHOP][PAYMENT_COMPLETION] stage_failed order=' . $orderNo
                . ' error=' . $exception->getMessage());
            return Result::failure('결제 완료 후처리를 준비하지 못했습니다.');
        }
    }

    public function process(string $orderNo): Result
    {
        $existing = $this->completionRepository->find($orderNo);
        if ($existing === null) {
            return Result::failure('결제 완료 후처리 원장을 찾을 수 없습니다.');
        }
        if (($existing['status'] ?? '') === 'COMPLETED') {
            return Result::success('결제 완료 후처리가 이미 완료되었습니다.', [
                'already_completed' => true,
                'event_id' => (string) ($existing['event_id'] ?? ''),
            ]);
        }

        $row = $this->completionRepository->claim($orderNo);
        if ($row === null) {
            return Result::success('결제 완료 후처리가 다른 요청에서 진행 중입니다.', [
                'processing' => true,
                'event_id' => (string) ($existing['event_id'] ?? ''),
            ]);
        }

        try {
            $verifyData = json_decode(
                (string) ($row['verify_data'] ?? '{}'),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
            if (!is_array($verifyData)) {
                throw new \RuntimeException('결제 검증 데이터 형식이 올바르지 않습니다.');
            }

            $order = $this->orderRepository->find($orderNo);
            if ($order === null) {
                throw new \RuntimeException('주문을 찾을 수 없습니다.');
            }
            $orderData = $order->toArray();
            if (($orderData['order_status'] ?? '') !== OrderAction::PAID->value) {
                throw new \RuntimeException('PAID 주문만 결제 완료 후처리를 실행할 수 있습니다.');
            }

            $pgKey = (string) ($row['pg_key'] ?? '');
            $pgTid = (string) ($row['pg_tid'] ?? '');
            if ($pgKey !== '' && $this->transactionRepository->findSuccessfulPayment($orderNo) === null) {
                $this->transactionRepository->createTransaction([
                    'order_no' => $orderNo,
                    'domain_id' => (int) ($row['domain_id'] ?? 0),
                    'pg_key' => $pgKey,
                    'pg_tid' => $pgTid,
                    'pg_approval_no' => $verifyData['approval_no'] ?? null,
                    'pg_response' => json_encode($verifyData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'payment_method' => $orderData['payment_method'] ?? '',
                    'amount' => (int) ($verifyData['amount'] ?? 0),
                    'transaction_type' => 'PAYMENT',
                    'transaction_status' => 'SUCCESS',
                ]);
            }

            $paymentMethod = (string) ($verifyData['payment_method'] ?? '');
            if ($paymentMethod !== '') {
                $this->orderRepository->updatePaymentMethod($orderNo, $paymentMethod);
            }

            $cartItemIds = $this->orderRepository->getCartItemIds($orderNo);
            if ($cartItemIds !== []) {
                $this->cartRepository->markOrderedByIds($cartItemIds);
            }

            $couponResult = $this->markCouponsUsed($orderNo, $orderData);
            if ($couponResult->isFailure()) {
                throw new \RuntimeException($couponResult->getMessage());
            }

            // 포인트는 주문 생성 시 이미 선점되어야 한다. 결제 완료에서는 선점 이력만 확인하며,
            // 누락된 경우 유료 주문을 과소 결제로 완료시키지 않고 재시도 가능한 실패로 남긴다.
            $pointResult = $this->orderPointService->confirm($orderData);
            if ($pointResult->isFailure()) {
                throw new \RuntimeException($pointResult->getMessage());
            }

            $eventId = (string) ($row['event_id'] ?? '');
            $this->eventDispatcher->dispatch(new PaymentCompletedEvent(
                $orderNo,
                $pgKey,
                $pgTid,
                $verifyData,
                $eventId,
                (int) ($row['domain_id'] ?? 0),
                date('c'),
            ));

            $leaseToken = (string) ($row['lease_token'] ?? '');
            $this->completionRepository->markCompleted($orderNo, $leaseToken);

            return Result::success('결제 완료 후처리가 완료되었습니다.', [
                'event_id' => $eventId,
            ]);
        } catch (\Throwable $exception) {
            $this->completionRepository->markFailed(
                $orderNo,
                (string) ($row['lease_token'] ?? ''),
                $exception->getMessage()
            );
            error_log('[SHOP][PAYMENT_COMPLETION] process_failed order=' . $orderNo
                . ' error=' . $exception->getMessage());

            return Result::failure('결제 완료 후처리를 완료하지 못했습니다. 재시도 대기 중입니다.');
        }
    }

    private function markCouponsUsed(string $orderNo, array $orderData): Result
    {
        $breakdown = [];
        $json = (string) ($orderData['coupon_breakdown'] ?? '');
        if ($json !== '') {
            $decoded = json_decode($json, true);
            $breakdown = is_array($decoded) ? $decoded : [];
        }

        if ($breakdown === []) {
            $couponId = (int) ($orderData['coupon_id'] ?? 0);
            $discount = (int) ($orderData['coupon_discount'] ?? 0);
            if ($couponId > 0 && $discount > 0) {
                $breakdown = [['coupon_id' => $couponId, 'discount' => $discount]];
            }
        }

        return $this->couponService->markCouponsUsedForOrder($orderNo, $breakdown);
    }
}
