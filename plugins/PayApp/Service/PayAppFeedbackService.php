<?php
declare(strict_types=1);

namespace Mublo\Plugin\PayApp\Service;

use Mublo\Contract\Payment\PaymentConsumerInterface;
use Mublo\Contract\Payment\PaymentGatewayResponse;
use Mublo\Contract\Payment\PreparedPayment;
use Mublo\Core\Registry\ContractRegistry;
use Mublo\Plugin\PayApp\PayAppClient;
use Mublo\Plugin\PayApp\PayAppGateway;

/**
 * 결제 통보(feedbackurl) 처리
 *
 * 페이앱은 결제 결과를 서버→서버 POST 로 알려 준다. 승인 권위가 이 통보에 있어,
 * 여기서 놓친 결제는 "돈은 빠졌는데 주문이 없는" 상태가 된다.
 *
 *   인증(설정 존재·IP·userid/linkkey/linkval·hash)
 *     ├ 4  결제완료   → 소비 패키지 검증(verifyPayment)
 *     │                 실패 → 이미 결제됐는지 재확인 → PG 재조회 → 자동 취소 → 못 하면 CRITICAL
 *     ├ 10 결제대기   → 가상계좌 발급. 받지 않는 수단이라 발급을 취소한다
 *     ├ 9/64 승인취소 → PG 재조회로 확인 후 소비 패키지에 환불 기록
 *     ├ 70/71 부분취소 → 위와 같음(부분)
 *     └ 1/8/16/31/32  → 요청·요청취소 — 결제 전이라 기록할 것이 없다
 *
 * 응답 원칙: 일시 오류(예외·PG 조회 불가)는 FAIL 로 재전송을 받고, 다시 보내도 결과가
 * 같은 실패는 조치(자동 취소·CRITICAL 로그)를 남기고 SUCCESS 로 끝낸다.
 */
class PayAppFeedbackService
{
    /** 결제 완료 */
    public const STATE_PAID = 4;

    /** 결제 대기 — 가상계좌 발급(입금 전) */
    public const STATE_WAITING = 10;

    /** 승인 취소(결제 완료 후 전체 취소) */
    public const STATES_FULL_CANCEL = [9, 64];

    /** 부분 취소 */
    public const STATES_PARTIAL_CANCEL = [70, 71];

    /** 요청 / 요청 취소 — 결제가 일어나지 않은 상태 */
    public const STATES_UNPAID = [1, 8, 16, 31, 32];

    /** @var callable(int): PayAppGateway */
    private $gatewayFactory;

    /** @var callable(string): void */
    private $logger;

    /**
     * @param callable(int): PayAppGateway $gatewayFactory 도메인별 게이트웨이
     * @param (callable(string): void)|null $logger 로그 출력(생략하면 error_log)
     */
    public function __construct(
        private PayAppConfigService $configService,
        private ContractRegistry $registry,
        callable $gatewayFactory,
        ?callable $logger = null,
    ) {
        $this->gatewayFactory = $gatewayFactory;
        $this->logger = $logger ?? static function (string $line): void {
            error_log($line);
        };
    }

    /**
     * @param array<string, mixed> $data        통보 본문
     * @param string               $consumerKey feedbackurl 에 실어 보낸 소비자 키(by)
     */
    public function handle(array $data, string $consumerKey, int $contextDomainId, string $clientIp): PayAppFeedbackOutcome
    {
        $mulNo = trim((string) ($data['mul_no'] ?? ''));
        $payState = (int) ($data['pay_state'] ?? 0);
        $orderNo = trim((string) ($data['var1'] ?? ''));
        $domainIdFromVar = (int) ($data['var2'] ?? 0);
        $domainId = $domainIdFromVar > 0 ? $domainIdFromVar : $contextDomainId;
        $tag = "mul_no={$mulNo}, order={$orderNo}, domain={$domainId}, pay_state={$payState}";

        // ── 인증 ──────────────────────────────────────────────────────────
        // 도메인은 통보 본문(var2)에서 온다. 설정이 없는 도메인을 지목하면 비교할 비밀이
        // 없으므로 검증 이전에 거부한다(빈 값 비교는 참이 된다).
        $config = $this->configService->getConfig($domainId);
        if (!PayAppConfigService::hasCredentials($config)) {
            $this->log('warning', "연동 정보가 없는 도메인의 통보 거부: {$tag}, ip={$clientIp}");
            return PayAppFeedbackOutcome::reject('NOT_CONFIGURED');
        }

        if (!self::isAllowedIp($clientIp, (string) ($config['feedback_ip_allowlist'] ?? ''))) {
            $this->log('warning', "IP 허용목록 밖의 통보 거부: {$tag}, ip={$clientIp}");
            return PayAppFeedbackOutcome::reject('IP_DENIED');
        }

        $gateway = ($this->gatewayFactory)($domainId);
        if (!$gateway->createClient()->verifyFeedback($data)) {
            $this->log('warning', "통보 검증 실패(userid/linkkey/linkval/hash 불일치): {$tag}, ip={$clientIp}");
            return PayAppFeedbackOutcome::reject('VERIFY_FAILED');
        }

        // ── 결제 전 상태 — 기록할 것이 없다 ─────────────────────────────────
        if (in_array($payState, self::STATES_UNPAID, true)) {
            return PayAppFeedbackOutcome::ok('UNPAID_STATE');
        }

        if ($payState === self::STATE_WAITING) {
            return $this->handleVirtualAccountWaiting($gateway, $mulNo, $tag);
        }

        if ($payState !== self::STATE_PAID
            && !in_array($payState, self::STATES_FULL_CANCEL, true)
            && !in_array($payState, self::STATES_PARTIAL_CANCEL, true)
        ) {
            return PayAppFeedbackOutcome::ok('IGNORED_STATE');
        }

        // 주문 반영은 소비 패키지가 한다(계약). 플러그인은 어느 패키지인지 알지 못한다.
        $consumer = $this->resolveConsumer($consumerKey);
        if ($consumer === null) {
            $this->log('error', "결제 소비자를 찾을 수 없습니다(by={$consumerKey}): {$tag}");
            return PayAppFeedbackOutcome::retry('NO_CONSUMER');
        }

        if ($payState === self::STATE_PAID) {
            return $this->handlePaid($consumer, $gateway, $domainId, $orderNo, $mulNo, $data, $tag);
        }

        return $this->handleRefund(
            $consumer, $gateway, $domainId, $orderNo, $mulNo, $data,
            in_array($payState, self::STATES_FULL_CANCEL, true), $tag
        );
    }

    /**
     * 가상계좌 발급 통보 — 받지 않는 결제수단이므로 발급을 취소한다.
     *
     * 결제창에서 가상계좌를 빼 두었지만(openpaytype), 판매자 설정이 우선하는 등 빠져나올
     * 여지가 있다. 그대로 두면 주문은 결제시도 상태로 스윕되고, 나중에 입금만 들어온다.
     */
    private function handleVirtualAccountWaiting(PayAppGateway $gateway, string $mulNo, string $tag): PayAppFeedbackOutcome
    {
        if ($mulNo === '') {
            $this->log('warning', "가상계좌 발급 통보에 결제요청번호 없음: {$tag}");
            return PayAppFeedbackOutcome::ok('VBANK_NO_MULNO');
        }

        try {
            $cancel = $gateway->cancel($mulNo, 0, '가상계좌 미지원 — 발급 취소');
        } catch (\Throwable $e) {
            $cancel = new PaymentGatewayResponse(false, $mulNo, errorCode: 'EXCEPTION', errorMessage: $e->getMessage());
        }

        if ($cancel->success) {
            $this->log('warning', "가상계좌는 받지 않는 결제수단이라 발급을 취소했습니다: {$tag}");
            return PayAppFeedbackOutcome::ok('VBANK_CANCELLED');
        }

        // 취소를 못 했어도 SUCCESS — 입금이 들어오면 결제완료(4) 통보가 자동 취소를 다시 시도한다.
        $this->log('error', "가상계좌 발급 취소 실패 — 입금되면 결제완료 통보에서 다시 취소를 시도합니다: {$tag}, "
            . ($cancel->errorCode ?? '') . ' ' . ($cancel->errorMessage ?? ''));
        return PayAppFeedbackOutcome::ok('VBANK_CANCEL_FAILED');
    }

    /**
     * 결제 완료 통보 — 소비 패키지가 검증하고 주문을 확정한다.
     *
     * @param array<string, mixed> $data
     */
    private function handlePaid(
        PaymentConsumerInterface $consumer,
        PayAppGateway $gateway,
        int $domainId,
        string $orderNo,
        string $mulNo,
        array $data,
        string $tag,
    ): PayAppFeedbackOutcome {
        $isVbank = PayAppGateway::isVirtualAccountFeedback($data);
        if ($isVbank) {
            $tag .= ', 가상계좌 입금(' . trim((string) ($data['vbank'] ?? '')) . ' ' . trim((string) ($data['vbankno'] ?? '')) . ')';
        }

        try {
            $result = $consumer->verifyPayment($domainId, $orderNo, PayAppGateway::PG_KEY, $mulNo);
        } catch (\Throwable $e) {
            // DB 장애 등 일시 오류 — 재전송받는다(verifyPayment 는 멱등)
            $this->log('error', "결제 검증 중 예외 — 재전송 대기: {$tag}, {$e->getMessage()}");
            return PayAppFeedbackOutcome::retry('VERIFY_EXCEPTION');
        }

        if ($result->isSuccess()) {
            return PayAppFeedbackOutcome::ok('PAID');
        }

        $message = $result->getMessage();
        $this->log('error', "소비 패키지 결제 검증 실패: {$tag}, {$message}");

        // 실패로 보였지만 다른 경로(결제창 닫힘 후 상태 확인)가 이미 결제 완료로 반영했을 수 있다
        try {
            $prepared = $orderNo !== '' ? $consumer->findPreparedPayment($domainId, $orderNo) : null;
        } catch (\Throwable $e) {
            $this->log('error', "주문 상태 재확인 중 예외 — 재전송 대기: {$tag}, {$e->getMessage()}");
            return PayAppFeedbackOutcome::retry('PREPARED_EXCEPTION');
        }
        if ($prepared !== null && $prepared->paid) {
            return PayAppFeedbackOutcome::ok('ALREADY_PAID');
        }

        // 페이앱에 직접 물어 실제로 결제된 건인지, 이 주문의 결제인지 확인한다
        $check = $this->queryPayment($gateway, $mulNo);
        $pgState = $check !== null ? self::pgState($check) : null;
        if ($check === null || $pgState === null) {
            $this->log('error', "자동 취소 전 결제 조회 실패 — 재전송 대기: {$tag}");
            return PayAppFeedbackOutcome::retry('QUERY_FAILED');
        }

        if ($pgState !== self::STATE_PAID) {
            $this->log('warning', "페이앱 조회상 결제완료가 아님(usingstate={$pgState}) — 취소할 결제 없음: {$tag}");
            return PayAppFeedbackOutcome::ok('NOT_PAID_AT_PG');
        }

        if ($orderNo === '' || $check->orderNo !== $orderNo) {
            // 이 사이트가 만든 결제인지 확인할 수 없다 — 남의 결제를 취소하지 않는다
            $this->log('critical', "결제완료이나 주문번호 대조 실패(조회 var1=" . ($check->orderNo ?? '') . ") — 자동 취소하지 않음, 확인 필요: {$tag}, {$message}");
            return PayAppFeedbackOutcome::ok('ORDER_MISMATCH');
        }

        // 소비 패키지 쪽 PG 조회만 순간적으로 실패했을 수 있다 — 페이앱이 결제완료라고 답한
        // 지금 한 번 더 검증해 보고, 그래도 안 될 때만 취소한다(정상 결제를 취소하지 않도록).
        try {
            $second = $consumer->verifyPayment($domainId, $orderNo, PayAppGateway::PG_KEY, $mulNo);
            if ($second->isSuccess()) {
                $this->log('warning', "재검증으로 결제 반영: {$tag}");
                return PayAppFeedbackOutcome::ok('PAID_ON_RECHECK');
            }
            $message = $second->getMessage() ?: $message;
        } catch (\Throwable $e) {
            $this->log('error', "결제 재검증 중 예외 — 재전송 대기: {$tag}, {$e->getMessage()}");
            return PayAppFeedbackOutcome::retry('VERIFY_EXCEPTION');
        }

        // 돈은 빠졌는데 주문 반영이 안 된다 — 전액 취소한다
        try {
            $cancel = $gateway->cancel($mulNo, 0, '주문 반영 실패 자동취소');
        } catch (\Throwable $e) {
            $cancel = new PaymentGatewayResponse(false, $mulNo, errorCode: PayAppClient::TRANSPORT_ERROR, errorMessage: $e->getMessage());
        }

        if ($cancel->success) {
            $this->log('warning', "주문 반영 실패로 결제를 자동 취소했습니다: {$tag}, 사유: {$message}");
            return PayAppFeedbackOutcome::ok('AUTO_CANCELLED');
        }

        $cancelError = trim(($cancel->errorCode ?? '') . ' ' . ($cancel->errorMessage ?? ''));
        $this->log('critical', ($isVbank ? '가상계좌 입금이 주문 없이 들어왔고' : '결제는 승인됐으나 주문 반영 실패,')
            . " 자동 취소도 실패 — 관리자가 페이앱에서 환불해야 합니다: {$tag}, 반영 실패: {$message}, 취소 실패: {$cancelError}");

        // 통신 실패면 재전송으로 취소를 다시 시도할 수 있다. 페이앱이 거절한 취소는 다시 해도 같다.
        return $cancel->errorCode === PayAppClient::TRANSPORT_ERROR
            ? PayAppFeedbackOutcome::retry('AUTO_CANCEL_TRANSPORT')
            : PayAppFeedbackOutcome::ok('NEEDS_ACTION');
    }

    /**
     * 취소·환불 통보 — PG 에서 확인한 뒤 소비 패키지에 기록을 위임한다(계약).
     *
     * @param array<string, mixed> $data
     */
    private function handleRefund(
        PaymentConsumerInterface $consumer,
        PayAppGateway $gateway,
        int $domainId,
        string $orderNo,
        string $mulNo,
        array $data,
        bool $fullCancel,
        string $tag,
    ): PayAppFeedbackOutcome {
        if ($orderNo === '' || $mulNo === '') {
            $this->log('error', 'Refund webhook missing order/mul_no: ' . json_encode(PayAppClient::maskSecrets($data), JSON_UNESCAPED_UNICODE));
            return PayAppFeedbackOutcome::ok('REFUND_MALFORMED');
        }

        // 부분취소 통보는 자기 결제요청번호를 갖고, 원거래 번호는 orig_mul_no 로 온다(문서)
        $origMulNo = trim((string) ($data['orig_mul_no'] ?? '')) ?: $mulNo;

        $amount = self::extractCancelAmount($data, $fullCancel);
        if (!$fullCancel && $amount <= 0) {
            $this->log('error', "부분취소 통보에 금액 없음: {$tag}");
            return PayAppFeedbackOutcome::ok('REFUND_NO_AMOUNT');
        }

        // 소비 패키지가 아는 주문의 결제수단이 페이앱이 아니면 기록하지 않는다
        try {
            $prepared = $consumer->findPreparedPayment($domainId, $orderNo);
        } catch (\Throwable $e) {
            $this->log('error', "환불 통보 처리 중 주문 조회 예외 — 재전송 대기: {$tag}, {$e->getMessage()}");
            return PayAppFeedbackOutcome::retry('PREPARED_EXCEPTION');
        }
        if ($prepared instanceof PreparedPayment && $prepared->gateway !== '' && $prepared->gateway !== PayAppGateway::PG_KEY) {
            $this->log('critical', "환불 통보의 주문이 페이앱 결제가 아님(gateway={$prepared->gateway}) — 기록하지 않음: {$tag}");
            return PayAppFeedbackOutcome::ok('REFUND_GATEWAY_MISMATCH');
        }

        // 페이앱에 직접 물어 이 결제가 이 주문의 것이고 실제로 취소됐는지 확인한다
        $check = $this->queryPayment($gateway, $origMulNo);
        $pgState = $check !== null ? self::pgState($check) : null;
        if ($check === null || $pgState === null) {
            $this->log('error', "환불 통보 확인용 결제 조회 실패 — 재전송 대기: {$tag}, orig_mul_no={$origMulNo}");
            return PayAppFeedbackOutcome::retry('QUERY_FAILED');
        }
        if ($check->orderNo !== $orderNo) {
            $this->log('critical', "환불 통보의 결제가 이 주문의 것이 아님(조회 var1=" . ($check->orderNo ?? '') . ") — 기록하지 않음: {$tag}");
            return PayAppFeedbackOutcome::ok('REFUND_ORDER_MISMATCH');
        }
        $acceptable = $fullCancel
            ? self::STATES_FULL_CANCEL
            : array_merge([self::STATE_PAID], self::STATES_FULL_CANCEL, self::STATES_PARTIAL_CANCEL);
        if (!in_array($pgState, $acceptable, true)) {
            // 조회 반영이 늦었을 수 있어 재전송을 받는다(위조라면 FAIL 이 나갈 뿐이다)
            $this->log('warning', "페이앱 조회상 취소 상태가 아님(usingstate={$pgState}) — 재전송 대기: {$tag}");
            return PayAppFeedbackOutcome::retry('NOT_CANCELLED_AT_PG');
        }

        $reason = (string) ($data['cancelmemo'] ?? $data['cancel_reason'] ?? '') ?: 'PG 통보 취소';
        // 전체 취소면 잔여액을 그대로 환불 처리 (구현체가 0보다 큰 값을 요구하므로 sentinel)
        $effectiveAmount = $amount > 0 ? $amount : PHP_INT_MAX;

        try {
            $result = $consumer->recordExternalRefund(
                $domainId, $orderNo, PayAppGateway::PG_KEY, $mulNo, $effectiveAmount, $reason, $fullCancel
            );
        } catch (\Throwable $e) {
            $this->log('error', "환불 기록 중 예외 — 재전송 대기: {$tag}, {$e->getMessage()}");
            return PayAppFeedbackOutcome::retry('REFUND_EXCEPTION');
        }

        if ($result->isFailure()) {
            // 페이앱에선 환불됐는데 장부엔 없다. 재전송(최대 10회)으로 다시 기회를 준다 —
            // 중복 기록은 소비 패키지의 멱등 가드가 막는다.
            $this->log('critical', "환불 기록 실패 — PG 는 환불됐으나 주문 장부에 없음: {$tag}, {$result->getMessage()}");
            return PayAppFeedbackOutcome::retry('REFUND_RECORD_FAILED');
        }

        return PayAppFeedbackOutcome::ok('REFUND_RECORDED');
    }

    private function queryPayment(PayAppGateway $gateway, string $mulNo): ?PaymentGatewayResponse
    {
        if ($mulNo === '') {
            return null;
        }
        try {
            return $gateway->verify($mulNo);
        } catch (\Throwable $e) {
            $this->log('error', "결제 조회 예외: mul_no={$mulNo}, {$e->getMessage()}");
            return null;
        }
    }

    /**
     * 조회 응답의 원본 상태 코드. 응답에 상태가 없으면(통신 실패·오류 응답) null.
     */
    private static function pgState(PaymentGatewayResponse $response): ?int
    {
        $raw = $response->data['pg_response'] ?? [];
        if (!is_array($raw)) {
            return null;
        }
        $state = $raw['usingstate'] ?? $raw['pay_state'] ?? null;
        return is_numeric($state) ? (int) $state : null;
    }

    /**
     * 취소 금액 추출. 문서 예제는 부분취소 통보의 price 를 취소 금액으로 쓴다
     * (원거래 금액은 orig_price). 알려진 다른 키도 먼저 시도한다.
     *
     * @param array<string, mixed> $data
     */
    private static function extractCancelAmount(array $data, bool $fullCancel): int
    {
        $keys = ['cancelprice', 'cancel_price', 'cancelamount', 'cancel_amount', 'cancelAmt'];
        if (!$fullCancel) {
            $keys[] = 'price';
        }
        foreach ($keys as $key) {
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
     * 결제 결과를 되돌려줄 소비 패키지 조회 (계약: PaymentConsumerInterface)
     *
     * 레지스트리에 등록된 키일 때만 유효하다 — 알 수 없으면 결과를 아무 데도
     * 반영하지 않는다(fail-closed).
     */
    private function resolveConsumer(string $key): ?PaymentConsumerInterface
    {
        if ($key === '' || !$this->registry->hasKey(PaymentConsumerInterface::class, $key)) {
            return null;
        }

        try {
            $consumer = $this->registry->get(PaymentConsumerInterface::class, $key);
        } catch (\Throwable $e) {
            return null;
        }

        return $consumer instanceof PaymentConsumerInterface ? $consumer : null;
    }

    /**
     * IP allowlist 검증. 비어 있으면 모두 허용한다. CIDR 표기(예: 1.2.3.0/24)도 지원.
     */
    public static function isAllowedIp(string $clientIp, string $allowlist): bool
    {
        $raw = trim($allowlist);
        if ($raw === '') {
            return true;
        }
        if ($clientIp === '') {
            return false;
        }

        foreach (preg_split('/[\s,]+/', $raw) ?: [] as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            if (str_contains($entry, '/')) {
                if (self::ipInCidr($clientIp, $entry)) {
                    return true;
                }
            } elseif ($entry === $clientIp) {
                return true;
            }
        }

        return false;
    }

    private static function ipInCidr(string $ip, string $cidr): bool
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

    private function log(string $level, string $message): void
    {
        $prefix = $level === 'critical' ? '[PayApp][CRITICAL] ' : '[PayApp] ';
        ($this->logger)($prefix . $message);
    }
}
