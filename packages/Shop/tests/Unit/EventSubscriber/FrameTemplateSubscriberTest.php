<?php

namespace Tests\Shop\Unit\EventSubscriber;

use PHPUnit\Framework\TestCase;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Core\Event\Rendering\FrameTemplateSourceCollectEvent;
use Mublo\Core\Rendering\FrameTemplateRenderer;
use Mublo\Core\Result\Result;
use Mublo\Packages\Shop\EventSubscriber\FrameTemplateSubscriber;
use Mublo\Packages\Shop\Service\CartService;
use Mublo\Service\Auth\AuthService;

/**
 * Shop 프레임 템플릿 변수 레퍼런스 테스트
 *
 * 확장 변수 규약(§3.9)의 전 경로 검증: 구독자 등록 → 이벤트 수집 →
 * 렌더러 병합 → {{shop.cart_count}} 치환.
 */
class FrameTemplateSubscriberTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_COOKIE['cart_session_id']);
        parent::tearDown();
    }

    private function renderWithSubscriber(FrameTemplateSubscriber $subscriber, string $template): string
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber($subscriber);

        /** @var FrameTemplateSourceCollectEvent $event */
        $event = $dispatcher->dispatch(new FrameTemplateSourceCollectEvent(1));

        $renderer = new FrameTemplateRenderer();
        $renderer->applyCollected($event);

        return $renderer->render($template);
    }

    public function testCartCountVariableResolvesThroughFullPath(): void
    {
        $_COOKIE['cart_session_id'] = 'sess-abc';

        $cart = $this->createMock(CartService::class);
        $cart->method('getCartSummary')
            ->with('sess-abc', 0)
            ->willReturn(Result::success('ok', ['totalItems' => 5]));

        $auth = $this->createMock(AuthService::class);
        $auth->method('id')->willReturn(null);

        $out = $this->renderWithSubscriber(
            new FrameTemplateSubscriber($cart, $auth),
            '장바구니 <b>{{shop.cart_count}}</b>'
        );

        $this->assertSame('장바구니 <b>5</b>', $out);
    }

    public function testNoSessionAndGuestReturnsZeroWithoutCartQuery(): void
    {
        $cart = $this->createMock(CartService::class);
        $cart->expects($this->never())->method('getCartSummary');

        $auth = $this->createMock(AuthService::class);
        $auth->method('id')->willReturn(null);

        $out = $this->renderWithSubscriber(
            new FrameTemplateSubscriber($cart, $auth),
            '{{shop.cart_count}}'
        );

        $this->assertSame('0', $out);
    }

    public function testResolverIsLazyWhenTokenAbsent(): void
    {
        $cart = $this->createMock(CartService::class);
        $cart->expects($this->never())->method('getCartSummary');

        $auth = $this->createMock(AuthService::class);
        $auth->expects($this->never())->method('id');

        $_COOKIE['cart_session_id'] = 'sess-abc';

        $out = $this->renderWithSubscriber(
            new FrameTemplateSubscriber($cart, $auth),
            '토큰 없는 템플릿'
        );

        $this->assertSame('토큰 없는 템플릿', $out);
    }
}
