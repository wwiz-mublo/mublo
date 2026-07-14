<?php

namespace Tests\Unit\Core\Event;

use PHPUnit\Framework\TestCase;
use Mublo\Core\Event\Rendering\FrameTemplateSourceCollectEvent;

/**
 * 프레임 템플릿 소스 수집 이벤트 테스트 — §3.9 네임스페이스·등록 규칙
 */
class FrameTemplateSourceCollectEventTest extends TestCase
{
    public function testValidNamespacedRegistrationIsAccepted(): void
    {
        $event = new FrameTemplateSourceCollectEvent(1);
        $event->addVariable('shop.cart_count', '장바구니 수', fn(): string => '3');
        $event->addSlot('shop.cart_widget', '미니 장바구니', fn(): string => '<div></div>');

        $this->assertArrayHasKey('shop.cart_count', $event->getVariables());
        $this->assertArrayHasKey('shop.cart_widget', $event->getSlots());
        $this->assertSame([], $event->getRejections());
    }

    public function testUnprefixedNameIsRejected(): void
    {
        $event = new FrameTemplateSourceCollectEvent(1);
        $event->addVariable('cart_count', '장바구니 수', fn(): string => '3');

        $this->assertSame([], $event->getVariables());
        $this->assertCount(1, $event->getRejections());
        $this->assertSame('cart_count', $event->getRejections()[0]['name']);
    }

    public function testInvalidFormatIsRejected(): void
    {
        $event = new FrameTemplateSourceCollectEvent(1);
        $event->addVariable('Shop.CartCount', '대문자', fn(): string => '');
        $event->addVariable('shop.', '키 없음', fn(): string => '');
        $event->addSlot('shop.a.b', '점 2개', fn(): string => '');

        $this->assertSame([], $event->getVariables());
        $this->assertSame([], $event->getSlots());
        $this->assertCount(3, $event->getRejections());
    }

    public function testReservedPrefixIsRejected(): void
    {
        $event = new FrameTemplateSourceCollectEvent(1);
        $event->addVariable('company.name', '상호 선점 시도', fn(): string => '');
        $event->addVariable('core.anything', '코어 사칭', fn(): string => '');
        $event->addVariable('mublo.x', '무블로 사칭', fn(): string => '');

        $this->assertSame([], $event->getVariables());
        $this->assertCount(3, $event->getRejections());
    }

    public function testDuplicateRegistrationKeepsFirst(): void
    {
        $event = new FrameTemplateSourceCollectEvent(1);
        $event->addVariable('shop.cart_count', '첫 등록', fn(): string => 'first');
        $event->addVariable('shop.cart_count', '중복 등록', fn(): string => 'second');

        $vars = $event->getVariables();
        $this->assertSame('첫 등록', $vars['shop.cart_count']['label']);
        $this->assertCount(1, $event->getRejections());
    }

    public function testDuplicateAcrossVariableAndSlotIsRejected(): void
    {
        $event = new FrameTemplateSourceCollectEvent(1);
        $event->addVariable('shop.badge', '변수로 먼저', fn(): string => 'v');
        $event->addSlot('shop.badge', '같은 이름 슬롯', fn(): string => '<b>s</b>');
        $event->addSlot('shop.panel', '슬롯으로 먼저', fn(): string => '<b>p</b>');
        $event->addVariable('shop.panel', '같은 이름 변수', fn(): string => 'p');

        $this->assertArrayHasKey('shop.badge', $event->getVariables());
        $this->assertArrayNotHasKey('shop.badge', $event->getSlots(), '변수·슬롯 교차 중복도 거부된다');
        $this->assertArrayHasKey('shop.panel', $event->getSlots());
        $this->assertArrayNotHasKey('shop.panel', $event->getVariables());
        $this->assertCount(2, $event->getRejections());
    }

    public function testRejectionDoesNotThrow(): void
    {
        $event = new FrameTemplateSourceCollectEvent(1);

        // 잘못된 등록이 예외를 던지면 다른 구독자의 수집까지 깨진다 — 던지지 않아야 한다
        $event->addVariable('잘못된 이름!!', 'x', fn(): string => '');
        $event->addSlot('', 'y', fn(): string => '');

        $this->assertCount(2, $event->getRejections());
    }
}
