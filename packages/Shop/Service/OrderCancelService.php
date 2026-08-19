<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\Service;

use Mublo\Core\Result\Result;
use Mublo\Packages\Shop\Enum\OrderAction;
use Mublo\Packages\Shop\Repository\OrderRepository;

/**
 * OrderCancelService
 *
 * 고객(사용자) 셀프 주문취소 오케스트레이터
 *
 * 정책:
 * - received(미결제/입금대기): 받은 돈이 없으므로 즉시 cancelled.
 *   (CANCELLED 전이 시 쿠폰·포인트는 구독자가 자동 복원)
 * - paid(결제완료):
 *     · 환불 불필요(전액 포인트/쿠폰 결제 등): 즉시 cancelled
 *     · PG 결제(payment_gateway 존재): PG 자동 결제취소 → 성공 시 cancelled,
 *       실패 시 cancel_requested(관리자 수동 처리)로 접수
 *     · 무통장 등 자동환불 불가: cancel_requested(관리자 환불 처리)로 접수
 * - 그 외 상태(배송준비 이후 등): 취소 불가
 *
 * 순환참조 회피: OrderService는 PaymentService→OrderService 순환 때문에
 * RefundService를 직접 주입할 수 없다. 이 오케스트레이터가 OrderService와
 * RefundService를 함께 들고 정책을 조립한다.
 *
 * 금지:
 * - Request/Response 처리 (Controller 담당)
 * - DB 직접 접근 (Repository 담당)
 */
class OrderCancelService
{
    private OrderRepository $orderRepository;
    private OrderService $orderService;
    private RefundService $refundService;

    public function __construct(
        OrderRepository $orderRepository,
        OrderService $orderService,
        RefundService $refundService
    ) {
        $this->orderRepository = $orderRepository;
        $this->orderService = $orderService;
        $this->refundService = $refundService;
    }

    /**
     * 고객 주문취소 처리
     *
     * @param string $orderNo 주문번호 (호출 전 소유권 검증 완료 가정 — Controller 책임)
     * @param int $domainId 도메인 ID
     * @param string $reason 취소 사유 (선택)
     * @return Result
     */
    public function cancelByCustomer(string $orderNo, int $domainId, string $reason = ''): Result
    {
        $order = $this->orderRepository->find($orderNo);
        if (!$order) {
            return Result::failure('주문을 찾을 수 없습니다.');
        }
        if ((int) ($order->getDomainId() ?? 0) !== $domainId) {
            return Result::failure('주문을 찾을 수 없습니다.');
        }

        $action = OrderAction::tryFrom($order->getOrderStatusRaw() ?? '');
        if (!$action || !$action->isCancellable()) {
            return Result::failure('현재 상태에서는 주문을 취소할 수 없습니다.');
        }

        // 주문 상태만 보면 부분 배송을 놓친다. 주문 상태는 가장 뒤처진 품목을 따르므로
        // 3개 중 1개가 배송완료여도 나머지가 결제완료면 주문은 결제완료로 남는다.
        if ($this->itemsBlockingCancel($this->orderRepository->getItems($orderNo)) !== []) {
            return Result::failure(
                '이미 출고된 상품이 있어 주문 전체 취소가 어렵습니다. '
                . '아직 출고되지 않은 상품의 취소는 판매자에게 문의해 주세요.'
            );
        }

        $reason = trim($reason);

        // received(미결제/입금대기): 받은 돈 없음 → 즉시 취소
        if ($action === OrderAction::RECEIVED) {
            return $this->finalizeCancel($orderNo, $domainId, $reason, '주문이 취소되었습니다.');
        }

        // ── 여기부터 paid(결제완료) ──

        // 환불 가능 금액(실제 PG/계좌로 결제된 금액)
        $refundable = 0;
        $refundInfo = $this->refundService->getRefundableAmount($orderNo);
        if ($refundInfo->isSuccess()) {
            $refundable = (int) ($refundInfo->getData()['refundable'] ?? 0);
        }

        // 전액 포인트/쿠폰 결제 등 환불할 결제금액이 없으면 즉시 취소(이벤트가 포인트·쿠폰 복원)
        if ($refundable <= 0) {
            return $this->finalizeCancel($orderNo, $domainId, $reason, '주문이 취소되었습니다.');
        }

        $gateway = trim((string) ($order->getPaymentGateway() ?? ''));

        // PG 결제 → 자동 결제취소 후 즉시 취소
        if ($gateway !== '') {
            $refund = $this->refundService->processRefund(
                $orderNo,
                $refundable,
                'PG_CANCEL',
                $reason !== '' ? $reason : '고객 주문취소',
                $domainId,
                0
            );

            if ($refund->isFailure()) {
                // 자동 환불 실패 → 취소요청으로 접수(관리자가 수동 환불 후 취소 확정)
                $note = '자동 환불 실패: ' . $refund->getMessage() . ($reason !== '' ? ' / ' . $reason : '');
                $req = $this->orderService->updateStatus(
                    $orderNo,
                    OrderAction::CANCEL_REQUESTED->value,
                    $domainId,
                    $note,
                    'CUSTOMER'
                );
                if ($req->isFailure()) {
                    return $req;
                }
                return Result::success(
                    '자동 환불에 실패하여 취소 요청으로 접수되었습니다. 판매자 확인 후 처리됩니다.',
                    $req->getData()
                );
            }

            return $this->finalizeCancel($orderNo, $domainId, $reason, '주문이 취소되고 결제가 환불되었습니다.');
        }

        // 무통장 등 자동환불 불가 → 취소요청 접수(관리자 환불 처리 후 취소 확정)
        $req = $this->orderService->updateStatus(
            $orderNo,
            OrderAction::CANCEL_REQUESTED->value,
            $domainId,
            $reason !== '' ? $reason : '고객 주문취소 요청',
            'CUSTOMER'
        );
        if ($req->isFailure()) {
            return $req;
        }
        return Result::success(
            '취소 요청이 접수되었습니다. 환불은 판매자 확인 후 처리됩니다.',
            $req->getData()
        );
    }

    /**
     * cancelled 전이 확정 (쿠폰·포인트 복원은 OrderStatusChangedEvent 구독자가 수행)
     */
    private function finalizeCancel(string $orderNo, int $domainId, string $reason, string $message): Result
    {
        $result = $this->orderService->updateStatus(
            $orderNo,
            OrderAction::CANCELLED->value,
            $domainId,
            $reason !== '' ? $reason : '고객 주문취소',
            'CUSTOMER'
        );
        if ($result->isFailure()) {
            return $result;
        }
        return Result::success($message, $result->getData());
    }

    /**
     * 주문 전체 취소를 막는 품목의 상품명.
     *
     * 취소는 아직 나가지 않은 것에만 성립한다. 한 상품이라도 출고됐다면 그 주문은
     * "없던 일"이 될 수 없다. 그런데도 전체 취소를 허용하면 환불액은
     * getRefundableAmount()가 주문 전체 기준으로 잡으므로 이미 받은 상품 값까지 돌려주게 된다.
     *
     * 이미 취소된 품목은 막지 않는다 — 되돌릴 것이 남아 있지 않다.
     * 상태를 알 수 없으면(판매자가 FSM에 추가한 커스텀 상태) 막는 쪽에 둔다.
     * 잘못 막으면 문의 한 번이지만, 잘못 열면 돈이 나간다.
     *
     * 이 판정을 프론트 주문 상세도 그대로 쓴다 — 버튼을 띄우는 규칙과
     * 서버가 집행하는 규칙이 갈리면 안 된다.
     *
     * @param array $items 주문상품 목록 (OrderRepository::getItems)
     * @return string[] 취소를 막는 상품명 (없으면 빈 배열 = 전체 취소 가능)
     */
    public function itemsBlockingCancel(array $items): array
    {
        $blocking = [];
        foreach ($items as $item) {
            $status = (string) ($item['status'] ?? '');
            if ($status === OrderAction::CANCELLED->value) {
                continue;
            }
            $action = OrderAction::tryFrom($status);
            if ($action !== null && $action->isCancellable()) {
                continue;
            }
            $name = trim((string) ($item['goods_name'] ?? ''));
            $blocking[] = $name !== '' ? $name : '상품';
        }
        return $blocking;
    }
}
