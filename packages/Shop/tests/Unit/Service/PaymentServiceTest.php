<?php
/**
 * packages/Shop/tests/Unit/Service/PaymentServiceTest.php
 *
 * PaymentService 단위 테스트
 *
 * 검증 항목:
 * - getAvailableGateways() — 전체 반환, enabledKeys 필터링
 * - processPayment()       — 미등록 PG 거부, 예외 처리, 성공
 * - verifyPayment()        — 주문 없음, 소유자 불일치, 이미 처리됨, PG 불일치, 금액 불일치, 성공
 * - cancelPayment()        — 금액 0 거부, 미등록 PG 거부, 예외 처리, 성공
 */

namespace Tests\Shop\Unit\Service;

use Tests\Shop\TestCase;
use Mublo\Packages\Shop\Service\PaymentService;
use Mublo\Packages\Shop\Service\PaymentCompletionService;
use Mublo\Packages\Shop\Service\OrderService;
use Mublo\Packages\Shop\Service\PriceCalculator;
use Mublo\Packages\Shop\Repository\OrderRepository;
use Mublo\Packages\Shop\Entity\Order;
use Mublo\Core\Registry\ContractRegistry;
use Mublo\Core\Result\Result;
use Mublo\Contract\Payment\PaymentGatewayInterface;
use Mublo\Contract\Payment\PaymentGatewayResponse;

class PaymentServiceTest extends TestCase
{
    private ContractRegistry $registry;
    private OrderRepository $orderRepo;
    private OrderService $orderService;
    private PriceCalculator $priceCalc;
    private PaymentCompletionService $completionService;
    private PaymentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry     = new ContractRegistry();
        $this->orderRepo    = $this->createMock(OrderRepository::class);
        $this->orderService = $this->createMock(OrderService::class);
        $this->priceCalc    = new PriceCalculator();
        $this->completionService = $this->createMock(PaymentCompletionService::class);
        $this->completionService->method('stage')->willReturn(Result::success('staged'));
        $this->completionService->method('process')->willReturn(Result::success('completed', [
            'event_id' => 'evt-default',
        ]));

        $this->service = new PaymentService(
            $this->registry,
            $this->orderRepo,
            $this->orderService,
            $this->priceCalc,
            $this->completionService
        );
    }

    /**
     * 테스트용 PG 목(PaymentGatewayInterface 구현체) 생성
     */
    private function makeMockGateway(
        ?PaymentGatewayResponse $prepareResult = null,
        ?PaymentGatewayResponse $verifyResult = null,
        ?PaymentGatewayResponse $cancelResult = null
    ): PaymentGatewayInterface
    {
        $gateway = $this->createMock(PaymentGatewayInterface::class);
        $gateway->method('prepare')->willReturn($prepareResult ?? new PaymentGatewayResponse(true, '', data: ['token' => 'tok_test']));
        $gateway->method('verify')->willReturn($verifyResult ?? new PaymentGatewayResponse(true, 'txn_test', amount: 30000));
        $gateway->method('cancel')->willReturn($cancelResult ?? new PaymentGatewayResponse(true, 'txn_test', data: ['cancelled' => true]));
        $gateway->method('getClientConfig')->willReturn(['mode' => 'test']);
        $gateway->method('getCheckoutScript')->willReturn(null);
        return $gateway;
    }

    /**
     * ContractRegistry에 PG 등록 (메타 포함)
     */
    private function registerGateway(string $key, PaymentGatewayInterface $gateway): void
    {
        $this->registry->register(
            PaymentGatewayInterface::class,
            $key,
            $gateway,
            ['label' => $key . ' 결제']
        );
    }

    // =========================================================
    // getAvailableGateways()
    // =========================================================

    public function testGetAvailableGatewaysReturnsAll(): void
    {
        $this->registerGateway('tosspay', $this->makeMockGateway());
        $this->registerGateway('nicepay', $this->makeMockGateway());

        $result = $this->service->getAvailableGateways();

        $this->assertTrue($result->isSuccess());
        $gateways = $result->get('gateways');
        $this->assertArrayHasKey('tosspay', $gateways);
        $this->assertArrayHasKey('nicepay', $gateways);
    }

    public function testGetAvailableGatewaysFiltersEnabledKeys(): void
    {
        $this->registerGateway('tosspay', $this->makeMockGateway());
        $this->registerGateway('nicepay', $this->makeMockGateway());
        $this->registerGateway('iamport', $this->makeMockGateway());

        $result = $this->service->getAvailableGateways(['tosspay', 'iamport']);

        $this->assertTrue($result->isSuccess());
        $gateways = $result->get('gateways');
        $this->assertArrayHasKey('tosspay', $gateways);
        $this->assertArrayHasKey('iamport', $gateways);
        $this->assertArrayNotHasKey('nicepay', $gateways);
    }

    public function testGetAvailableGatewaysReturnsEmptyWhenNoneRegistered(): void
    {
        $result = $this->service->getAvailableGateways();

        $this->assertTrue($result->isSuccess());
        $this->assertEmpty($result->get('gateways'));
    }

    // =========================================================
    // processPayment()
    // =========================================================

    public function testProcessPaymentFailsWhenPgNotRegistered(): void
    {
        $result = $this->service->processPayment('unknown_pg', ['order_no' => 'ORD001']);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('등록되지 않은', $result->getMessage());
    }

    public function testProcessPaymentFailsWhenPrepareThrows(): void
    {
        $gateway = $this->createMock(PaymentGatewayInterface::class);
        $gateway->method('prepare')->willThrowException(new \RuntimeException('PG 연결 실패'));
        $this->registerGateway('tosspay', $gateway);

        $result = $this->service->processPayment('tosspay', ['order_no' => 'ORD001']);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('오류', $result->getMessage());
    }

    public function testProcessPaymentSuccessReturnsPrepareData(): void
    {
        $gateway = $this->makeMockGateway(new PaymentGatewayResponse(
            true,
            '',
            data: ['token' => 'tok_abc', 'payment_url' => 'https://pg.example.com/pay']
        ));
        $this->registerGateway('tosspay', $gateway);

        $result = $this->service->processPayment('tosspay', [
            'order_no' => 'ORD001',
            'amount'   => 30000,
        ]);

        $this->assertTrue($result->isSuccess());
        $data = $result->getData();
        $this->assertArrayHasKey('token', $data);
    }

    public function testProcessPaymentPropagatesGatewayFailure(): void
    {
        // prepare()가 success=false로 거부하면(최소금액 미만 등) 결제창을 열지 않도록
        // PaymentService가 실패로 전파하고 거부 사유를 메시지로 노출해야 한다.
        $gateway = $this->createMock(PaymentGatewayInterface::class);
        $gateway->method('prepare')->willReturn(new PaymentGatewayResponse(
            false,
            '',
            errorCode: 'MIN_AMOUNT',
            errorMessage: '페이앱 최소 결제금액은 1,000원입니다.'
        ));
        $this->registerGateway('payapp', $gateway);

        $result = $this->service->processPayment('payapp', [
            'order_no' => 'ORD001',
            'amount'   => 100,
        ]);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('최소 결제금액', $result->getMessage());
    }

    // =========================================================
    // verifyPayment()
    // =========================================================

    public function testVerifyPaymentFailsWhenOrderNotFound(): void
    {
        $this->orderRepo->method('find')->willReturn(null);

        $result = $this->service->verifyPayment('tosspay', 'txn_001', 'ORD001', 42, 1);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('찾을 수 없', $result->getMessage());
    }

    public function testVerifyPaymentFailsWhenMemberMismatch(): void
    {
        $order = Order::fromArray($this->makeOrderData([
            'order_no'     => 'ORD001',
            'member_id'    => 99,          // 다른 회원
            'order_status' => 'received',
        ]));
        $this->orderRepo->method('find')->willReturn($order);

        $result = $this->service->verifyPayment('tosspay', 'txn_001', 'ORD001', 42, 1);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('접근', $result->getMessage());
    }

    public function testVerifyPaymentReturnsIdempotentSuccessWhenAlreadyPaid(): void
    {
        // PG webhook이 먼저 도착해 주문이 PAID로 전이된 뒤,
        // 프론트의 verify 호출이 뒤늦게 와도 멱등 성공으로 응답해야 한다.
        $order = Order::fromArray($this->makeOrderData([
            'order_no'     => 'ORD001',
            'member_id'    => 42,
            'order_status' => 'paid',  // OrderAction::PAID->value
        ]));
        $this->orderRepo->method('find')->willReturn($order);

        $result = $this->service->verifyPayment('tosspay', 'txn_001', 'ORD001', 42, 1);

        $this->assertTrue($result->isSuccess());
        $data = $result->getData();
        $this->assertTrue($data['success'] ?? false);
        $this->assertTrue($data['already_paid'] ?? false);
    }

    public function testAlreadyPaidRequestResumesPendingCompletion(): void
    {
        $order = Order::fromArray($this->makeOrderData([
            'order_no' => 'ORD001',
            'member_id' => 42,
            'order_status' => 'paid',
        ]));
        $this->orderRepo->method('find')->willReturn($order);
        $completion = $this->createMock(PaymentCompletionService::class);
        $completion->expects($this->once())
            ->method('process')
            ->with('ORD001')
            ->willReturn(Result::success('done', ['event_id' => 'evt-1']));
        $service = new PaymentService(
            $this->registry,
            $this->orderRepo,
            $this->orderService,
            $this->priceCalc,
            $completion,
            null,
        );

        $result = $service->verifyPayment('tosspay', 'txn_001', 'ORD001', 42, 1);

        $this->assertTrue($result->isSuccess());
        $this->assertFalse($result->get('postprocessing_pending'));
        $this->assertSame('evt-1', $result->get('completion_event_id'));
    }

    public function testSuccessfulPaymentStagesBeforeStatusAndProcessesAfterward(): void
    {
        $order = Order::fromArray($this->makeOrderData([
            'order_no' => 'ORD001',
            'member_id' => 42,
            'order_status' => 'attempting',
            'payment_gateway' => 'tosspay',
            'total_price' => 30000,
            'shipping_fee' => 3000,
        ]));
        $this->orderRepo->method('find')->willReturn($order);
        $gateway = $this->makeMockGateway(verifyResult: new PaymentGatewayResponse(
            true,
            'txn_canonical',
            amount: 33000,
        ));
        $this->registerGateway('tosspay', $gateway);
        $completion = $this->createMock(PaymentCompletionService::class);
        $completion->expects($this->once())
            ->method('stage')
            ->with(
                'ORD001',
                1,
                'tosspay',
                'txn_canonical',
                $this->callback(fn(array $data): bool => ($data['amount'] ?? null) === 33000)
            )
            ->willReturn(Result::success('staged'));
        $completion->expects($this->once())
            ->method('process')
            ->with('ORD001')
            ->willReturn(Result::success('done', ['event_id' => 'evt-1']));
        $this->orderService->method('updateStatus')->willReturn(Result::success('paid'));
        $service = new PaymentService(
            $this->registry,
            $this->orderRepo,
            $this->orderService,
            $this->priceCalc,
            $completion,
            null,
        );

        $result = $service->verifyPayment('tosspay', 'txn_request', 'ORD001', 42, 1);

        $this->assertTrue($result->isSuccess());
        $this->assertFalse($result->get('postprocessing_pending'));
    }

    public function testPaymentRemainsSuccessfulWhenCompletionNeedsRetry(): void
    {
        $order = Order::fromArray($this->makeOrderData([
            'order_no' => 'ORD001',
            'member_id' => 42,
            'order_status' => 'paid',
        ]));
        $this->orderRepo->method('find')->willReturn($order);
        $completion = $this->createMock(PaymentCompletionService::class);
        $completion->method('process')->willReturn(Result::failure('temporary failure'));
        $service = new PaymentService(
            $this->registry,
            $this->orderRepo,
            $this->orderService,
            $this->priceCalc,
            $completion,
            null,
        );

        $result = $service->verifyPayment('tosspay', 'txn_001', 'ORD001', 42, 1);

        $this->assertTrue($result->isSuccess());
        $this->assertTrue($result->get('postprocessing_pending'));
        $this->assertStringContainsString('temporary failure', $result->get('postprocessing_error'));
    }

    public function testZeroAmountPaymentUsesSameCompletionLedger(): void
    {
        $order = Order::fromArray($this->makeOrderData([
            'order_no' => 'ORD001',
            'order_status' => 'received',
            'total_price' => 10000,
            'coupon_discount' => 10000,
            'shipping_fee' => 0,
            'payment_gateway' => '',
            'payment_method' => 'FREE',
        ]));
        $this->orderRepo->method('find')->willReturn($order);
        $completion = $this->createMock(PaymentCompletionService::class);
        $completion->expects($this->once())
            ->method('stage')
            ->with(
                'ORD001',
                1,
                '',
                '',
                ['success' => true, 'amount' => 0, 'zero_amount' => true]
            )
            ->willReturn(Result::success('staged'));
        $completion->expects($this->once())
            ->method('process')
            ->with('ORD001')
            ->willReturn(Result::success('done', ['event_id' => 'evt-zero']));
        $this->orderService->expects($this->once())
            ->method('updateStatus')
            ->with('ORD001', 'paid', 1, $this->anything(), 'SYSTEM')
            ->willReturn(Result::success('paid'));
        $service = new PaymentService(
            $this->registry,
            $this->orderRepo,
            $this->orderService,
            $this->priceCalc,
            $completion,
        );

        $result = $service->finalizeZeroAmountPayment('ORD001', 1);

        $this->assertTrue($result->isSuccess());
        $this->assertFalse($result->get('postprocessing_pending'));
        $this->assertSame('evt-zero', $result->get('completion_event_id'));
    }

    public function testVerifyPaymentFailsWhenPgKeyMismatch(): void
    {
        $order = Order::fromArray($this->makeOrderData([
            'order_no'       => 'ORD001',
            'member_id'      => 42,
            'order_status'   => 'attempting',
            'payment_gateway'=> 'nicepay',  // 다른 PG로 생성
        ]));
        $this->orderRepo->method('find')->willReturn($order);

        $result = $this->service->verifyPayment('tosspay', 'txn_001', 'ORD001', 42, 1);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('결제 수단', $result->getMessage());
    }

    public function testVerifyPaymentFailsWhenPgNotRegistered(): void
    {
        $order = Order::fromArray($this->makeOrderData([
            'order_no'       => 'ORD001',
            'member_id'      => 42,
            'order_status'   => 'attempting',
            'payment_gateway'=> '',
        ]));
        $this->orderRepo->method('find')->willReturn($order);

        $result = $this->service->verifyPayment('unknown_pg', 'txn_001', 'ORD001', 42, 1);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('등록되지 않은', $result->getMessage());
    }

    public function testVerifyPaymentFailsWhenAmountMismatch(): void
    {
        $order = Order::fromArray($this->makeOrderData([
            'order_no'       => 'ORD001',
            'member_id'      => 42,
            'order_status'   => 'attempting',
            'payment_gateway'=> 'tosspay',
            'total_price'    => 30000,
            'shipping_fee'   => 3000,
        ]));
        $this->orderRepo->method('find')->willReturn($order);

        // PG가 결제 성공을 보고하지만 금액이 주문과 다름
        $gateway = $this->createMock(PaymentGatewayInterface::class);
        $gateway->method('verify')->willReturn(new PaymentGatewayResponse(true, 'txn_001', amount: 99999));
        $this->registerGateway('tosspay', $gateway);

        $result = $this->service->verifyPayment('tosspay', 'txn_001', 'ORD001', 42, 1);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('금액', $result->getMessage());
    }

    public function testVerifyPaymentSuccessCallsUpdateStatus(): void
    {
        $order = Order::fromArray($this->makeOrderData([
            'order_no'       => 'ORD001',
            'member_id'      => 42,
            'order_status'   => 'attempting',
            'payment_gateway'=> 'tosspay',
            'total_price'    => 30000,
            'shipping_fee'   => 3000,
        ]));
        $this->orderRepo->method('find')->willReturn($order);

        // PG는 승인 금액을 반드시 표면화해야 한다(fail-closed). 주문 금액과 일치.
        $gateway = $this->createMock(PaymentGatewayInterface::class);
        $gateway->method('verify')->willReturn(new PaymentGatewayResponse(true, 'txn_001', amount: 33000));
        $this->registerGateway('tosspay', $gateway);

        $this->orderService->method('updateStatus')
            ->willReturn(Result::success('상태 변경됨'));
        $this->orderRepo->method('updatePaymentMethod'); // void 반환

        $result = $this->service->verifyPayment('tosspay', 'txn_001', 'ORD001', 42, 1);

        $this->assertTrue($result->isSuccess());
    }

    public function testVerifyPaymentBenignRaceWhenAlreadyPaidByConcurrentPath(): void
    {
        // 동시 처리(client verify + PG 콜백): attempting 검증은 통과했으나 CAS(updateStatus)에서
        // 패배한 경우, 재조회 시 이미 PAID면 거짓 '결제-상태 불일치' 경보 없이 멱등 성공해야 한다.
        $attempting = Order::fromArray($this->makeOrderData([
            'order_no' => 'ORD001', 'member_id' => 42, 'order_status' => 'attempting',
            'payment_gateway' => 'tosspay', 'total_price' => 30000, 'shipping_fee' => 3000,
        ]));
        $paid = Order::fromArray($this->makeOrderData([
            'order_no' => 'ORD001', 'member_id' => 42, 'order_status' => 'paid',
            'payment_gateway' => 'tosspay', 'total_price' => 30000, 'shipping_fee' => 3000,
        ]));
        // 1) verifyPayment 초기 조회 → attempting, 2) CAS 실패 후 재조회 → paid
        $this->orderRepo->method('find')->willReturnOnConsecutiveCalls($attempting, $paid, $paid);

        $gateway = $this->createMock(PaymentGatewayInterface::class);
        $gateway->method('verify')->willReturn(new PaymentGatewayResponse(true, 'txn_001', amount: 33000));
        $this->registerGateway('tosspay', $gateway);

        // CAS 패배 시뮬레이션: updateStatus 실패
        $this->orderService->method('updateStatus')->willReturn(Result::failure('주문 상태 변경에 실패했습니다.'));
        $result = $this->service->verifyPayment('tosspay', 'txn_001', 'ORD001', 42, 1);

        $this->assertTrue($result->isSuccess());
        $this->assertStringContainsString('이미 처리됨', $result->getMessage());
    }

    public function testVerifyPaymentFailsWhenAmountMissing(): void
    {
        // fail-closed: PG가 결제 성공을 보고해도 승인 금액을 표면화하지 않으면
        // 위변조를 확인할 수 없으므로 검증 실패시키고 PAID로 전이하지 않는다.
        $order = Order::fromArray($this->makeOrderData([
            'order_no'       => 'ORD001',
            'member_id'      => 42,
            'order_status'   => 'attempting',
            'payment_gateway'=> 'tosspay',
            'total_price'    => 30000,
            'shipping_fee'   => 3000,
        ]));
        $this->orderRepo->method('find')->willReturn($order);

        $gateway = $this->createMock(PaymentGatewayInterface::class);
        $gateway->method('verify')->willReturn(new PaymentGatewayResponse(true, 'txn_001')); // amount 없음
        $this->registerGateway('tosspay', $gateway);

        // 금액 확인 불가 → 검증 실패(failVerify가 주문을 취소로 전이). PAID로는 절대 넘어가지 않는다.
        $result = $this->service->verifyPayment('tosspay', 'txn_001', 'ORD001', 42, 1);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('금액', $result->getMessage());
    }

    public function testVerifyPaymentNotCompletedKeepsAttemptingWithoutCancelling(): void
    {
        // 결제창 이탈/취소 등 단순 미완료(success=false)는 주문을 CANCELLED로 전이하지 않고
        // attempting으로 그대로 둔다. (PG 간 동작 통일 — 고아는 만료 스윕/주문내역 숨김에 위임)
        $order = Order::fromArray($this->makeOrderData([
            'order_no'       => 'ORD001',
            'member_id'      => 42,
            'order_status'   => 'attempting',
            'payment_gateway'=> 'payapp',
        ]));
        $this->orderRepo->method('find')->willReturn($order);

        $gateway = $this->createMock(PaymentGatewayInterface::class);
        $gateway->method('verify')->willReturn(new PaymentGatewayResponse(false, 'txn_001'));
        $this->registerGateway('payapp', $gateway);

        // 핵심: 상태 전이(updateStatus)가 한 번도 호출되지 않아야 함
        $this->orderService->expects($this->never())->method('updateStatus');

        $result = $this->service->verifyPayment('payapp', 'txn_001', 'ORD001', 42, 1);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('완료 상태', $result->getMessage());
    }

    // =========================================================
    // cancelPayment()
    // =========================================================

    public function testCancelPaymentFailsWhenAmountIsZero(): void
    {
        $result = $this->service->cancelPayment('tosspay', 'txn_001', 0, '고객 요청');

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('취소 금액', $result->getMessage());
    }

    public function testCancelPaymentFailsWhenPgNotRegistered(): void
    {
        $result = $this->service->cancelPayment('unknown_pg', 'txn_001', 10000, '고객 요청');

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('등록되지 않은', $result->getMessage());
    }

    public function testCancelPaymentFailsWhenCancelThrows(): void
    {
        $gateway = $this->createMock(PaymentGatewayInterface::class);
        $gateway->method('cancel')->willThrowException(new \RuntimeException('PG 취소 실패'));
        $this->registerGateway('tosspay', $gateway);

        $result = $this->service->cancelPayment('tosspay', 'txn_001', 10000, '고객 요청');

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('오류', $result->getMessage());
    }

    public function testCancelPaymentSuccessReturnsCancelData(): void
    {
        // PG 컨벤션: cancel() 응답에 success=true가 있어야 PaymentService가 성공으로 인정
        $gateway = $this->createMock(PaymentGatewayInterface::class);
        $gateway->method('cancel')->willReturn(new PaymentGatewayResponse(
            true,
            'txn_001',
            cancelAmount: 10000,
            data: ['cancelled' => true, 'refund_amount' => 10000]
        ));
        $this->registerGateway('tosspay', $gateway);

        $result = $this->service->cancelPayment('tosspay', 'txn_001', 10000, '단순 변심');

        $this->assertTrue($result->isSuccess());
        $this->assertStringContainsString('취소', $result->getMessage());
        $data = $result->getData();
        $this->assertTrue($data['cancelled'] ?? false);
    }

    public function testCancelPaymentPropagatesGatewayFailure(): void
    {
        // PG가 success=false로 응답하면 PaymentService도 실패로 전파해야 한다
        $gateway = $this->createMock(PaymentGatewayInterface::class);
        $gateway->method('cancel')->willReturn(new PaymentGatewayResponse(
            false,
            'txn_001',
            errorCode: 'NOT_CONFIGURED',
            errorMessage: 'API Key가 없습니다.'
        ));
        $this->registerGateway('tosspay', $gateway);

        $result = $this->service->cancelPayment('tosspay', 'txn_001', 10000, '관리자 환불');

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('NOT_CONFIGURED', $result->getMessage());
    }
}
