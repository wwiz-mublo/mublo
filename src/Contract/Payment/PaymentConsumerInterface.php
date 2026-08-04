<?php
declare(strict_types=1);

namespace Mublo\Contract\Payment;

use Mublo\Core\Result\Result;

/**
 * 결제 소비자 계약 — 주문을 소유한 패키지가 구현한다.
 *
 * PaymentGatewayInterface 가 "패키지 → PG" 방향이라면, 이 계약은 그 반대 방향이다.
 * PG 결제창이 돌아오는 콜백은 플러그인이 받지만, 그 결과를 주문에 반영하는 일은
 * 주문을 소유한 패키지만 할 수 있다. 이 계약이 없으면 플러그인이 특정 패키지의
 * 서비스를 직접 붙잡게 되고, 그 순간 PG 플러그인은 그 패키지 전용이 된다.
 *
 * ── 등록 ──────────────────────────────────────────────────────────────
 * 소비 패키지는 자기 키로 ContractRegistry 에 1:N 등록한다.
 *
 *   $registry->register(PaymentConsumerInterface::class, 'shop',
 *       fn() => new ShopPaymentConsumer(...), ['label' => '쇼핑몰']);
 *
 * ── 연결 ──────────────────────────────────────────────────────────────
 * 어느 소비자에게 결과를 돌려줄지는 결제 준비 시점에 정해진다. 패키지는
 * prepare() 의 orderData 에 자기 키를 'consumer' 로 실어 보내고, 플러그인은 그 값을
 * 결제창 콜백 URL 에 함께 태워 되돌려받아 이 계약을 조회한다. 플러그인은 키 문자열을
 * 옮기기만 할 뿐 그것이 어느 패키지인지 알 필요가 없다.
 *
 * 되돌려받은 키는 레지스트리에 등록된 값일 때만 유효하다. 알 수 없는 키면 결과를
 * 반영하지 않는다(fail-closed) — 남의 주문에 결제를 붙이는 것보다 낫다.
 */
interface PaymentConsumerInterface
{
    /**
     * 승인 전 대조용 결제 준비 상태 조회
     *
     * 리다이렉트형 PG 가 승인(confirm) 호출 전에 금액을 대조하는 용도다.
     * 주문이 없으면 null 을 반환한다. 사전 대조를 제공하지 않는 패키지도 null 을
     * 반환하면 되며, 이때 PG 는 승인 후 verifyPayment 의 검증에만 의존한다.
     */
    public function findPreparedPayment(int $domainId, string $orderNo): ?PreparedPayment;

    /**
     * 결제 검증 + 주문 반영 (검증 권위)
     *
     * PG 승인이 끝난 뒤 호출한다. 구현체는 PaymentGatewayInterface::verify 로 PG 에
     * 실제 승인 내역을 확인하고, 주문번호·금액 일치를 검증한 뒤 주문 상태를 전이한다.
     * 같은 결제가 중복 통보되어도 안전해야 한다(멱등).
     */
    public function verifyPayment(int $domainId, string $orderNo, string $pgKey, string $transactionId): Result;

    /**
     * PG 에서 발생한 환불을 주문에 기록
     *
     * 가맹점이 아니라 PG 측(관리자 콘솔 등)에서 취소가 일어나고 그 사실이 웹훅으로
     * 통보되는 경우에 호출한다. 환불 요청 자체는 하지 않는다 — 이미 일어난 일의 기록이다.
     *
     * @param bool $fullCancel 전체 취소면 true, 부분 취소면 false
     */
    public function recordExternalRefund(
        int $domainId,
        string $orderNo,
        string $pgKey,
        string $transactionId,
        int $amount,
        string $reason,
        bool $fullCancel,
    ): Result;
}
