<?php

namespace Mublo\Contract\Payment;

/**
 * 결제 준비 상태 스냅샷 — 소비 패키지가 자기 주문에서 뽑아 알려준다.
 *
 * 리다이렉트형 PG 는 승인(confirm)이 별도 단계라, 승인 "전에" 결제창이 돌려준 금액이
 * 주문 금액과 같은지 대조할 수 있다. 그 대조에 필요한 최소 정보다.
 * (승인 후 검증은 PaymentConsumerInterface::verifyPayment 가 권위를 갖는다)
 */
final readonly class PreparedPayment
{
    public function __construct(
        /** 결제 상태 — 소비 패키지의 어휘. 'PAID' 는 이미 결제 완료(콜백 재진입)를 뜻한다 */
        public string $status,
        /** 주문 생성 시 선택된 PG 키 */
        public string $gateway,
        /** 결제해야 할 금액 */
        public int $amount,
        /** 이미 결제가 끝난 주문인가 — 콜백 재진입(새로고침)의 멱등 처리용 */
        public bool $paid = false,
    ) {
    }
}
