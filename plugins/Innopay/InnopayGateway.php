<?php
declare(strict_types=1);

namespace Mublo\Plugin\Innopay;

use Mublo\Contract\Payment\PaymentGatewayInterface;
use Mublo\Contract\Payment\PaymentGatewayResponse;
use Mublo\Plugin\Innopay\Repository\InnopayAttemptRepository;
use Mublo\Plugin\Innopay\Repository\InnopayCancelRepository;
use Mublo\Plugin\Innopay\Service\InnopayConfigService;
use Mublo\Plugin\Innopay\Service\InnopayLogger;

/**
 * 이노페이 결제 게이트웨이
 *
 * 결제 흐름 (리다이렉트형):
 * 1. prepare()          → 결제 시도 기록 + 결제창 파라미터 반환 (이노페이 서버 호출 없음)
 * 2. 프론트 JS           → innopay.goPay() — PC 는 레이어(iframe), 모바일은 전체 페이지 이동
 * 3. returnUrl (GET)     → CallbackController → InnopayReturnService 가 서버에서 승인 API 호출
 * 4. 소비 패키지 검증    → verify() 가 거래조회 API 로 금액·주문번호·상태를 다시 확인
 * 5. cancel()            → 통합취소 API (취소 비밀번호 필요)
 * 6. 결제결과 통보(Noti)  → InnopayNotiService — 복귀가 끊긴 승인 반영, 외부 취소 기록
 */
class InnopayGateway implements PaymentGatewayInterface
{
    public const PG_KEY = 'innopay';

    public const SDK_URL = 'https://pg.innopay.co.kr/tpay/js/v1/innopay.js';

    public const RETURN_PATH = '/innopay/callback/return';

    public const LOG_PATH = '/innopay/callback/log';

    public const NOTI_PATH = '/innopay/callback/noti';

    /** 이노페이 거래 상태 */
    public const STATUS_APPROVED = 0;          // 승인완료
    public const STATUS_CANCELLED_BEFORE = 1;  // 매입 전 취소
    public const STATUS_CANCELLED_AFTER = 2;   // 매입 후 취소

    /** 이노페이 moid 규격: 영문·숫자·-·_ 6~40자 */
    private const MOID_PATTERN = '/^[A-Za-z0-9_-]{6,40}$/';

    /** returnUrl 최대 길이 (이노페이 규격) */
    private const RETURN_URL_MAX = 100;

    /** @var callable|null */
    private $transport;

    public function __construct(
        private InnopayConfigService $configService,
        private InnopayAttemptRepository $attempts,
        private InnopayLogger $logger,
        private InnopayCancelRepository $cancels,
        private int $domainId,
        ?callable $transport = null,
    ) {
        $this->transport = $transport;
    }

    public function prepare(array $orderData): PaymentGatewayResponse
    {
        $config = $this->configService->getConfig($this->domainId);
        $moid = (string) ($orderData['order_no'] ?? '');
        $amount = (int) ($orderData['amount'] ?? 0);

        $fail = function (string $code, string $message) use ($moid, $amount, $orderData): PaymentGatewayResponse {
            $this->logger->log($this->domainId, 'PREPARE', [
                'moid' => $moid,
                'amount' => $amount,
                'level' => 'warning',
                'result_code' => $code,
                'result_msg' => $message,
                'request' => $this->orderDataForLog($orderData),
            ]);
            return new PaymentGatewayResponse(false, '', errorCode: $code, errorMessage: $message);
        };

        if ($config['mid'] === '' || $config['merchant_key'] === '') {
            return $fail('NOT_CONFIGURED', '이노페이 연동 설정이 필요합니다. (MID / Merchant-Key 미등록)');
        }
        if (!preg_match(self::MOID_PATTERN, $moid)) {
            return $fail('INVALID_ORDER_NO', '이노페이에서 사용할 수 없는 주문번호 형식입니다.');
        }
        if ($amount <= 0) {
            return $fail('INVALID_AMOUNT', '결제 금액이 올바르지 않습니다.');
        }

        $baseUrl = rtrim((string) ($orderData['base_url'] ?? ''), '/');
        if ($baseUrl === '') {
            return $fail('NO_BASE_URL', '결제 복귀 주소를 만들 수 없습니다. 사이트 도메인 설정을 확인해주세요.');
        }
        // 결제창을 띄운 도메인과 같은 도메인이어야 인증 결과를 받을 수 있다(이노페이 규격).
        $returnUrl = $baseUrl . self::RETURN_PATH;
        if (strlen($returnUrl) > self::RETURN_URL_MAX) {
            return $fail('RETURN_URL_TOO_LONG', '결제 복귀 주소가 이노페이 허용 길이(100자)를 넘습니다.');
        }

        $payMethod = (string) $config['pay_method'];

        $stored = $this->attempts->prepare($this->domainId, $moid, [
            'consumer' => (string) ($orderData['consumer'] ?? ''),
            'success_url' => (string) ($orderData['success_url'] ?? ''),
            'fail_url' => (string) ($orderData['fail_url'] ?? ''),
            'pay_method' => $payMethod,
            'amount' => $amount,
        ]);
        if (!$stored) {
            return $fail('ALREADY_PROCESSING', '이미 결제가 진행되었거나 처리 중인 주문입니다.');
        }

        $buyerEmail = trim((string) ($orderData['customer_email'] ?? ''));
        if ($buyerEmail === '') {
            $buyerEmail = trim((string) $config['default_buyer_email']);
        }
        if ($buyerEmail === '') {
            // 이노페이 필수값 — 소비처가 주지 않고 기본값도 없으면 사이트 도메인으로 만든다
            $host = (string) (parse_url($baseUrl, PHP_URL_HOST) ?: 'example.com');
            $buyerEmail = 'noreply@' . $host;
        }

        $buyerTel = preg_replace('/\D/', '', (string) ($orderData['customer_phone'] ?? '')) ?: '00000000000';

        // payMethod 가 빈 값이면 이노페이가 결제수단 선택창을 띄운다 — 빈 값도 그대로 보낸다
        $payParams = [
            'payMethod' => $payMethod,
            'mid' => $config['mid'],
            'moid' => $moid,
            'goodsName' => mb_substr(trim((string) ($orderData['order_name'] ?? '')) ?: '상품', 0, 40),
            'goodsCnt' => '1',
            'amt' => (string) $amount,
            'taxFreeAmt' => '0',
            'buyerName' => mb_substr(trim((string) ($orderData['customer_name'] ?? '')) ?: '구매자', 0, 30),
            'buyerTel' => substr($buyerTel, 0, 40),
            'buyerEmail' => mb_substr($buyerEmail, 0, 60),
            'returnUrl' => $returnUrl,
            'currency' => 'KRW',
        ];
        if ((string) $config['logo_url'] !== '') {
            $payParams['logoUrl'] = (string) $config['logo_url'];
        }
        // 가상계좌를 고를 수 있는 결제창이면 입금 기한을 정한다 (안 보내면 이노페이가 당일·익일로 잡는다)
        if (in_array($payMethod, ['', 'VBANK'], true)) {
            $payParams['vBankExpDate'] = date('Ymd', strtotime('+' . max(1, (int) ($config['vbank_days'] ?? 3)) . ' days'));
        }

        $this->logger->log($this->domainId, 'PREPARE', [
            'moid' => $moid,
            'amount' => $amount,
            'result_code' => 'READY',
            'result_msg' => '결제창 파라미터 생성',
            'request' => $this->orderDataForLog($orderData),
            'response' => $payParams,
        ]);

        return new PaymentGatewayResponse(
            success: true,
            // 거래번호(tid)는 결제창 인증이 끝나야 생긴다
            transactionId: '',
            orderNo: $moid,
            amount: $amount,
            data: ['pg_response' => [
                'status' => 'READY',
                'method' => self::PG_KEY,
                'pay_params' => $payParams,
                'log_url' => self::LOG_PATH,
            ]],
        );
    }

    public function verify(string $transactionId): PaymentGatewayResponse
    {
        $result = $this->createClient()->findByTid($transactionId);

        if (($result['success'] ?? false) !== true || !is_array($result['data'] ?? null)) {
            return new PaymentGatewayResponse(
                false,
                $transactionId,
                errorCode: (string) ($result['error']['code'] ?? 'QUERY_FAILED'),
                errorMessage: (string) ($result['error']['message'] ?? '이노페이 거래 조회에 실패했습니다.'),
                data: ['pg_response' => self::stripMeta($result)],
            );
        }

        $data = $result['data'];
        $status = isset($data['status']) ? (int) $data['status'] : -1;

        // 전체취소된 거래는 amt 가 빠지고 cancel.amt 만 온다 — 금액을 추측하지 않고 null 로 둔다
        $amount = isset($data['amt']) && is_numeric($data['amt'])
            ? (int) $data['amt'] + (int) ($data['taxFreeAmt'] ?? 0)
            : null;

        $moid = (string) ($data['moid'] ?? '');
        $approvalNo = (string) ($data['authNum'] ?? '');

        // 가상계좌는 승인(status 0)이 "계좌 발급"일 뿐 입금이 아니다. 입금은 결제결과 통보로만 알 수
        // 있으므로, 통보를 거래조회로 확인해 결제 시도를 DEPOSITED 로 옮긴 경우에만 결제 완료로 본다.
        // (소비 패키지의 클라이언트 검증 경로로 발급만 된 계좌를 결제 완료로 뒤집지 못하게 한다)
        $isVbank = self::isVirtualAccount((string) ($data['payMethod'] ?? ''));
        $deposited = $isVbank && $this->isDepositConfirmed($moid, (string) ($data['tid'] ?? $transactionId));
        $paid = $status === self::STATUS_APPROVED && (!$isVbank || $deposited);
        $error = match (true) {
            $paid => null,
            $isVbank && $status === self::STATUS_APPROVED => '가상계좌 입금이 확인되지 않았습니다.',
            default => '승인 상태가 아닌 거래입니다. (' . self::mapStatus($status) . ')',
        };

        return new PaymentGatewayResponse(
            success: $paid,
            transactionId: (string) ($data['tid'] ?? $transactionId),
            orderNo: $moid !== '' ? $moid : null,
            amount: $amount,
            status: $isVbank && !$paid && $status === self::STATUS_APPROVED ? 'WAITING_FOR_DEPOSIT' : self::mapStatus($status),
            paymentMethod: self::mapPayMethod((string) ($data['payMethod'] ?? '')),
            approvalNo: $approvalNo !== '' ? $approvalNo : null,
            errorMessage: $error,
            data: [
                'verified_at' => date('Y-m-d H:i:s'),
                'paid_at' => (string) ($data['approvedAt'] ?? ''),
                'receipt_url' => (string) ($data['receiptUrl'] ?? ''),
                // 간편결제는 card 대신 epay 객체(epayCl·매입 카드사)로 온다
                'card_company' => (string) ($data['card']['fnName'] ?? $data['epay']['acquCardName'] ?? ''),
                'card_number' => (string) ($data['card']['cardNum'] ?? ''),
                'epay_type' => self::epayLabel((string) ($data['epay']['epayCl'] ?? '')),
                'installment' => (int) ($data['card']['cardQuota'] ?? 0),
                'pg_response' => $data,
            ],
        );
    }

    public function cancel(
        string $transactionId,
        int $amount,
        string $reason = '',
        ?int $remainingAmount = null,
    ): PaymentGatewayResponse {
        $config = $this->configService->getConfig($this->domainId);
        if ($config['cancel_pwd'] === '') {
            $this->logger->log($this->domainId, 'CANCEL', [
                'tid' => $transactionId, 'amount' => $amount, 'level' => 'warning',
                'result_code' => 'NOT_CONFIGURED', 'result_msg' => '취소 비밀번호 미등록',
            ]);
            return new PaymentGatewayResponse(false, $transactionId, errorCode: 'NOT_CONFIGURED',
                errorMessage: '이노페이 취소 비밀번호가 설정되지 않았습니다. 관리자 설정 또는 이노페이 상점관리에서 취소해주세요.');
        }

        $client = $this->createClient();

        // 결제수단(svcCd)과 남은 금액을 알아야 하므로 먼저 조회한다
        $query = $client->findByTid($transactionId);
        $data = is_array($query['data'] ?? null) ? $query['data'] : null;
        if (($query['success'] ?? false) !== true || $data === null) {
            return new PaymentGatewayResponse(false, $transactionId,
                errorCode: (string) ($query['error']['code'] ?? 'QUERY_FAILED'),
                errorMessage: '취소 전 거래 조회에 실패했습니다: ' . (string) ($query['error']['message'] ?? '응답 없음'));
        }

        $moid = (string) ($data['moid'] ?? '');
        $payMethod = (string) ($data['payMethod'] ?? '');
        $svcCd = self::serviceCode($payMethod);
        if ($svcCd === null) {
            return new PaymentGatewayResponse(false, $transactionId, errorCode: 'UNSUPPORTED_METHOD',
                errorMessage: "자동 취소를 지원하지 않는 결제수단입니다({$payMethod}). 이노페이 상점관리에서 취소해주세요.");
        }

        if ((int) ($data['status'] ?? -1) !== self::STATUS_APPROVED) {
            return new PaymentGatewayResponse(false, $transactionId, errorCode: 'NOT_CANCELLABLE',
                errorMessage: '이미 취소되었거나 취소할 수 없는 거래입니다.');
        }

        // 남은 금액 — 소비처가 알려주면 그 값을, 아니면 조회 결과(원금 - 부분취소 합계)로 계산한다
        $remaining = $remainingAmount;
        if ($remaining === null && isset($data['amt'])) {
            $cancelled = 0;
            foreach ((array) ($data['partialCancels'] ?? []) as $pc) {
                $cancelled += (int) ($pc['amt'] ?? 0);
            }
            $remaining = (int) $data['amt'] + (int) ($data['taxFreeAmt'] ?? 0) - $cancelled;
        }

        $cancelAmount = $amount > 0 ? $amount : (int) $remaining;
        if ($cancelAmount <= 0) {
            return new PaymentGatewayResponse(false, $transactionId, errorCode: 'INVALID_AMOUNT', errorMessage: '취소 금액이 올바르지 않습니다.');
        }
        $partial = $remaining !== null && $cancelAmount < $remaining;

        $result = $client->cancel($transactionId, $svcCd, $cancelAmount, $partial, $reason, $moid);
        $code = (string) ($result['resultCode'] ?? '');

        if ($code !== InnopayClient::CANCEL_SUCCESS_CODE) {
            $message = trim((string) ($result['resultMsg'] ?? ''));
            if ($message === '') {
                $message = ($result['_error'] ?? '') !== '' ? '취소 요청 전송 오류' : '취소에 실패했습니다.';
            }
            return new PaymentGatewayResponse(false, $transactionId, errorCode: $code !== '' ? $code : 'CANCEL_FAILED',
                errorMessage: $message . ($code !== '' ? " ({$code})" : ''), data: ['pg_response' => self::stripMeta($result)]);
        }

        $cancelled = is_numeric($result['pgApprovalAmt'] ?? null) ? (int) $result['pgApprovalAmt'] : $cancelAmount;

        // 이 취소는 곧 결제결과 통보(Noti)로 되돌아온다 — 우리가 한 취소임을 적어 두어
        // 소비 패키지에 외부 환불로 한 번 더 기록되지 않게 한다.
        try {
            $this->cancels->recordApi($this->domainId, $moid, $transactionId, $cancelled, (string) ($result['pgCancelTid'] ?? ''));
        } catch (\Throwable $e) {
            error_log('[Innopay] 취소 기록 실패 tid=' . $transactionId . ': ' . $e->getMessage());
        }

        return new PaymentGatewayResponse(
            success: true,
            transactionId: $transactionId,
            orderNo: $moid !== '' ? $moid : null,
            cancelAmount: $cancelled,
            status: $partial ? 'PARTIAL_CANCELLED' : 'CANCELLED',
            data: [
                'reason' => $reason,
                'partial' => $partial,
                'cancel_tid' => (string) ($result['pgCancelTid'] ?? ''),
                'cancelled_at' => date('Y-m-d H:i:s'),
                'pg_response' => self::stripMeta($result),
            ],
        );
    }

    public function getClientConfig(): array
    {
        return [
            'sdk_url' => self::SDK_URL,
            'requires_redirect' => true,
        ];
    }

    /**
     * 체크아웃 JS 핸들러
     *
     * 주의 — 이노페이 SDK 의 성질:
     *  - 최상위에 const innopay 로 선언되어 window 속성이 아니다. 존재 확인은 typeof 로 하고,
     *    두 번 불러오면 재선언 오류가 나므로 한 번만 불러온다.
     *  - 불러오는 순간 문서 전체의 Enter 키를 막는다. 그래서 체크아웃 페이지에 미리 넣지 않고
     *    결제 버튼을 누른 뒤에만 불러온다.
     *  - 결제가 끝나면 SDK 가 최상위 창을 returnUrl 로 옮긴다(리다이렉트형). 완료 신호는
     *    서버 복귀 경로가 처리하고, 여기서는 닫기(cancel)와 실패(fail)만 알린다.
     */
    public function getCheckoutScript(): ?string
    {
        $sdk = json_encode(self::SDK_URL, JSON_UNESCAPED_SLASHES);

        return <<<JS
(function() {
    window.MubloPayHandlers = window.MubloPayHandlers || {};
    var SDK_URL = {$sdk};
    var sdkLoading = null;

    function signal(status, message, orderNo) {
        document.dispatchEvent(new CustomEvent('mublo:pay', {
            detail: { gateway: 'innopay', status: status, message: message || '', code: '', order_no: orderNo || '' }
        }));
    }

    function clientLog(url, moid, event, message) {
        if (!url || !moid) return;
        try {
            fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                keepalive: true,
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ moid: moid, event: event, message: String(message || '').slice(0, 200) })
            }).catch(function() {});
        } catch (e) {}
    }

    function sdkReady() {
        return typeof innopay !== 'undefined' && innopay && typeof innopay.goPay === 'function';
    }

    function loadSdk() {
        if (sdkReady()) return Promise.resolve();
        if (sdkLoading) return sdkLoading;
        sdkLoading = new Promise(function(resolve, reject) {
            var s = document.createElement('script');
            s.src = SDK_URL;
            s.onload = function() { sdkReady() ? resolve() : reject(new Error('SDK 초기화 실패')); };
            s.onerror = function() { sdkLoading = null; reject(new Error('SDK 로드 실패')); };
            document.head.appendChild(s);
        });
        return sdkLoading;
    }

    window.MubloPayHandlers['innopay'] = function(data) {
        var pg = (data && data.pg_response) || {};
        var params = pg.pay_params || null;
        var logUrl = pg.log_url || '';

        if (!params || !params.moid) {
            signal('fail', '결제 정보를 불러오지 못했습니다.', data && data.order_no);
            return;
        }
        var moid = params.moid;

        loadSdk().then(function() {
            var closed = false;
            // SDK 는 결제창마다 message 리스너를 덧붙이므로 같은 결제에서 두 번 불릴 수 있다
            innopay.closeHandler = function() {
                if (closed) return;
                closed = true;
                clientLog(logUrl, moid, 'CLIENT_CLOSE', '결제창 닫힘');
                signal('cancel', '', moid);
            };
            clientLog(logUrl, moid, 'CLIENT_OPEN', (params.payMethod || 'SELECT') + ' / ' + navigator.userAgent);
            try {
                innopay.goPay(params);
            } catch (e) {
                clientLog(logUrl, moid, 'CLIENT_ERROR', 'goPay 오류: ' + (e && e.message));
                signal('fail', '결제창을 열지 못했습니다.', moid);
            }
        }).catch(function(e) {
            clientLog(logUrl, moid, 'CLIENT_ERROR', (e && e.message) || 'SDK 로드 실패');
            signal('fail', '결제 모듈을 불러오지 못했습니다.', moid);
        });
    };
})();
JS;
    }

    public function createClient(): InnopayClient
    {
        $config = $this->configService->getConfig($this->domainId);

        return new InnopayClient(
            (string) $config['mid'],
            (string) $config['merchant_key'],
            (string) $config['cancel_pwd'],
            $this->logger,
            $this->domainId,
            $this->transport,
        );
    }

    public static function isVirtualAccount(string $payMethod): bool
    {
        return strtoupper($payMethod) === 'VBANK';
    }

    /** 결제결과 통보로 입금이 확인된 가상계좌 결제인가 (플러그인이 기록한 사실 기준) */
    private function isDepositConfirmed(string $moid, string $tid): bool
    {
        $attempt = $moid !== '' ? $this->attempts->find($this->domainId, $moid) : null;

        return $attempt !== null
            && in_array((string) $attempt['status'], [InnopayAttemptRepository::DEPOSITED, InnopayAttemptRepository::COMPLETED], true)
            && (string) ($attempt['tid'] ?? '') === $tid;
    }

    public static function mapStatus(int $status): string
    {
        return match ($status) {
            self::STATUS_APPROVED => 'DONE',
            self::STATUS_CANCELLED_BEFORE, self::STATUS_CANCELLED_AFTER => 'CANCELLED',
            default => 'UNKNOWN',
        };
    }

    /**
     * 이노페이 결제수단 → Shop PaymentMethod (CARD / BANK / VBANK)
     * 간편결제·해외카드는 카드 결제로 본다.
     */
    public static function mapPayMethod(string $payMethod): string
    {
        return match (strtoupper($payMethod)) {
            'BANK', 'EBANK' => 'BANK',
            'VBANK' => 'VBANK',
            default => 'CARD',
        };
    }

    /**
     * 통합취소 서비스 구분 (01 신용카드, 02 계좌이체).
     * 가상계좌는 환불 계좌가 필요해 자동 취소 대상에서 뺀다.
     * 간편결제(EPAY)는 카드 승인으로 처리되므로 01 로 보낸다.
     */
    public static function serviceCode(string $payMethod): ?string
    {
        return match (strtoupper($payMethod)) {
            'CARD', 'OPCARD', 'EPAY' => '01',
            'BANK', 'EBANK' => '02',
            default => null,
        };
    }

    /**
     * 간편결제 종류 (epay.epayCl). SSG 는 결제창 요청 때 0408(신용)·0409(머니)로 나뉜다.
     */
    public static function epayLabel(string $epayCl): string
    {
        return match ($epayCl) {
            '' => '',
            '01' => '카카오페이',
            '02' => 'L.PAY',
            '03' => 'PAYCO',
            '04', '0408', '0409' => 'SSG페이',
            '05' => '토스페이',
            '06' => '네이버페이',
            default => $epayCl,
        };
    }

    public static function stripMeta(array $result): array
    {
        unset($result['_http_code'], $result['_error'], $result['_ambiguous']);
        return $result;
    }

    private function orderDataForLog(array $orderData): array
    {
        return array_intersect_key($orderData, array_flip([
            'order_no', 'amount', 'payment_method', 'order_name', 'customer_name',
            'customer_phone', 'consumer', 'success_url', 'fail_url',
        ]));
    }
}
