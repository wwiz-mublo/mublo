<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\EventSubscriber;

use Mublo\Core\Event\EventSubscriberInterface;
use Mublo\Core\Event\Rendering\FrameTemplateSourceCollectEvent;
use Mublo\Packages\Shop\Service\CartService;
use Mublo\Contract\Auth\AuthContextInterface;

/**
 * Shop 패키지 프레임 템플릿 변수 등록
 *
 * 도메인 프레임 편집(header/footer 오버라이드) 템플릿에서 쓸 수 있는
 * 변수를 광고한다. 확장 변수 규약(계획문서 §3.9)의 레퍼런스 구현:
 * - 네임스페이스: `shop.` 접두사 강제
 * - 지연 해석: resolver는 템플릿에 {{shop.cart_count}}가 실제 있을 때만,
 *   렌더 시점에 호출된다 — 등록 자체는 비용이 없다
 * - 공개하면 계약: 이 변수명은 운영자 템플릿이 의존하는 공개 표면이다
 */
class FrameTemplateSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private CartService $cartService,
        private AuthContextInterface $authService,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            FrameTemplateSourceCollectEvent::class => 'onCollect',
        ];
    }

    public function onCollect(FrameTemplateSourceCollectEvent $event): void
    {
        $domainId = $event->getDomainId();
        $event->addVariable('shop.cart_count', '장바구니 담긴 수', function () use ($domainId): string {
            $sessionId = $_COOKIE['cart_session_id'] ?? '';
            $memberId = (int) ($this->authService->id() ?? 0);

            if ($sessionId === '' && $memberId <= 0) {
                return '0';
            }

            $summary = $this->cartService->getCartSummary($sessionId, $memberId, $domainId);

            return (string) ($summary->isSuccess() ? $summary->get('totalItems', 0) : 0);
        });
    }
}
