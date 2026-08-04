<?php
declare(strict_types=1);

namespace Mublo\Plugin\TestPay;

use Mublo\Contract\Payment\PaymentGatewayInterface;
use Mublo\Contract\Payment\PaymentGatewayResponse;

/**
 * 테스트 결제 게이트웨이
 *
 * 개발/테스트용 가상 PG 구현체.
 * 모든 결제가 즉시 성공하며, 실제 외부 API 호출 없음.
 * 트랜잭션 ID에 TEST_ 접두사를 붙여 실제 결제와 구별.
 */
class TestPayGateway implements PaymentGatewayInterface
{
    /**
     * 결제 준비 — 즉시 성공, 가상 트랜잭션 ID 발급
     */
    public function prepare(array $orderData): PaymentGatewayResponse
    {
        // 승인 금액을 트랜잭션 ID에 인코딩한다. 검증(verify)은 거래ID만 받으므로,
        // 서버측 금액 검증(fail-closed)에 필요한 금액을 여기서 실어 둔다.
        $amount = (int) ($orderData['amount'] ?? 0);
        $transactionId = 'TEST_' . $amount . '_' . date('YmdHis') . '_' . mt_rand(1000, 9999);

        return new PaymentGatewayResponse(
            success: true,
            transactionId: $transactionId,
            orderNo: (string) ($orderData['order_no'] ?? ''),
            amount: $amount,
            data: ['pg_response' => ['status' => 'PAID', 'method' => 'test']],
        );
    }

    /**
     * 결제 검증 — TEST_ 접두사면 성공, 트랜잭션 ID에 인코딩된 승인 금액을 표면화
     */
    public function verify(string $transactionId): PaymentGatewayResponse
    {
        if (!str_starts_with($transactionId, 'TEST_')) {
            return new PaymentGatewayResponse(
                success: false,
                transactionId: $transactionId,
                status: 'FAILED',
                paymentMethod: 'CARD',
                data: ['verified_at' => date('Y-m-d H:i:s')],
            );
        }

        // TEST_{amount}_{date}_{rand}
        $parts = explode('_', $transactionId);

        return new PaymentGatewayResponse(
            success: true,
            transactionId: $transactionId,
            amount: isset($parts[1]) && ctype_digit($parts[1]) ? (int) $parts[1] : null,
            status: 'PAID',
            paymentMethod: 'CARD',
            data: ['verified_at' => date('Y-m-d H:i:s')],
        );
    }

    /**
     * 결제 취소 — 항상 성공
     */
    public function cancel(
        string $transactionId,
        int $amount,
        string $reason = '',
        ?int $remainingAmount = null,
    ): PaymentGatewayResponse
    {
        return new PaymentGatewayResponse(
            success: true,
            transactionId: $transactionId,
            cancelAmount: $amount,
            data: ['reason' => $reason, 'cancelled_at' => date('Y-m-d H:i:s')],
        );
    }

    /**
     * 클라이언트 설정 — 외부 SDK 불필요
     */
    public function getClientConfig(): array
    {
        return [
            'mode' => 'test',
            'requires_redirect' => false,
        ];
    }

    /**
     * 체크아웃 JS — 테스트 결제 확인 모달을 플러그인이 자체 주입한다.
     *
     * 마크업/스타일/핸들러를 플러그인 파일로 분리 소유한다:
     *   - views/checkout-modal.html  : 모달 마크업
     *   - assets/checkout-modal.css  : 스타일
     *   - assets/checkout-modal.js   : 핸들러 (__TPAY_CSS__/__TPAY_HTML__ 플레이스홀더)
     * 여기서는 세 파일을 읽어 JS 한 덩어리로 조립만 한다(JSON 인코딩으로 안전 주입).
     * Shop 체크아웃은 테스트 결제를 알 필요가 없다(MubloPayHandlers['testpay']만 호출).
     */
    public function getCheckoutScript(): ?string
    {
        $js = (string) file_get_contents(__DIR__ . '/assets/checkout-modal.js');
        $css = (string) file_get_contents(__DIR__ . '/assets/checkout-modal.css');
        $html = (string) file_get_contents(__DIR__ . '/views/checkout-modal.html');

        // 슬래시는 이스케이프(기본)하여 인라인 <script> 안에서 </script> 조기 종료를 막는다.
        return strtr($js, [
            '__TPAY_CSS__'  => json_encode($css, JSON_UNESCAPED_UNICODE),
            '__TPAY_HTML__' => json_encode(trim($html), JSON_UNESCAPED_UNICODE),
        ]);
    }
}
