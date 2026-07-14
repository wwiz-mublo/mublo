<?php
/**
 * NotificationActionHandler — 배송 변수 치환 테스트
 *
 * 배송 상태 알림 템플릿이 송장번호/택배사/추적URL을 쓸 수 있도록,
 * prepareFieldValues가 ShipmentService에서 배송 정보를 채워 게이트웨이로 넘기는지 검증한다.
 */

namespace Tests\Shop\Unit\Action;

use Tests\Shop\TestCase;
use Mublo\Packages\Shop\Action\NotificationActionHandler;
use Mublo\Packages\Shop\Event\OrderStatusChangedEvent;
use Mublo\Packages\Shop\Service\ShipmentService;
use Mublo\Core\Registry\ContractRegistry;
use Mublo\Contract\Notification\NotificationGatewayInterface;
use Mublo\Contract\Notification\NotificationSendResult;

class NotificationActionHandlerTest extends TestCase
{
    /** 발송 시 전달된 fieldValues를 포착하는 가짜 게이트웨이 */
    private function captureGateway(object $sink): NotificationGatewayInterface
    {
        return new class($sink) implements NotificationGatewayInterface {
            public function __construct(private object $sink) {}
            public function send(string $channel, string $templateCode, string $recipient, array $fieldValues): NotificationSendResult
            {
                $this->sink->fields = $fieldValues;
                return new NotificationSendResult(true);
            }
            public function getSupportedChannels(): array { return ['sms' => 'SMS']; }
            public function getChannelTree(int $domainId): array { return []; }
        };
    }

    private function registry(NotificationGatewayInterface $gateway): ContractRegistry
    {
        $registry = $this->createMock(ContractRegistry::class);
        $registry->method('allMeta')->willReturn(['fake' => ['channels' => ['sms'], 'label' => 'SMS']]);
        $registry->method('get')->willReturn($gateway);
        return $registry;
    }

    private function event(): OrderStatusChangedEvent
    {
        return new OrderStatusChangedEvent('ORD1', 'paid', 'shipping', '결제완료', '배송중', null, null, [
            'order_no'      => 'ORD1',
            'domain_id'     => 1,
            'orderer_phone' => '01011112222',
        ]);
    }

    public function testShipmentVariablesAreSentToTemplate(): void
    {
        $sink = new \stdClass();
        $handler = new NotificationActionHandler(
            $this->registry($this->captureGateway($sink)),
            null,
            $this->shipmentService([
                ['company_name' => 'CJ대한통운', 'invoice_no' => '123456789', 'tracking_url' => 'https://track/123456789'],
            ])
        );

        $handler->execute(['channel' => 'sms', 'template_code' => 'ship_start', 'recipient' => 'orderer'], $this->event());

        $this->assertSame('CJ대한통운', $sink->fields['courier']);
        $this->assertSame('123456789', $sink->fields['invoice_no']);
        $this->assertSame('https://track/123456789', $sink->fields['tracking_url']);
    }

    public function testShipmentVariablesEmptyWhenNoShipment(): void
    {
        $sink = new \stdClass();
        $handler = new NotificationActionHandler(
            $this->registry($this->captureGateway($sink)),
            null,
            $this->shipmentService([]) // 송장 미등록
        );

        $handler->execute(['channel' => 'sms', 'template_code' => 'order_confirmed', 'recipient' => 'orderer'], $this->event());

        $this->assertSame('', $sink->fields['courier']);
        $this->assertSame('', $sink->fields['invoice_no']);
        $this->assertSame('', $sink->fields['tracking_url']);
    }

    public function testNoShipmentServiceDoesNotBreakSend(): void
    {
        $sink = new \stdClass();
        // ShipmentService 미주입(하위호환) — 배송 변수는 빈 문자열, 발송은 정상
        $handler = new NotificationActionHandler($this->registry($this->captureGateway($sink)), null, null);

        $handler->execute(['channel' => 'sms', 'template_code' => 'order_confirmed', 'recipient' => 'orderer'], $this->event());

        $this->assertSame('', $sink->fields['invoice_no']);
        $this->assertSame('01011112222', $sink->fields['orderer_phone']); // 기존 변수는 그대로
    }

    public function testDomainIdIncludedOnlyWhenResolved(): void
    {
        // 주문에 domain_id 있음 — 게이트웨이 라우팅용으로 전달
        $sink = new \stdClass();
        $handler = new NotificationActionHandler($this->registry($this->captureGateway($sink)), null, null);
        $handler->execute(['channel' => 'sms', 'template_code' => 'order_confirmed', 'recipient' => 'orderer'], $this->event());
        $this->assertSame(1, $sink->fields['domain_id']);

        // 주문에 domain_id 없음 — 0 을 실어 게이트웨이의 정상 Context 해석을 덮지 않는다
        $sink2 = new \stdClass();
        $handler2 = new NotificationActionHandler($this->registry($this->captureGateway($sink2)), null, null);
        $event2 = new OrderStatusChangedEvent('ORD2', 'paid', 'shipping', '결제완료', '배송중', null, null, [
            'order_no'      => 'ORD2',
            'orderer_phone' => '01011112222',
        ]);
        $handler2->execute(['channel' => 'sms', 'template_code' => 'order_confirmed', 'recipient' => 'orderer'], $event2);
        $this->assertArrayNotHasKey('domain_id', $sink2->fields);
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function shipmentService(array $rows): ShipmentService
    {
        $svc = $this->createMock(ShipmentService::class);
        $svc->method('getByOrderNo')->willReturn($rows);
        return $svc;
    }
}
