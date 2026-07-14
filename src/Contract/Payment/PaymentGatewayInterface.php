<?php

namespace Mublo\Contract\Payment;

/**
 * PG(Payment Gateway) 계약 인터페이스
 *
 * ContractRegistry에 등록되어 1:N PG 연동을 지원합니다.
 * 각 PG사(토스, 이니시스 등)는 이 인터페이스를 구현합니다.
 */
interface PaymentGatewayInterface
{
    /**
     * 결제 준비 (PG에 주문 정보 전달, 클라이언트 토큰 반환)
     *
     * @param array<string, mixed> $orderData 체크아웃이 구성한 주문 정보 (order_no, amount 등 — PG사별 참조 키 상이)
     */
    public function prepare(array $orderData): PaymentGatewayResponse;

    /**
     * 결제 검증 (PG 서버에서 실제 결제 확인)
     */
    public function verify(string $transactionId): PaymentGatewayResponse;

    /**
     * 결제 취소
     *
     * @param int      $amount           이번에 취소할 금액
     * @param int|null $remainingAmount  이 취소 직전의 취소 가능 잔액. 소비처만 아는 값이라
     *                                   소비처가 알려준다. 모르면 null.
     *
     * PG 는 전체취소와 부분취소를 다른 요청으로 다룬다. KCP 는 mod_type(STSC/STPC)이
     * 갈리고 부분취소일 때 잔액(rem_mny)까지 요구하며, 이니시스도 Refund/PartialRefund 로
     * 나뉜다. 그 잔액은 주문을 소유한 소비처만 알기 때문에 소비처가 넘겨준다.
     *
     * 어느 형식으로 보낼지는 구현체가 정한다 — PG 마다 규칙이 다르고, 그 차이를 흡수하는
     * 것이 플러그인의 일이다. 예컨대 KCP 는 부분취소 이력이 있는 거래에 전체취소를
     * 거부하므로(8134), 잔액을 아는 한 부분취소 형식으로 보내는 편이 안전하다.
     * 소비처는 "얼마를 취소하는가"와 "얼마가 남아 있었는가"라는 사실만 전달한다.
     */
    public function cancel(
        string $transactionId,
        int $amount,
        string $reason = '',
        ?int $remainingAmount = null,
    ): PaymentGatewayResponse;

    /**
     * 클라이언트 SDK 설정 (프론트에서 PG 결제창 호출에 필요한 정보)
     *
     * @return array<string, mixed> PG사별로 키가 상이한 자유형 설정 뭉치
     */
    public function getClientConfig(): array;

    /**
     * 체크아웃 페이지에 삽입할 결제 핸들러 JS
     *
     * 반환한 JS는 체크아웃 페이지에 <script>로 삽입됩니다.
     * JS는 window.MubloPayHandlers['pg_key'] = function(data) {...} 형태로
     * 핸들러를 등록해야 합니다.
     *
     * data 구조: { order_no, amount, gateway, transaction_id, client_config, ... }
     *
     * ── 신호 규약 ──────────────────────────────────────────────────────────
     *
     * 플러그인은 결제 진행 중 일어난 "사실"만 알리고 화면은 건드리지 않습니다.
     * 알릴지 말지, 무엇을 보여줄지, 결제 버튼을 되돌릴지는 소비 패키지가 정합니다
     * (같은 PG를 Shop·Mshop 등이 공유하며 각자 다르게 처리할 수 있습니다).
     *
     *   document.dispatchEvent(new CustomEvent('mublo:pay', {
     *       detail: { gateway: 'pg_key', status: 'done'|'cancel'|'fail', message: '사유', code: 'PG코드', order_no: '주문번호' }
     *   }));
     *
     *   status=done   : 결제가 완료됐다(승인·검증까지 끝). 이동할지 그 자리에서 처리할지는
     *                   소비처가 정한다 — 플러그인은 이동시키지 않는다
     *   status=cancel : 사용자가 결제창을 닫음 — 오류가 아니다
     *   status=fail   : 결제를 진행할 수 없다. message 에 사람이 읽을 사유를 담는다
     *   order_no      : 관련 주문번호(선택). code 는 PG 원본 코드(선택)
     *
     * 플러그인은 화면에 대한 결정을 직접 내리지 않습니다 — 알림 표시(MubloRequest.showAlert 등),
     * 결제 버튼 조작(MubloPayReset)은 소비처의 몫입니다. 소비처가 준 값(status_url 등)으로
     * 코어 유틸리티를 호출하는 것은 무방합니다. 소비처를 모르는 것이 이 계약의 목적입니다.
     *
     * 결제창이 가맹점 페이지를 통째로 대체하는 경우(리다이렉트형)에는 신호를 받을 창이
     * 없습니다. 이때만 이동이 필요하며, 목적지 역시 소비처가 정합니다 — prepare 로 받은
     * success_url·fail_url 을 콜백 URL 쿼리(ok·fail)로 실어 보내고 되돌려받아 그대로
     * 이동하며, 실패 사유는 복귀 경로에 pay_error 쿼리로 싣습니다.
     *
     * 플러그인은 소비처의 URL 규칙을 알지 못합니다. 경로를 받지 못했다면 추측하지 말고
     * 결과만 알리는 화면으로 끝냅니다 — 남의 패키지 경로로 보내는 것보다 낫습니다.
     *
     * 특별한 클라이언트 처리가 불필요한 PG는 null을 반환합니다.
     */
    public function getCheckoutScript(): ?string;
}
