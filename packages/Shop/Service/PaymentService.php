<?php

namespace Mublo\Packages\Shop\Service;

use Mublo\Core\Result\Result;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Core\Event\EventInterface;
use Mublo\Core\Registry\ContractRegistry;
use Mublo\Core\Registry\RegistryNotFoundException;
use Mublo\Packages\Shop\Repository\OrderRepository;
use Mublo\Packages\Shop\Enum\OrderAction;
use Mublo\Packages\Shop\Event\PaymentMismatchEvent;
use Mublo\Contract\Payment\PaymentGatewayInterface;

/**
 * PaymentService
 *
 * 결제 비즈니스 로직 + 이벤트 발행
 *
 * ContractRegistry를 통해 1:N PG 연동을 지원합니다.
 * 각 PG사(토스, 이니시스 등)는 PaymentGatewayInterface를 구현하여
 * ContractRegistry에 등록합니다.
 *
 * 책임:
 * - PG 목록 조회 (메타데이터 기반)
 * - 결제 준비/검증/취소 (PG 인터페이스 호출)
 *
 * 금지:
 * - Request/Response 직접 처리 (Controller 담당)
 * - DB 직접 접근 (Repository 담당)
 * - PG사별 구현 로직 (각 PG 구현체 담당)
 */
class PaymentService
{
    private ContractRegistry $registry;
    private OrderRepository $orderRepository;
    private OrderService $orderService;
    private PriceCalculator $priceCalculator;
    private ?EventDispatcher $eventDispatcher;
    private PaymentCompletionService $completionService;

    public function __construct(
        ContractRegistry $registry,
        OrderRepository $orderRepository,
        OrderService $orderService,
        PriceCalculator $priceCalculator,
        PaymentCompletionService $completionService,
        ?EventDispatcher $eventDispatcher = null
    ) {
        $this->registry = $registry;
        $this->orderRepository = $orderRepository;
        $this->orderService = $orderService;
        $this->priceCalculator = $priceCalculator;
        $this->completionService = $completionService;
        $this->eventDispatcher = $eventDispatcher;
    }

    /**
     * 이벤트 발행 헬퍼
     */
    private function dispatch(EventInterface $event): EventInterface
    {
        return $this->eventDispatcher?->dispatch($event) ?? $event;
    }

    /**
     * 사용 가능한 PG 목록 조회
     *
     * ContractRegistry에 등록된 PaymentGatewayInterface 메타데이터 반환
     * 인스턴스를 resolve하지 않으므로 lazy 등록의 이점을 유지
     *
     * @param array $enabledKeys 활성화된 PG 키 목록 (빈 배열이면 전체 반환)
     * @return Result 성공 시 gateways 배열 포함 (key => meta)
     */
    public function getAvailableGateways(array $enabledKeys = []): Result
    {
        $allMeta = $this->registry->allMeta(PaymentGatewayInterface::class);

        if (!empty($enabledKeys)) {
            $allMeta = array_intersect_key($allMeta, array_flip($enabledKeys));
        }

        return Result::success('', ['gateways' => $allMeta]);
    }

    /**
     * 결제 게이트웨이 키 선택
     *
     * 우선순위:
     * 1) 요청 키
     * 2) 도메인 기본 키
     * 3) 활성 키 중 첫 번째
     * 4) Registry 등록 키 중 첫 번째
     */
    public function selectGatewayKey(
        array $enabledKeys,
        ?string $requestedKey = null,
        ?string $defaultKey = null
    ): ?string {
        $requestedKey = trim((string) $requestedKey);
        $defaultKey = trim((string) $defaultKey);
        $normalizedEnabled = array_values(array_filter(array_map('strval', $enabledKeys)));

        if ($requestedKey !== '' && $this->isSelectableGateway($requestedKey, $normalizedEnabled)) {
            return $requestedKey;
        }

        if ($defaultKey !== '' && $this->isSelectableGateway($defaultKey, $normalizedEnabled)) {
            return $defaultKey;
        }

        foreach ($normalizedEnabled as $enabledKey) {
            if ($this->registry->hasKey(PaymentGatewayInterface::class, $enabledKey)) {
                return $enabledKey;
            }
        }

        foreach (array_keys($this->registry->allMeta(PaymentGatewayInterface::class)) as $registeredKey) {
            if ($this->registry->hasKey(PaymentGatewayInterface::class, $registeredKey)) {
                return (string) $registeredKey;
            }
        }

        return null;
    }

    /**
     * 결제 준비
     *
     * PG에 주문 정보를 전달하고 클라이언트 토큰/결제 정보를 반환
     *
     * @param string $pgKey PG 키 (예: 'tosspay', 'nicepay')
     * @param array $paymentData 결제 데이터 (order_no, amount, buyer_name 등)
     * @return Result 성공 시 PG prepare 응답 데이터 포함
     */
    public function processPayment(string $pgKey, array $paymentData): Result
    {
        // PG 구현체 조회
        $gateway = $this->resolveGateway($pgKey);
        if ($gateway === null) {
            return Result::failure("등록되지 않은 결제 수단입니다: {$pgKey}");
        }

        try {
            $result = $gateway->prepare($paymentData);
        } catch (\Throwable $e) {
            return Result::failure('결제 준비 중 오류가 발생했습니다: ' . $e->getMessage());
        }

        // 게이트웨이가 준비를 거부하면(최소금액 미만, PG 파라미터 오류 등)
        // 결제창을 열지 않도록 실패로 전파한다. 거부 사유는 error.message로 노출.
        if (!$result->success) {
            return Result::failure($result->errorMessage ?? '결제 준비에 실패했습니다.');
        }

        // PG 거래번호를 주문에 남긴다 — 승인 보장이 아니라 대사용 키다.
        // 이게 없으면 결제창 이탈·webhook 유실로 검증 전에 끊겼을 때 PG 에 조회할
        // 방법이 사라진다(PG 에는 승인이 있는데 우리 쪽엔 흔적이 없는 상태).
        $orderNo = (string) ($paymentData['order_no'] ?? '');
        if ($orderNo !== '' && $result->transactionId !== '') {
            $this->orderRepository->updatePaymentTid($orderNo, $result->transactionId);
        }

        return Result::success('결제가 준비되었습니다.', $result->toArray());
    }

    /**
     * 결제 검증 + 상태 전이
     *
     * PG 서버에서 실제 결제가 완료되었는지 검증하고,
     * 성공 시 주문 상태를 PAID로 전이합니다.
     *
     * 소유권, 이중결제 방지, 금액 일치 여부를 함께 검증합니다.
     * 상태 전이 실패 시 PaymentMismatchEvent를 발행하여 관리자 개입을 유도합니다.
     *
     * @param string $pgKey PG 키
     * @param string $transactionId 트랜잭션 ID
     * @param string $orderNo 주문번호
     * @param int $memberId 현재 로그인 회원 ID
     * @param int $domainId 도메인 ID (FSM 상태 전이에 필요)
     * @return Result 성공 시 검증 결과 데이터 포함
     */
    public function verifyPayment(string $pgKey, string $transactionId, string $orderNo, int $memberId, int $domainId): Result
    {
        // 주문 조회
        $order = $this->orderRepository->find($orderNo);
        if (!$order) {
            return Result::failure('주문을 찾을 수 없습니다.');
        }

        $orderArray = $order->toArray();

        // 소유권 검증: 현재 사용자의 주문인지 확인
        if ((int) ($orderArray['member_id'] ?? 0) !== $memberId) {
            return Result::failure('주문 정보에 접근할 수 없습니다.');
        }

        // 이미 PAID 상태면 멱등 응답 (서버 콜백이 먼저 도착한 경우의 race-free 처리)
        $currentStatus = $orderArray['order_status'] ?? '';
        if ($currentStatus === OrderAction::PAID->value) {
            return $this->resumeCompletionOrReturnPaid($orderNo, [
                'success' => true,
                'already_paid' => true,
                'transaction_id' => $transactionId,
            ]);
        }

        // 이중결제 방지: PG 주문은 attempting(결제창 호출 후 미완료) 상태에서만 검증 허용
        if ($currentStatus !== OrderAction::ATTEMPTING->value) {
            return Result::failure('이미 처리된 주문입니다.');
        }

        return $this->finalizeVerifiedPayment($orderArray, $orderNo, $pgKey, $transactionId, $domainId);
    }

    /**
     * 서버 콜백(webhook) 경로 결제 검증
     *
     * PG가 server-to-server로 결제 완료를 통보하는 경우 호출.
     * 소유권 검증은 PG 측 인증으로 대체되므로 우회한다.
     * 이미 PAID 상태인 주문이 재통보된 경우(멱등성) 성공으로 응답한다.
     */
    public function verifyPaymentByCallback(string $pgKey, string $transactionId, string $orderNo, int $domainId): Result
    {
        $order = $this->orderRepository->find($orderNo);
        if (!$order) {
            return Result::failure('주문을 찾을 수 없습니다.');
        }

        $orderArray = $order->toArray();

        // 이미 PAID 상태면 멱등 응답 (PG가 같은 webhook을 재전송하는 경우)
        $currentStatus = $orderArray['order_status'] ?? '';
        if ($currentStatus === OrderAction::PAID->value) {
            return $this->resumeCompletionOrReturnPaid($orderNo, [
                'success' => true,
                'already_paid' => true,
                'transaction_id' => $transactionId,
            ]);
        }

        if ($currentStatus !== OrderAction::ATTEMPTING->value) {
            return Result::failure('결제 검증 가능한 상태가 아닙니다: ' . $currentStatus);
        }

        return $this->finalizeVerifiedPayment($orderArray, $orderNo, $pgKey, $transactionId, $domainId);
    }

    /**
     * PG 검증 호출 + 금액 검증 + 트랜잭션 기록 + FSM 전이
     *
     * verifyPayment / verifyPaymentByCallback의 공통 후처리 로직.
     * 호출자는 이미 주문 조회 + 상태 가드(PG=ATTEMPTING)를 수행한 상태여야 한다.
     */
    private function finalizeVerifiedPayment(array $orderArray, string $orderNo, string $pgKey, string $transactionId, int $domainId): Result
    {
        // PG 키 일치 검증: 주문 생성 시 선택한 PG와 검증 요청 PG가 동일해야 함
        $orderPgKey = $orderArray['payment_gateway'] ?? '';
        if ($orderPgKey !== '' && $pgKey !== $orderPgKey) {
            return $this->failVerify($orderNo, $domainId, '결제 수단이 주문 정보와 일치하지 않습니다.');
        }

        // PG 검증
        $gateway = $this->resolveGateway($pgKey);
        if ($gateway === null) {
            return $this->failVerify($orderNo, $domainId, "등록되지 않은 결제 수단입니다: {$pgKey}");
        }

        try {
            $result = $gateway->verify($transactionId);
        } catch (\Throwable $e) {
            return $this->failVerify($orderNo, $domainId, '결제 검증 중 오류가 발생했습니다: ' . $e->getMessage());
        }

        // 단순 미완료(결제창 이탈/취소 등)는 주문을 ATTEMPTING(출생 상태)으로 그대로 둔다.
        // PG별 동작 통일: 일부 PG만 이탈 시 verify를 호출해 failVerify로 CANCELLED 되던
        // 불일치를 제거한다. 미결제 ATTEMPTING 고아는 만료 스윕 + 주문목록 제외에 위임한다.
        // (failVerify 자동취소는 아래 PG키/주문번호/금액 불일치 같은 '확정적 이상'에만 적용)
        if (!$result->success) {
            // 상태는 ATTEMPTING 으로 두되(만료 스윕에 위임), 사유는 이력에 남겨 추적 가능하게 한다.
            // (prepare 실패 로깅과 대칭 — 둘 다 PG가 보고한 '미완료'이며 verify 가 실제 서버에 도달한 경우)
            // 사유: 게이트웨이가 error.message 를 주면 우선, 없으면 매핑된 결제상태(status)를 남긴다.
            // (페이앱 verify 는 error 없이 status=REQUESTED/CANCELLED/WAITING 등으로 미완료를 표현)
            $reason = (string) ($result->errorMessage ?? $result->status ?? '');
            $this->orderService->logEvent(
                $orderNo, $domainId,
                'PG 결제 미완료' . ($reason !== '' ? ': ' . $reason : ''), 'PAYMENT', 'SYSTEM'
            );
            return Result::failure('PG 결제가 완료 상태가 아닙니다.');
        }

        // PG 응답의 주문번호가 요청 주문번호와 일치하는지 검증 (반환 시)
        if ($result->orderNo !== null && $result->orderNo !== $orderNo) {
            return $this->failVerify($orderNo, $domainId, '결제 정보의 주문번호가 일치하지 않습니다.');
        }

        // 금액 검증 (fail-closed): PG는 검증 응답에 실제 승인 금액을 반드시 표면화해야 한다.
        // 금액이 없으면 '싼 결제의 거래ID로 비싼 주문을 PAID로 뒤집는' 위변조를 확인할 수 없으므로
        // 검증을 건너뛰지 않고 실패시킨다. (실 PG는 statecheck로 승인금액을 반환한다)
        $expectedAmount = $this->priceCalculator->calculatePaymentAmount($orderArray);
        if ($result->amount === null) {
            error_log("[SHOP][PAY] verify 응답에 결제 금액 없음 — 위변조 검증 불가 pg={$pgKey} order={$orderNo}");
            return $this->failVerify($orderNo, $domainId, '결제 금액을 확인할 수 없어 검증에 실패했습니다.');
        }
        if ($result->amount !== $expectedAmount) {
            return $this->failVerify($orderNo, $domainId, '결제 금액이 일치하지 않습니다.');
        }

        $verifiedTransactionId = $result->transactionId !== '' ? $result->transactionId : $transactionId;

        // PAID 전이 전에 후처리 원장을 먼저 준비한다. 이후 어느 지점에서 중단돼도
        // 같은 주문번호의 원장을 재시도해 장바구니·쿠폰·포인트 처리를 이어갈 수 있다.
        $stageResult = $this->completionService->stage(
            $orderNo,
            $domainId,
            $pgKey,
            $verifiedTransactionId,
            $result->toArray()
        );
        if ($stageResult->isFailure()) {
            return $stageResult;
        }

        // FSM 상태 전이(CAS)를 먼저 시도해 동시 처리(client verify + PG 콜백)의 '승자'를 결정한다.
        // updateStatus는 'WHERE order_status = 현재값' 조건부 갱신이라 동시 두 건 중 하나만 성공한다.
        // 승자만 PAYMENT 트랜잭션 기록·결제완료 이벤트를 수행하므로, 중복 PAYMENT 행과
        // (원래 패자가 일으키던) 거짓 '결제-상태 불일치' 경보가 발생하지 않는다.
        $statusResult = $this->orderService->updateStatus(
            $orderNo, OrderAction::PAID->value, $domainId, '', 'SYSTEM'
        );

        if ($statusResult->isFailure()) {
            // CAS 실패 — 동시 처리 승자가 이미 PAID로 만든 경우(양성 레이스)인지 확인한다.
            $fresh = $this->orderRepository->find($orderNo);
            $freshStatus = $fresh ? ($fresh->getOrderStatusRaw() ?? '') : '';
            if ($freshStatus === OrderAction::PAID->value) {
                // 다른 경로가 PAID를 선점했더라도 같은 원장의 후처리를 함께 재개할 수 있다.
                return $this->resumeCompletionOrReturnPaid(
                    $orderNo,
                    $result->toArray(),
                    '결제가 검증되었습니다. (이미 처리됨)'
                );
            }
            // 결제는 완료됐으나 상태가 PAID가 아님(진짜 불일치) → 관리자 개입 필요.
            error_log("[CRITICAL] 결제-상태 불일치: {$orderNo}, PG={$pgKey}, TXN={$transactionId}");
            $this->dispatch(new PaymentMismatchEvent($orderNo, $pgKey, $transactionId, $statusResult->getMessage()));
            return Result::success('결제가 검증되었습니다.', $result->toArray());
        }

        return $this->resumeCompletionOrReturnPaid(
            $orderNo,
            $result->toArray(),
            '결제가 검증되었습니다.'
        );
    }

    /**
     * 0원 결제 확정 (PG 우회)
     *
     * 쿠폰/포인트로 결제금액이 0원이 되어 PG 승인이 불필요한 주문을 즉시 결제완료 처리한다.
     * PG-complete(finalizeVerifiedPayment)와 동일하게 PAID 전이 후 PaymentCompletionService가
     * 쿠폰 사용처리·포인트 차감·장바구니 정리를 멱등하게 수행하고 외부 이벤트를 발행한다.
     *
     * 호출자(CartController)는 payment_gateway=''(무 PG) / payment_method=FREE 로 주문을
     * 생성한 상태여야 한다. 이 경우 주문은 RECEIVED 로 출생하며 여기서 RECEIVED→PAID 로 전이한다.
     *
     * @param string $orderNo 주문번호
     * @param int $domainId 도메인 ID
     * @return Result 성공 시 결제완료 데이터 포함
     */
    public function finalizeZeroAmountPayment(string $orderNo, int $domainId): Result
    {
        $order = $this->orderRepository->find($orderNo);
        if (!$order) {
            return Result::failure('주문을 찾을 수 없습니다.');
        }

        $orderArray = $order->toArray();

        $zeroPaymentData = [
            'success' => true,
            'amount' => 0,
            'zero_amount' => true,
        ];

        // 멱등 처리: 이미 PAID면 미완료 원장이 있을 때 후처리를 재개한다.
        if (($orderArray['order_status'] ?? '') === OrderAction::PAID->value) {
            $this->completionService->stage($orderNo, $domainId, '', '', $zeroPaymentData);
            return $this->resumeCompletionOrReturnPaid($orderNo, $zeroPaymentData + [
                'already_paid' => true,
            ], '이미 결제 완료된 주문입니다.');
        }

        // 서버 권위 금액 재검증: 영속된 주문 레코드 기준으로 실제 0원인지 확인한다.
        // (배송비가 남아 있으면 0원이 아니므로 여기서 걸러져 PG 우회가 차단된다.)
        $expectedAmount = $this->priceCalculator->calculatePaymentAmount($orderArray);
        if ($expectedAmount > 0) {
            return Result::failure('결제 금액이 0원이 아니어서 무결제 확정할 수 없습니다.');
        }

        $stageResult = $this->completionService->stage($orderNo, $domainId, '', '', $zeroPaymentData);
        if ($stageResult->isFailure()) {
            return $stageResult;
        }

        // 결제완료 전이 (RECEIVED → PAID)
        $statusResult = $this->orderService->updateStatus(
            $orderNo, OrderAction::PAID->value, $domainId,
            '0원 결제(쿠폰/포인트 전액 상계)', 'SYSTEM'
        );
        if ($statusResult->isFailure()) {
            return Result::failure($statusResult->getMessage());
        }

        return $this->resumeCompletionOrReturnPaid($orderNo, $zeroPaymentData, '0원 결제가 완료되었습니다.');
    }

    /**
     * 이미 PAID인 재요청도 후처리 원장을 확인해 실패·중단된 작업을 재개한다.
     * 결제 승인은 끝난 상태이므로 후처리 실패는 성공 응답에 pending 플래그로 노출한다.
     */
    private function resumeCompletionOrReturnPaid(
        string $orderNo,
        array $data,
        string $message = '이미 결제 검증 완료된 주문입니다.'
    ): Result {
        $completion = $this->completionService->process($orderNo);
        if ($completion->isFailure()) {
            return Result::success($message, $data + [
                'postprocessing_pending' => true,
                'postprocessing_error' => $completion->getMessage(),
            ]);
        }

        if ($completion->get('processing', false)) {
            return Result::success($message, $data + [
                'postprocessing_pending' => true,
                'completion_event_id' => $completion->get('event_id', ''),
            ]);
        }

        return Result::success($message, $data + [
            'postprocessing_pending' => false,
            'completion_event_id' => $completion->get('event_id', ''),
        ]);
    }

    /**
     * 결제 취소 (PG 취소 전용)
     *
     * PG를 통해 결제를 취소하고 환불 처리합니다.
     * 주문 상태 전이는 호출자(RefundService, Admin Controller)가 관리합니다.
     *
     * @param string $pgKey PG 키
     * @param string $transactionId 트랜잭션 ID
     * @param int $amount 취소 금액
     * @param string $reason 취소 사유
     * @param int|null $remainingAmount 이 취소 직전의 취소 가능 잔액 (PG 가 전체/부분을 가르는 근거)
     * @return Result 성공 시 취소 결과 데이터 포함
     */
    public function cancelPayment(
        string $pgKey,
        string $transactionId,
        int $amount,
        string $reason = '',
        ?int $remainingAmount = null
    ): Result {
        if ($amount <= 0) {
            return Result::failure('취소 금액은 0보다 커야 합니다.');
        }

        $gateway = $this->resolveGateway($pgKey);
        if ($gateway === null) {
            return Result::failure("등록되지 않은 결제 수단입니다: {$pgKey}");
        }

        try {
            $result = $gateway->cancel($transactionId, $amount, $reason, $remainingAmount);
        } catch (\Throwable $e) {
            return Result::failure('결제 취소 중 오류가 발생했습니다: ' . $e->getMessage());
        }

        // PG가 실패를 보고하면 그대로 실패로 전파한다 (RefundService가 트랜잭션을 기록하지 않도록)
        if (!$result->success) {
            $errorCode = $result->errorCode ?? 'PG_CANCEL_FAILED';
            $errorMsg = $result->errorMessage ?? '결제 취소에 실패했습니다.';
            return Result::failure("[{$errorCode}] {$errorMsg}");
        }

        return Result::success('결제가 취소되었습니다.', $result->toArray());
    }

    /**
     * PG 클라이언트 설정 조회
     *
     * 프론트에서 PG 결제창을 열기 위한 설정 반환
     *
     * @param string $pgKey PG 키
     * @return array 클라이언트 설정 (mode, requires_redirect 등)
     */
    public function getClientConfig(string $pgKey): array
    {
        $gateway = $this->resolveGateway($pgKey);
        if ($gateway === null) {
            return [];
        }

        return $gateway->getClientConfig();
    }

    /**
     * 활성화된 PG들의 체크아웃 핸들러 JS 수집
     *
     * 각 PG가 getCheckoutScript()로 반환한 JS 문자열 목록.
     * null을 반환한 PG (특별 JS 불필요)는 제외됩니다.
     *
     * @param string[] $pgKeys 활성화된 PG 키 목록
     * @return string[] JS 문자열 배열
     */
    public function collectCheckoutScripts(array $pgKeys): array
    {
        $scripts = [];
        foreach ($pgKeys as $key) {
            $gateway = $this->resolveGateway($key);
            if ($gateway === null) {
                continue;
            }
            $script = $gateway->getCheckoutScript();
            if ($script !== null) {
                $scripts[] = $script;
            }
        }
        return $scripts;
    }

    /**
     * ContractRegistry에서 PG 구현체 조회
     *
     * @param string $pgKey PG 키
     * @return PaymentGatewayInterface|null
     */
    private function resolveGateway(string $pgKey): ?PaymentGatewayInterface
    {
        try {
            $gateway = $this->registry->get(PaymentGatewayInterface::class, $pgKey);
            return $gateway instanceof PaymentGatewayInterface ? $gateway : null;
        } catch (RegistryNotFoundException $e) {
            return null;
        }
    }

    /**
     * 선택 가능한 결제 게이트웨이인지 확인
     *
     * @param string[] $enabledKeys
     */
    private function isSelectableGateway(string $key, array $enabledKeys): bool
    {
        if (!$this->registry->hasKey(PaymentGatewayInterface::class, $key)) {
            return false;
        }

        return empty($enabledKeys) || in_array($key, $enabledKeys, true);
    }

    /**
     * verify 단계의 '확정적 이상'에 한해 주문을 자동 CANCELLED로 전이
     *
     * 호출 대상: PG 키 불일치 / 미등록 게이트웨이 / 검증 예외 / 주문번호·금액 불일치 등
     * 결제 자체가 잘못 연결됐거나 변조 의심이 있는 경우만.
     *
     * 제외: 단순 미완료(결제창 이탈/취소)는 호출하지 않는다. 이 경우 주문은 ATTEMPTING으로
     *      남기고 만료 스윕 + 주문목록 제외에 맡겨 PG 간 동작을 통일한다.
     *      "이미 처리된 주문"·"권한 없음" 같은 보호 분기에서도 호출하지 않음.
     */
    private function failVerify(string $orderNo, int $domainId, string $reason): Result
    {
        $this->orderService->updateStatus(
            $orderNo,
            OrderAction::CANCELLED->value,
            $domainId,
            '결제 검증 실패: ' . $reason,
            'SYSTEM'
        );
        return Result::failure($reason);
    }
}
