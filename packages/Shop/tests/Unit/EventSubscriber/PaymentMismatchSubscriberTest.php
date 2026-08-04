<?php
/**
 * PaymentMismatchSubscriber 단위 테스트
 *
 * 결제-상태 불일치 이벤트 발생 시 관리자 주문 상세에 노출되는 INTERNAL 메모를 남기는지 검증.
 */

namespace Tests\Shop\Unit\EventSubscriber;

use Tests\Shop\TestCase;
use Mublo\Packages\Shop\EventSubscriber\PaymentMismatchSubscriber;
use Mublo\Packages\Shop\Event\PaymentMismatchEvent;
use Mublo\Packages\Shop\Service\OrderMemoService;
use Mublo\Core\Result\Result;

class PaymentMismatchSubscriberTest extends TestCase
{
    public function testAddsInternalMemoOnMismatch(): void
    {
        $memoService = $this->createMock(OrderMemoService::class);
        $memoService->expects($this->once())
            ->method('addMemo')
            ->with(
                $this->equalTo('ORD123'),
                $this->stringContains('결제-상태 불일치'),
                $this->equalTo('INTERNAL'),
                $this->equalTo(0)
            )
            ->willReturn(Result::success('ok'));

        $subscriber = new PaymentMismatchSubscriber($memoService, null);
        $subscriber->onPaymentMismatch(
            new PaymentMismatchEvent('ORD123', 'tosspay', 'TXN-9', '상태 전이 실패')
        );
    }

    public function testMemoFailureDoesNotThrow(): void
    {
        $memoService = $this->createMock(OrderMemoService::class);
        $memoService->method('addMemo')->willThrowException(new \RuntimeException('db down'));

        $subscriber = new PaymentMismatchSubscriber($memoService, null);

        // 메모 기록 실패가 결제 검증 흐름을 깨지 않아야 한다(예외 삼킴)
        $subscriber->onPaymentMismatch(
            new PaymentMismatchEvent('ORD123', 'tosspay', 'TXN-9', '상태 전이 실패')
        );

        $this->assertTrue(true);
    }

    public function testSubscribesToPaymentMismatchEvent(): void
    {
        $this->assertArrayHasKey(
            PaymentMismatchEvent::class,
            PaymentMismatchSubscriber::getSubscribedEvents()
        );
    }
}
