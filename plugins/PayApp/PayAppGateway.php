<?php
declare(strict_types=1);

namespace Mublo\Plugin\PayApp;

use Mublo\Contract\Payment\PaymentGatewayInterface;
use Mublo\Contract\Payment\PaymentGatewayResponse;
use Mublo\Plugin\PayApp\Service\PayAppConfigService;

/**
 * PayApp 결제 게이트웨이
 *
 * PaymentGatewayInterface 구현체.
 *
 * 결제 흐름:
 * 1. prepare()         → payrequest API → mul_no + payurl 반환
 * 2. 프론트 JS          → PayApp Lite JS로 결제창 호출
 * 3. feedbackurl        → payrequest 파라미터로 전달, 서버가 POST로 결제 결과 수신 (pay_state=4)
 * 4. verify()           → statecheck API로 상태 재확인
 * 5. cancel()           → paycancel API
 */
class PayAppGateway implements PaymentGatewayInterface
{
    /**
     * 페이앱 결제요청 최소금액(원).
     *
     * 페이앱 서비스 정책값이며 가맹점이 조정할 수 없다(설정값 아님).
     * 페이앱 정책이 바뀌면 플러그인 업데이트로 이 상수만 갱신한다.
     */
    private const MIN_AMOUNT = 1000;

    private PayAppConfigService $configService;
    private int $domainId;

    public function __construct(PayAppConfigService $configService, int $domainId)
    {
        $this->configService = $configService;
        $this->domainId = $domainId;
    }

    /**
     * 결제 준비 — payrequest API 호출
     */
    public function prepare(array $orderData): PaymentGatewayResponse
    {
        $config = $this->configService->getConfig($this->domainId);

        // 설정 검증
        if (empty($config['userid']) || empty($config['linkkey'])) {
            return new PaymentGatewayResponse(false, '', errorCode: 'NOT_CONFIGURED', errorMessage: 'PayApp API 설정이 필요합니다. (userid/linkkey 미등록)');
        }

        // 페이앱 최소금액 미만은 API 왕복 없이 선제 차단 (페이앱 정책 = self::MIN_AMOUNT)
        $amount = (int) ($orderData['amount'] ?? 0);
        if ($amount < self::MIN_AMOUNT) {
            return new PaymentGatewayResponse(false, '', errorCode: 'MIN_AMOUNT', errorMessage: sprintf('페이앱 최소 결제금액은 %s원입니다.', number_format(self::MIN_AMOUNT)));
        }

        $client = $this->createClient();

        $orderId = $orderData['order_no'] ?? ('PA_' . date('YmdHis') . '_' . mt_rand(1000, 9999));

        $feedbackPath = $config['feedbackurl_path'] ?? '/pay-app/callback/feedback';
        $feedbackUrl = $orderData['feedback_url'] ?? '';
        if ($feedbackUrl === '' && !empty($orderData['base_url'])) {
            $baseUrl = rtrim($orderData['base_url'], '/');
            // PayApp feedbackurl은 반드시 https여야 함
            $baseUrl = preg_replace('/^http:\/\//i', 'https://', $baseUrl);
            $feedbackUrl = $baseUrl . $feedbackPath;
        }

        // 결제 결과를 되돌려줄 소비자 키를 웹훅 URL 에 싣는다 — 콜백은 별도 요청이라
        // 이 값을 기억할 수 없다. 플러그인은 값의 의미를 알지 못하고 옮기기만 한다.
        $consumer = (string) ($orderData['consumer'] ?? '');
        if ($feedbackUrl !== '' && $consumer !== '') {
            $sep = str_contains($feedbackUrl, '?') ? '&' : '?';
            $feedbackUrl .= $sep . 'by=' . rawurlencode($consumer);
        }

        // 결제창은 별도 팝업으로 뜨고, 결제가 끝나면 페이앱이 그 팝업을 returnurl 로
        // 이동시킨다 — PG 는 자기가 있는 창만 옮길 수 있다. 여기에 소비처의 완료 페이지를
        // 넣으면 완료 화면이 팝업 안에 뜨고 팝업이 살아남아, 부모창의 '팝업 닫힘 감지 →
        // 결제 확인' 폴백이 영영 돌지 않는다. 그래서 복귀 지점은 플러그인이 소유한다.
        $returnUrl = !empty($orderData['base_url'])
            ? rtrim((string) $orderData['base_url'], '/') . '/pay-app/callback/return'
            : '';

        $params = [
            'goodname'    => $orderData['order_name'] ?? '구독 결제',
            'price'       => (string) ($orderData['amount'] ?? 0),
            'recvphone'   => $orderData['customer_phone'] ?? '',
            'smsuse'      => 'n',
            'returnurl'   => $returnUrl,
            'var1'        => $orderId,
            'var2'        => (string) ($orderData['domain_id'] ?? $this->domainId),
            'memo'        => $orderData['memo'] ?? '',
        ];

        if ($feedbackUrl !== '') {
            $params['feedbackurl'] = $feedbackUrl;
        }

        $result = $client->payRequest($params);

        $state = $result['state'] ?? '';
        if ($state !== '1') {
            $error = $this->mapError($result, $state, '결제 요청에 실패했습니다.');
            return new PaymentGatewayResponse(false, '', errorCode: $error['code'], errorMessage: $error['message']);
        }

        return new PaymentGatewayResponse(
            success: true,
            transactionId: (string) ($result['mul_no'] ?? ''),
            orderNo: $orderId,
            amount: (int) ($orderData['amount'] ?? 0),
            data: ['pg_response' => [
                'status' => 'READY',
                'method' => 'payapp',
                'mul_no' => $result['mul_no'] ?? '',
                'payurl' => $result['payurl'] ?? '',
                'order_id' => $orderId,
            ]],
        );
    }

    /**
     * 결제 검증 — statecheck API 호출
     */
    public function verify(string $transactionId): PaymentGatewayResponse
    {
        $client = $this->createClient();
        $result = $client->stateCheck($transactionId);

        // 조회(payinfo) 응답은 webhook 규격과 필드가 다르다.
        //   상태 usingstate / 금액 goodprice / 승인번호 payauthcode / 결제수단 paytype
        // 상태 코드 체계(4=결제완료 등)는 webhook 의 pay_state 와 같다.
        $payState = (int) ($result['usingstate'] ?? $result['pay_state'] ?? 0);

        // PG가 결제 시점에 바인딩한 주문번호(var1)와 실제 승인금액을 표면화한다.
        // 이 두 값이 표준 키(order_no/amount)로 올라와야 소비 패키지의 주문번호·금액
        // 일치 검증이 실제로 동작한다. (통보 위·변조 방어의 핵심: 공격자가 값싼 결제의
        // mul_no를 재사용해 다른/고액 주문을 결제완료로 뒤집는 것을 차단)
        $boundOrderNo = (string) ($result['var1'] ?? '');
        $paidPrice = $result['goodprice'] ?? $result['price'] ?? $result['pay_price'] ?? null;
        $approvalNo = (string) ($result['payauthcode'] ?? '');

        return new PaymentGatewayResponse(
            success: $payState === 4,
            transactionId: $transactionId,
            orderNo: $boundOrderNo !== '' ? $boundOrderNo : null,
            amount: is_numeric($paidPrice) ? (int) $paidPrice : null,
            status: $this->mapPayState($payState),
            paymentMethod: $this->resolvePaymentMethod($result),
            approvalNo: $approvalNo !== '' ? $approvalNo : null,
            data: [
                'verified_at' => date('Y-m-d H:i:s'),
                'paid_at' => (string) ($result['paydate'] ?? ''),
                'receipt_url' => (string) ($result['cardst_url'] ?? $result['recpt'] ?? ''),
                'pg_response' => $result,
            ],
        );
    }

    /**
     * 결제 취소
     */
    public function cancel(
        string $transactionId,
        int $amount,
        string $reason = '',
        ?int $remainingAmount = null,
    ): PaymentGatewayResponse
    {
        $client = $this->createClient();
        $cancelAmount = $amount > 0 ? $amount : null;
        $result = $client->payCancel($transactionId, $reason ?: '관리자 취소', $cancelAmount);

        $state = $result['state'] ?? '';
        if ($state !== '1') {
            $error = $this->mapError($result, $state, '취소에 실패했습니다.');
            return new PaymentGatewayResponse(false, $transactionId, errorCode: $error['code'], errorMessage: $error['message']);
        }

        return new PaymentGatewayResponse(
            success: true,
            transactionId: $transactionId,
            cancelAmount: $amount,
            data: [
                'reason' => $reason,
                'cancelled_at' => date('Y-m-d H:i:s'),
                'pg_response' => $result,
            ],
        );
    }

    /**
     * 클라이언트 설정
     */
    public function getClientConfig(): array
    {
        $config = $this->configService->getConfig($this->domainId);

        return [
            'mode' => $config['mode'] ?? 'live',
            'sdk_url' => 'https://lite.payapp.kr/public/api/v2/payapp-lite.js',
            'requires_redirect' => false,
        ];
    }

    /**
     * 체크아웃 JS 핸들러
     *
     * PayApp Lite JS를 로드하고 결제창을 호출합니다.
     * prepare()에서 받은 mul_no를 이용하여 결제를 진행합니다.
     */
    public function getCheckoutScript(): ?string
    {
        return <<<'JS'
(function() {
    window.MubloPayHandlers = window.MubloPayHandlers || {};
    window.MubloPayHandlers['payapp'] = function(data) {
        var pg = data.pg_response || {};
        var payurl = pg.payurl || '';
        var successUrl = data.success_url || '';

        // 계약: 사실만 알리고 화면은 건드리지 않는다. 표시·버튼 복원은 소비처가 정한다.
        function signal(status, message) {
            document.dispatchEvent(new CustomEvent('mublo:pay', {
                detail: { gateway: 'payapp', status: status, message: message || '', code: '' }
            }));
        }

        // 결제창을 열 수 있는 정보(payurl 또는 mul_no)가 전혀 없으면 깨진 결제창을 띄우지 않는다.
        // (정상 흐름에선 prepare 실패가 서버에서 차단되어 여기 도달하지 않는다.)
        if (!payurl && !pg.mul_no) {
            signal('fail', '결제 정보를 불러오지 못했습니다.');
            return;
        }

        if (payurl) {
            var width = 500, height = 700;
            var left = (screen.width - width) / 2;
            var top = (screen.height - height) / 2;
            var popup = window.open(payurl, 'payapp_payment',
                'width=' + width + ',height=' + height + ',left=' + left + ',top=' + top + ',scrollbars=yes');

            if (!popup || popup.closed) {
                location.href = payurl;
                return;
            }

            // 팝업 닫힘 감지 → 결제 상태 확인 → 분기
            var statusUrl = data.status_url || '';
            var statusPayload = data.status_payload || {};
            var pollTimer = setInterval(function() {
                if (!popup.closed) return;
                clearInterval(pollTimer);

                if (!statusUrl) {
                    // 상태 확인 URL 없으면 success로 직행 (하위 호환)
                    if (successUrl) location.href = successUrl;
                    else signal('cancel');
                    return;
                }

                // 결제 상태 확인 API 호출 — Shop의 verify 엔드포인트는
                // 성공 시 data.redirect (예: /shop/order/{no}/complete)를 반환한다.
                MubloRequest.requestJson(statusUrl, statusPayload)
                .then(function(json) {
                    var redirect = (json && json.data && json.data.redirect) || '';
                    if (redirect) {
                        location.href = redirect;
                    } else if (successUrl) {
                        location.href = successUrl;
                    } else {
                        signal('fail', '결제가 완료되지 않았습니다.');
                    }
                })
                .catch(function() {
                    signal('fail', '결제가 완료되지 않았습니다.');
                });
            }, 500);
        } else {
            var config = data.client_config || {};
            var sdkUrl = config.sdk_url || 'https://lite.payapp.kr/public/api/v2/payapp-lite.js';

            function doPayment() {
                if (typeof PayApp === 'undefined') {
                    signal('fail', '결제 모듈을 불러오지 못했습니다.');
                    return;
                }
                PayApp.setParam('mul_no', pg.mul_no);
                PayApp.payrequest();
            }

            if (window.PayApp) {
                doPayment();
            } else {
                var script = document.createElement('script');
                script.src = sdkUrl;
                script.onload = doPayment;
                script.onerror = function() {
                    signal('fail', '결제 모듈을 불러오지 못했습니다.');
                };
                document.head.appendChild(script);
            }
        }
    };
})();
JS;
    }

    /**
     * Feedback 검증용 클라이언트 생성
     */
    public function createClient(): PayAppClient
    {
        $config = $this->configService->getConfig($this->domainId);
        return new PayAppClient(
            $config['userid'] ?? '',
            $config['linkkey'] ?? '',
            $config['linkval'] ?? ''
        );
    }

    /**
     * 페이앱 실패 응답을 표준 error 구조로 변환한다.
     *
     * 페이앱은 실패 시 코드(errno)와 사람이 읽을 수 있는 사유(errorMessage)를 내려준다.
     * (예: 결제요청 최소금액 1,000원 미만 → 해당 사유 메시지)
     * 최소금액 같은 정책 판단은 페이앱에 맡기고, 우리는 받은 사유를 그대로 노출한다.
     * 단, errorMessage가 비어 코드만 온 경우(NO-PARAMS 등) 코드를 메시지에 덧붙여 추적 가능하게 한다.
     */
    private function mapError(array $result, string $state, string $fallback): array
    {
        $code = $result['errno'] ?? $result['errorCode'] ?? ($state !== '' ? $state : 'UNKNOWN');
        $message = trim((string) ($result['errorMessage'] ?? ''));

        if ($message === '') {
            $message = $fallback . ' (' . $code . ')';
        }

        return [
            'code' => (string) $code,
            'message' => $message,
        ];
    }

    private function mapPayState(int $state): string
    {
        return match ($state) {
            1 => 'REQUESTED',
            4 => 'DONE',
            8, 32 => 'CANCELLED',
            9, 64 => 'REFUNDED',
            10 => 'WAITING',
            70, 71 => 'PARTIAL_CANCELLED',
            default => 'UNKNOWN',
        };
    }

    /**
     * 페이앱 pay_type 코드를 Shop PaymentMethod enum 값으로 매핑한다.
     *
     * 페이앱 코드(공식 문서 기준):
     *   11 신용카드 / 12 휴대폰 / 13 가상계좌 / 14 카카오페이 / 15 네이버페이
     *   16 토스페이 / 17 페이코 / 18 실시간계좌이체 / 21 무통장입금 / 25 애플페이
     * Shop enum: CARD / PHONE / VBANK / BANK
     * 카카오·네이버·토스·페이코·애플 같은 간편결제는 신용카드 결제에 해당하므로 CARD로 정규화한다.
     */
    private function mapPayType(string $payType): string
    {
        return match ($payType) {
            '12' => 'PHONE',
            '13' => 'VBANK',
            '18', '21' => 'BANK',
            '11', '14', '15', '16', '17', '25' => 'CARD',
            default => 'CARD',
        };
    }

    /**
     * 응답에서 결제수단을 뽑는다.
     *
     * 페이앱은 통보(webhook)와 조회(payinfo)의 코드 체계가 다르다 — 통보는 pay_type
     * 11/12/13…, 조회는 paytype 1,2… 로 온다. 같은 숫자가 다른 뜻이 될 수 있어
     * 숫자만 믿지 않고, 조회 응답이 함께 주는 한글 라벨(paytypestr)을 먼저 본다.
     */
    private function resolvePaymentMethod(array $result): string
    {
        $label = (string) ($result['paytypestr'] ?? $result['paytype_str'] ?? '');

        if ($label !== '') {
            return match (true) {
                str_contains($label, '휴대폰')  => 'PHONE',
                str_contains($label, '가상계좌') => 'VBANK',
                str_contains($label, '계좌'),
                str_contains($label, '이체')    => 'BANK',
                default => 'CARD',
            };
        }

        // 라벨이 없으면 통보 규격의 코드로 간주한다.
        return $this->mapPayType((string) ($result['pay_type'] ?? ''));
    }
}
