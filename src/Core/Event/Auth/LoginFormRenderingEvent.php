<?php

namespace Mublo\Core\Event\Auth;

use Mublo\Core\Context\Context;
use Mublo\Core\Event\AbstractEvent;

/**
 * LoginFormRenderingEvent
 *
 * 로그인 폼 렌더링 시 패키지가 추가 HTML을 주입할 수 있는 확장점.
 * AuthController.loginForm()에서 발행.
 *
 * 사용 예 (ShopProvider):
 * ```php
 * // LoginFormSubscriber
 * public static function getSubscribedEvents(): array
 * {
 *     return [LoginFormRenderingEvent::class => 'onLoginFormRendering'];
 * }
 *
 * public function onLoginFormRendering(LoginFormRenderingEvent $event): void
 * {
 *     $context = $event->getContext();
 *     if ($context->getAttribute('active_package') !== 'shop') return;
 *
 *     $event->addHtml('<a href="/shop/checkout?guest=1">비회원 주문하기</a>', 100);
 * }
 * ```
 */
class LoginFormRenderingEvent extends AbstractEvent
{
    private Context $context;

    /** @var array<int, array{html: string, order: int}> */
    private array $htmlBlocks = [];

    public function __construct(Context $context)
    {
        $this->context = $context;
    }

    public function getContext(): Context
    {
        return $this->context;
    }

    /**
     * HTML 블록 추가
     *
     * @param string $html 주입할 HTML
     * @param int $order 정렬 순서 (낮을수록 먼저 표시)
     */
    public function addHtml(string $html, int $order = 500): void
    {
        $this->htmlBlocks[] = ['html' => $html, 'order' => $order];
    }

    /**
     * order 정렬된 HTML 문자열 배열 반환
     *
     * @return string[]
     */
    public function getHtmlSorted(): array
    {
        $blocks = $this->htmlBlocks;
        usort($blocks, fn($a, $b) => $a['order'] <=> $b['order']);

        return array_column($blocks, 'html');
    }

    public function hasHtml(): bool
    {
        return !empty($this->htmlBlocks);
    }
}
