<?php
/**
 * packages/Shop/tests/Unit/Service/OrderServiceUpdateInfoTest.php
 *
 * 관리자 주문인·배송지 정보 수정 (OrderService::updateOrderInfo)
 *
 * 검증 항목:
 * - 바뀐 칼럼만 주문 생성과 같은 방식으로 암호화하고 blind index 를 다시 만든다
 * - 값을 비우면 값과 인덱스를 함께 NULL 로 둔다
 * - shop_order_logs 에 INFO 로그(항목명 + 마스킹된 전후 값)를 남긴다
 * - 변경 없음/검증 실패/다른 도메인 주문은 저장하지 않는다
 */

namespace Tests\Shop\Unit\Service;

use Tests\Shop\TestCase;
use Mublo\Packages\Shop\Service\OrderService;
use Mublo\Packages\Shop\Service\OrderStateResolver;
use Mublo\Packages\Shop\Service\PriceCalculator;
use Mublo\Packages\Shop\Repository\OrderRepository;
use Mublo\Packages\Shop\Repository\CartRepository;
use Mublo\Packages\Shop\Repository\ProductRepository;
use Mublo\Packages\Shop\Repository\ProductOptionRepository;
use Mublo\Packages\Shop\Entity\Order;
use Mublo\Contract\Security\SensitiveValueCodecInterface;
use Mublo\Infrastructure\Database\Database;

class OrderServiceUpdateInfoTest extends TestCase
{
    private OrderRepository $orderRepo;
    private OrderService $service;

    /** @var array<string,mixed>|null */
    private ?array $savedData = null;
    /** @var array<string,mixed>|null */
    private ?array $savedLog = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->orderRepo = $this->createMock(OrderRepository::class);

        $stateResolver = $this->createMock(OrderStateResolver::class);
        $stateResolver->method('getLabel')->willReturn('결제완료');

        // 암호화는 'ENC:' 접두어, 인덱스는 'IDX:' 접두어로 흉내 낸다
        $enc = $this->createMock(SensitiveValueCodecInterface::class);
        $enc->method('encrypt')->willReturnCallback(fn(string $v) => 'ENC:' . $v);
        $enc->method('decrypt')->willReturnCallback(
            fn(string $v) => str_starts_with($v, 'ENC:') ? substr($v, 4) : null
        );
        $enc->method('createSearchIndex')->willReturnCallback(fn(string $v) => 'IDX:' . $v);

        $db = $this->createMock(Database::class);
        $db->method('beginTransaction')->willReturn(true);
        $db->method('commit')->willReturn(true);
        $this->orderRepo->method('getDb')->willReturn($db);

        $this->orderRepo->method('updateOrderInfo')->willReturnCallback(function (string $no, array $data) {
            $this->savedData = $data;
            return true;
        });
        $this->orderRepo->method('insertOrderLog')->willReturnCallback(function (array $log) {
            $this->savedLog = $log;
            return 1;
        });

        $this->service = new OrderService(
            $this->orderRepo,
            $this->createMock(CartRepository::class),
            $this->createMock(ProductRepository::class),
            $this->createMock(ProductOptionRepository::class),
            new PriceCalculator(),
            $stateResolver,
            null,
            $enc
        );
    }

    private function givenOrder(array $overrides = []): void
    {
        $order = Order::fromArray($this->makeOrderData(array_merge([
            'order_no'          => 'ORD1',
            'domain_id'         => 1,
            'payment_profile'   => 'NORMAL',
            'orderer_name'      => 'ENC:홍길동',
            'orderer_phone'     => 'ENC:010-1234-5678',
            'orderer_email'     => 'ENC:hong@example.com',
            'recipient_name'    => 'ENC:홍길동',
            'recipient_phone'   => 'ENC:010-1234-5678',
            'shipping_zip'      => 'ENC:12345',
            'shipping_address1' => 'ENC:서울시 강남구 테헤란로 1',
            'shipping_address2' => 'ENC:101동 202호',
            'order_memo'        => '문 앞에 놓아주세요',
            'order_status'      => 'paid',
        ], $overrides)));
        $this->orderRepo->method('find')->willReturn($order);
    }

    private function shippingInput(array $overrides = []): array
    {
        return array_merge([
            'recipient_name'    => '홍길동',
            'recipient_phone'   => '010-1234-5678',
            'shipping_zip'      => '12345',
            'shipping_address1' => '서울시 강남구 테헤란로 1',
            'shipping_address2' => '101동 202호',
            'order_memo'        => '문 앞에 놓아주세요',
        ], $overrides);
    }

    public function testShippingChangeEncryptsOnlyChangedFieldsAndRebuildsIndex(): void
    {
        $this->givenOrder();

        $result = $this->service->updateOrderInfo(1, 'ORD1', 'shipping', $this->shippingInput([
            'recipient_name'    => '김철수',
            'shipping_zip'      => '06236',
            'shipping_address1' => '서울시 강남구 역삼로 2',
        ]), 7);

        $this->assertTrue($result->isSuccess(), $result->getMessage());
        $this->assertSame([
            'recipient_name'       => 'ENC:김철수',
            'shipping_zip'         => 'ENC:06236',
            'shipping_address1'    => 'ENC:서울시 강남구 역삼로 2',
            'recipient_name_index' => 'IDX:김철수',
        ], $this->savedData);
        $this->assertArrayNotHasKey('recipient_phone_index', $this->savedData, '안 바뀐 칼럼의 인덱스는 건드리지 않는다');
    }

    public function testOrdererChangeRebuildsNameAndPhoneIndexes(): void
    {
        $this->givenOrder();

        $result = $this->service->updateOrderInfo(1, 'ORD1', 'orderer', [
            'orderer_name'  => '이영희',
            'orderer_phone' => '010-9999-8888',
            'orderer_email' => 'hong@example.com',
        ]);

        $this->assertTrue($result->isSuccess(), $result->getMessage());
        $this->assertSame('ENC:이영희', $this->savedData['orderer_name']);
        $this->assertSame('IDX:이영희', $this->savedData['orderer_name_index']);
        $this->assertSame('ENC:010-9999-8888', $this->savedData['orderer_phone']);
        $this->assertSame('IDX:010-9999-8888', $this->savedData['orderer_phone_index']);
        $this->assertArrayNotHasKey('orderer_email', $this->savedData);
    }

    public function testClearingOptionalFieldStoresNull(): void
    {
        $this->givenOrder();

        $result = $this->service->updateOrderInfo(1, 'ORD1', 'orderer', [
            'orderer_name'  => '홍길동',
            'orderer_phone' => '010-1234-5678',
            'orderer_email' => '',
        ]);

        $this->assertTrue($result->isSuccess(), $result->getMessage());
        $this->assertSame(['orderer_email' => null], $this->savedData);
    }

    public function testWritesInfoLogWithMaskedBeforeAndAfter(): void
    {
        $this->givenOrder();

        $this->service->updateOrderInfo(1, 'ORD1', 'shipping', $this->shippingInput([
            'recipient_name'    => '김철수',
            'recipient_phone'   => '010-5555-6666',
            'shipping_address2' => '302동 1004호',
            'order_memo'        => '공동현관 1234#',
        ]), 7);

        $this->assertNotNull($this->savedLog);
        $this->assertSame('INFO', $this->savedLog['change_type']);
        $this->assertSame('STAFF', $this->savedLog['changed_by']);
        $this->assertSame(7, $this->savedLog['staff_id']);
        $this->assertSame('paid', $this->savedLog['new_status'], 'new_status NOT NULL — 현재 상태를 그대로 담는다');
        $this->assertSame($this->savedLog['prev_status'], $this->savedLog['new_status']);
        $this->assertStringContainsString('배송지 정보 수정', $this->savedLog['reason']);
        $this->assertStringContainsString('수령인', $this->savedLog['reason']);

        $detail = json_decode($this->savedLog['change_detail'], true);
        $byField = array_column($detail, null, 'field');
        $this->assertSame(['recipient_name', 'recipient_phone', 'shipping_address2', 'order_memo'], array_keys($byField));

        $this->assertSame('홍*동', $byField['recipient_name']['before']);
        $this->assertSame('김*수', $byField['recipient_name']['after']);
        $this->assertSame('010-****-6666', $byField['recipient_phone']['after']);
        $this->assertSame('30********', $byField['shipping_address2']['after']);
        $this->assertSame('(입력됨)', $byField['order_memo']['after']);

        // 원문 개인정보가 이력에 남지 않는다
        $raw = $this->savedLog['change_detail'];
        $this->assertStringNotContainsString('김철수', $raw);
        $this->assertStringNotContainsString('5555', $raw);
        $this->assertStringNotContainsString('1234#', $raw);
    }

    public function testNoChangeIsRejectedWithoutSaving(): void
    {
        $this->givenOrder();

        $result = $this->service->updateOrderInfo(1, 'ORD1', 'shipping', $this->shippingInput());

        $this->assertTrue($result->isFailure());
        $this->assertNull($this->savedData);
        $this->assertNull($this->savedLog);
    }

    public function testValidationFailureDoesNotSave(): void
    {
        $this->givenOrder();

        $result = $this->service->updateOrderInfo(1, 'ORD1', 'shipping', $this->shippingInput([
            'shipping_address1' => '',
        ]));
        $this->assertTrue($result->isFailure());

        $result = $this->service->updateOrderInfo(1, 'ORD1', 'orderer', [
            'orderer_name' => '홍길동', 'orderer_phone' => '010-1234-5678', 'orderer_email' => 'not-an-email',
        ]);
        $this->assertTrue($result->isFailure());
        $this->assertNull($this->savedData);
    }

    public function testOrderOfOtherDomainIsNotFound(): void
    {
        $this->givenOrder(['domain_id' => 2]);

        $result = $this->service->updateOrderInfo(1, 'ORD1', 'shipping', $this->shippingInput(['recipient_name' => '김철수']));

        $this->assertTrue($result->isFailure());
        $this->assertNull($this->savedData);
    }

    public function testUnknownSectionIsRejected(): void
    {
        $result = $this->service->updateOrderInfo(1, 'ORD1', 'payment', ['payment_method' => 'CARD']);

        $this->assertTrue($result->isFailure());
    }

    public function testLogFailureRollsBack(): void
    {
        $order = Order::fromArray($this->makeOrderData(['order_no' => 'ORD1', 'domain_id' => 1, 'payment_profile' => 'NORMAL']));
        $repo = $this->createMock(OrderRepository::class);
        $repo->method('find')->willReturn($order);
        $db = $this->createMock(Database::class);
        $db->method('inTransaction')->willReturn(true);
        $db->expects($this->once())->method('rollBack');
        $db->expects($this->never())->method('commit');
        $repo->method('getDb')->willReturn($db);
        $repo->method('insertOrderLog')->willThrowException(new \RuntimeException('db down'));

        $service = new OrderService(
            $repo,
            $this->createMock(CartRepository::class),
            $this->createMock(ProductRepository::class),
            $this->createMock(ProductOptionRepository::class),
            new PriceCalculator(),
            $this->createMock(OrderStateResolver::class)
        );

        $result = $service->updateOrderInfo(1, 'ORD1', 'shipping', $this->shippingInput(['recipient_name' => '김철수']));

        $this->assertTrue($result->isFailure());
    }
}
