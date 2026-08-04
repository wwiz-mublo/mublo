<?php
declare(strict_types=1);

namespace Mublo\Plugin\PayApp\Controller\Front;

use Mublo\Core\Context\Context;
use Mublo\Core\Http\Request;
use Mublo\Core\Response\HtmlResponse;
use Mublo\Contract\Payment\PaymentConsumerInterface;
use Mublo\Core\Registry\ContractRegistry;
use Mublo\Plugin\PayApp\Service\PayAppConfigService;
use Mublo\Plugin\PayApp\PayAppClient;

/**
 * PayApp Feedback URL 콜백 컨트롤러
 *
 * PayApp이 결제 완료/취소 시 서버→서버로 POST 호출.
 * 반드시 HTTP 200 + 본문 'SUCCESS'를 반환해야 합니다.
 *
 * POST /pay-app/callback/feedback
 *
 * 결제 흐름:
 * 1. CartController가 prepare() → mul_no 발급, Shop 주문은 RECEIPT 상태로 생성
 * 2. 사용자가 결제 완료 → PayApp이 feedbackurl 호출 (pay_state=4)
 *    → PaymentService::verifyPaymentByCallback → shop_payment_transactions + 주문 PAID 전이
 * 3. 결제 취소/환불 → PayApp이 feedbackurl 호출 (pay_state=8/9/32/64/70/71)
 *    → RefundService::recordExternalRefund → shop_payment_transactions에 CANCEL/PARTIAL_CANCEL 기록
 */
class CallbackController
{
    /** 결제 완료 */
    private const STATE_PAID = 4;

    /** 전체 취소/환불 계열 */
    private const STATES_FULL_CANCEL = [8, 9, 32, 64];

    /** 부분 취소/환불 계열 */
    private const STATES_PARTIAL_CANCEL = [70, 71];

    public function __construct(
        private PayAppConfigService $configService,
        private ContractRegistry $registry,
    ) {}

    public function feedback(Request $request, Context $context): HtmlResponse
    {
        // PayApp은 POST body로 전달
        $data = $request->all();
        if (empty($data) || empty($data['mul_no'])) {
            // raw body에서 파싱 시도
            $raw = file_get_contents('php://input');
            if ($raw) {
                parse_str($raw, $data);
            }
        }

        $mulNo = (string) ($data['mul_no'] ?? '');
        $payState = (int) ($data['pay_state'] ?? 0);
        $orderNo = (string) ($data['var1'] ?? '');
        $domainIdFromVar = (int) ($data['var2'] ?? 0);
        $domainId = $domainIdFromVar > 0 ? $domainIdFromVar : ($context->getDomainId() ?? 1);

        // 인증 검증 (linkkey/linkval + 옵션 IP allowlist)
        $config = $this->configService->getConfig($domainId);

        if (!$this->isAllowedIp($request, $config)) {
            error_log("[PayApp] Feedback rejected by IP allowlist: mul_no={$mulNo}, ip=" . $this->clientIp($request));
            return new HtmlResponse('FAIL', 403);
        }

        $client = new PayAppClient(
            $config['userid'] ?? '',
            $config['linkkey'] ?? '',
            $config['linkval'] ?? ''
        );

        if (!$client->verifyFeedback($data)) {
            error_log("[PayApp] Feedback verification failed: mul_no={$mulNo}");
            return new HtmlResponse('FAIL', 403);
        }

        // 주문 반영은 소비 패키지가 한다(계약). 플러그인은 어느 패키지인지 알지 못한다.
        $consumer = $this->resolveConsumer($request);
        if ($consumer === null) {
            error_log("[PayApp] 결제 소비자를 찾을 수 없습니다: mul_no={$mulNo}, order={$orderNo}");
            return new HtmlResponse('FAIL', 500);
        }

        try {
            if ($payState === self::STATE_PAID) {
                // 결제 완료 — 소비 패키지가 검증하고 주문을 확정한다
                $result = $consumer->verifyPayment($domainId, $orderNo, 'payapp', $mulNo);
                if ($result->isFailure()) {
                    error_log("[PayApp] verifyPaymentByCallback failed: {$result->getMessage()} mul_no={$mulNo}, order={$orderNo}");
                    // SUCCESS를 반환하면 PayApp이 재시도하지 않아 '결제됨/주문 미완료'가 되고
                    // 30분 후 스윕에 삭제될 수 있다. 실패를 알려 재전송(재시도)을 유도한다.
                    // verifyPaymentByCallback은 이미 PAID면 success를 반환하므로 중복 재시도는 안전.
                    return new HtmlResponse('FAIL', 500);
                }
            } elseif (in_array($payState, self::STATES_FULL_CANCEL, true)) {
                $this->handleRefund($consumer, $orderNo, $mulNo, $domainId, $data, true);
            } elseif (in_array($payState, self::STATES_PARTIAL_CANCEL, true)) {
                $this->handleRefund($consumer, $orderNo, $mulNo, $domainId, $data, false);
            }
        } catch (\Throwable $e) {
            error_log("[PayApp] Feedback processing error: {$e->getMessage()} mul_no={$mulNo}");
            // 결제완료 통보 처리 중 예외 → 재시도 유도(멱등 처리라 안전).
            if ($payState === self::STATE_PAID) {
                return new HtmlResponse('FAIL', 500);
            }
        }

        return new HtmlResponse('SUCCESS');
    }

    /**
     * 결제창(팝업) 복귀 지점
     * GET|POST /pay-app/callback/return
     *
     * 페이앱은 결제가 끝나면 결제창 팝업을 이 주소로 이동시킨다. PG 는 자기가 있는 창만
     * 옮길 수 있으므로, 여기서 부모창(가맹점 페이지)에 결제창이 끝났음을 알리고 팝업을
     * 닫는다. 그 뒤 처리는 부모창이 한다 — 부모창은 이미 팝업 닫힘을 감지해 결제 상태를
     * 서버에서 확인(statecheck)하고 주문을 확정하는 경로를 갖고 있다.
     *
     * 결제 성공 여부를 여기서 판정하지 않는다. 이 창은 "결제창이 끝났다"는 사실만 알고,
     * 승인 여부의 권위는 PG 서버 조회와 소비 패키지의 검증에 있다.
     */
    public function returnUrl(Request $request, Context $context): HtmlResponse
    {
        return new HtmlResponse(<<<'HTML'
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><title>결제 확인 중</title></head>
<body>
<script>
(function () {
    var opener = null;
    try { if (window.opener && !window.opener.closed) opener = window.opener; } catch (e) {}

    if (opener) {
        // 부모창이 살아 있다 — 창을 닫아 부모의 '팝업 닫힘 감지 → 결제 확인'을 깨운다.
        try { window.close(); } catch (e) {}
        return;
    }

    // 부모창이 없다(사용자가 닫았거나 팝업이 단독 창이 된 경우).
    // 결제 결과는 PG 서버 통보(webhook)가 처리하므로 여기서는 안내만 남긴다.
    document.getElementById('msg').style.display = '';
})();
</script>
<p id="msg" style="display:none; font-family:sans-serif; text-align:center; margin-top:3rem;">
    결제 결과를 처리하고 있습니다.<br>이 창을 닫고 주문 내역에서 확인해주세요.
</p>
</body></html>
HTML);
    }

    /**
     * 결제 결과를 되돌려줄 소비 패키지 조회 (계약: PaymentConsumerInterface)
     *
     * 어느 패키지인지는 결제 준비 때 받아 feedbackurl 에 실어 보낸 키(by)로 정해진다.
     * 레지스트리에 등록된 키일 때만 유효하다 — 알 수 없으면 결과를 아무 데도
     * 반영하지 않는다(fail-closed).
     */
    private function resolveConsumer(Request $request): ?PaymentConsumerInterface
    {
        $key = (string) $request->query('by', '');
        if ($key === '') {
            return null;
        }

        try {
            $consumer = $this->registry->get(PaymentConsumerInterface::class, $key);
        } catch (\Throwable $e) {
            error_log('[PayApp] 알 수 없는 결제 소비자 키: ' . $key);
            return null;
        }

        return $consumer instanceof PaymentConsumerInterface ? $consumer : null;
    }

    /**
     * 환불 webhook 을 소비 패키지에 위임한다(계약).
     * 페이앱이 보내는 취소 금액 키는 문서/이벤트에 따라 다르므로 알려진 후보를 모두 시도한다.
     */
    private function handleRefund(PaymentConsumerInterface $consumer, string $orderNo, string $mulNo, int $domainId, array $data, bool $fullCancel): void
    {
        if ($orderNo === '' || $mulNo === '') {
            error_log('[PayApp] Refund webhook missing order/mul_no: ' . json_encode($data));
            return;
        }

        $amount = $this->extractCancelAmount($data);
        $reason = (string) ($data['cancelmemo'] ?? $data['cancel_reason'] ?? 'PG 통보 취소');

        // 부분 취소인데 금액을 못 뽑은 경우만 거부. 전체 취소는 RefundService가 잔여액으로 처리.
        if (!$fullCancel && $amount <= 0) {
            error_log("[PayApp] Partial cancel webhook without amount: mul_no={$mulNo}, order={$orderNo}");
            return;
        }

        // 전체 취소면 잔여액을 그대로 환불 처리 (구현체가 0보다 큰 값을 요구하므로 sentinel)
        $effectiveAmount = $amount > 0 ? $amount : PHP_INT_MAX;

        $result = $consumer->recordExternalRefund(
            $domainId, $orderNo, 'payapp', $mulNo, $effectiveAmount, $reason, $fullCancel
        );

        if ($result->isFailure()) {
            error_log("[PayApp] recordExternalRefund failed: {$result->getMessage()} mul_no={$mulNo}, order={$orderNo}");
        }
    }

    /**
     * 페이앱이 보내는 다양한 키 이름에서 취소 금액을 시도해 추출한다.
     */
    private function extractCancelAmount(array $data): int
    {
        foreach (['cancelprice', 'cancel_price', 'cancelamount', 'cancel_amount', 'cancelAmt'] as $key) {
            if (isset($data[$key]) && is_numeric($data[$key])) {
                $value = (int) $data[$key];
                if ($value > 0) {
                    return $value;
                }
            }
        }
        return 0;
    }

    /**
     * IP allowlist 검증. config에 feedback_ip_allowlist가 비어 있으면 모두 허용한다.
     * CIDR 표기(예: 1.2.3.0/24)도 지원.
     */
    private function isAllowedIp(Request $request, array $config): bool
    {
        $raw = trim((string) ($config['feedback_ip_allowlist'] ?? ''));
        if ($raw === '') {
            return true;
        }

        $clientIp = $this->clientIp($request);
        if ($clientIp === '') {
            return false;
        }

        foreach (preg_split('/[\s,]+/', $raw) as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            if (str_contains($entry, '/')) {
                if ($this->ipInCidr($clientIp, $entry)) {
                    return true;
                }
            } elseif ($entry === $clientIp) {
                return true;
            }
        }

        return false;
    }

    private function clientIp(Request $request): string
    {
        if (method_exists($request, 'getClientIp')) {
            $ip = (string) $request->getClientIp();
            if ($ip !== '') {
                return $ip;
            }
        }
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    }

    private function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $mask] = array_pad(explode('/', $cidr, 2), 2, null);
        if ($subnet === null || $mask === null) {
            return false;
        }
        $mask = (int) $mask;
        if ($mask < 0 || $mask > 32) {
            return false;
        }
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($ipLong === false || $subnetLong === false) {
            return false;
        }
        $maskLong = $mask === 0 ? 0 : (~0 << (32 - $mask)) & 0xFFFFFFFF;
        return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
    }
}
